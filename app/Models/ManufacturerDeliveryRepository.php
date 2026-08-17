<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * ManufacturerDeliveryRepository — getting a dispatched purchase order to its buyer,
 * and the manufacturer's own riders.
 *
 * Deliveries live in `mfg_deliveries` (77_manufacturer_delivery.sql), not the
 * consumer `deliveries` table: that one's sub_order_id is NOT NULL with an FK to
 * `sub_orders`, and a manufacturer's orders are mfg_purchase_orders rows.
 *
 * Riders, by contrast, need no parallel anything. `delivery_boys.vendor_id` points at
 * `vendors` (10_staff.sql:80) and a manufacturer IS a vendors row, so a manufacturer's
 * riders are ordinary delivery_boys rows and the rider mobile app works for them
 * unchanged. This is the one place in the manufacturer build where the vendor
 * structure was reusable as-is.
 *
 * @see \App\Models\VendorStaffRepository::addRider() — the same rider creation
 */
final class ManufacturerDeliveryRepository
{
    private const VEHICLES = ['bike', 'scooter', 'bicycle', 'car', 'van', 'truck', 'foot'];

    /**
     * Deliveries for the units this user may see, newest first.
     *
     * @param list<int> $mshopIds
     * @return list<array<string,mixed>>
     */
    public function list(int $manufacturerId, array $mshopIds, ?string $status = null, int $limit = 200): array
    {
        if ($mshopIds === []) {
            return [];
        }

        $b = Database::connect()->table('mfg_deliveries d')
            ->select('d.id, d.po_id, d.mshop_id, d.rider_user_id, d.mode, d.status, d.eta_at,
                      d.assigned_at, d.delivered_at, d.failure_reason,
                      po.po_no, po.grand_total, po.buyer_vendor_id,
                      m.name AS unit_name, ru.name AS rider_name, ru.phone AS rider_phone,
                      bv.display_name AS buyer_name')
            ->join('mfg_purchase_orders po', 'po.id = d.po_id', 'inner')
            ->join('mshops m', 'm.id = d.mshop_id', 'left')
            ->join('users ru', 'ru.id = d.rider_user_id', 'left')
            ->join('vendors bv', 'bv.id = po.buyer_vendor_id', 'left')
            // The tenant predicate: the PO's seller must be this manufacturer. mshop ids
            // alone would be enough today, but this makes the scoping explicit rather
            // than implied by the caller having passed the right list.
            ->where('po.seller_vendor_id', $manufacturerId)
            ->whereIn('d.mshop_id', $mshopIds)
            ->where('d.deleted_at', null);

        if ($status !== null && $status !== '') {
            $b->where('d.status', $status);
        }

        return $b->orderBy('d.id', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /** @return array<string,mixed>|null one delivery, tenant-scoped */
    public function find(int $deliveryId, int $manufacturerId): ?array
    {
        $row = Database::connect()->table('mfg_deliveries d')
            ->select('d.*, po.po_no, po.seller_vendor_id, po.status AS po_status')
            ->join('mfg_purchase_orders po', 'po.id = d.po_id', 'inner')
            ->where('d.id', $deliveryId)
            ->where('po.seller_vendor_id', $manufacturerId)
            ->where('d.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /**
     * Create the delivery record for a dispatched PO, if it has none.
     *
     * mfg_deliveries has a UNIQUE key on po_id, so a re-dispatch cannot produce a
     * second record; this returns the existing id instead of erroring.
     */
    public function ensureForPo(int $poId, int $mshopId, ?int $actorId = null): ?int
    {
        $db       = Database::connect();
        $existing = $db->table('mfg_deliveries')->select('id')->where('po_id', $poId)->get()->getRowArray();
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        try {
            $db->table('mfg_deliveries')->insert([
                'uuid'       => $this->uuid(),
                'po_id'      => $poId,
                'mshop_id'   => $mshopId,
                'mode'       => 'self',
                'status'     => 'pending',
                'created_by' => $actorId,
            ]);

            return (int) $db->insertID();
        } catch (Throwable $e) {
            log_message('error', 'could not create mfg delivery for PO ' . $poId . ': ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Assign one of the manufacturer's own riders.
     *
     * The rider is re-checked against delivery_boys for THIS manufacturer, so a posted
     * rider id belonging to another business — or to a vendor's fleet — is refused
     * rather than written.
     *
     * @return array{ok:bool,error:string}
     */
    public function assignRider(int $deliveryId, int $manufacturerId, int $riderUserId, ?int $actorId = null): array
    {
        $delivery = $this->find($deliveryId, $manufacturerId);
        if ($delivery === null) {
            return ['ok' => false, 'error' => 'Delivery not found.'];
        }
        if (in_array((string) $delivery['status'], ['delivered', 'returned'], true)) {
            return ['ok' => false, 'error' => 'This delivery is already closed.'];
        }
        if (! $this->ownsRider($manufacturerId, $riderUserId)) {
            return ['ok' => false, 'error' => 'Rider not found.'];
        }

        Database::connect()->table('mfg_deliveries')->where('id', $deliveryId)->update([
            'rider_user_id' => $riderUserId,
            'status'        => 'assigned',
            'assigned_at'   => date('Y-m-d H:i:s'),
            'updated_by'    => $actorId,
        ]);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Move a delivery along. Only forward moves are allowed, and only from a state
     * that can reach the target — the same shape as the PO state machine, kept local
     * because this flow has no StatusMachine map of its own.
     *
     * @return array{ok:bool,error:string}
     */
    public function transition(int $deliveryId, int $manufacturerId, string $to, ?string $reason, ?int $actorId = null): array
    {
        $allowed = [
            'picked_up'  => ['assigned'],
            'in_transit' => ['assigned', 'picked_up'],
            'delivered'  => ['picked_up', 'in_transit'],
            'failed'     => ['assigned', 'picked_up', 'in_transit'],
            'returned'   => ['failed'],
        ];

        if (! isset($allowed[$to])) {
            return ['ok' => false, 'error' => 'Unknown delivery status.'];
        }

        $delivery = $this->find($deliveryId, $manufacturerId);
        if ($delivery === null) {
            return ['ok' => false, 'error' => 'Delivery not found.'];
        }

        $from = (string) $delivery['status'];
        if (! in_array($from, $allowed[$to], true)) {
            return ['ok' => false, 'error' => 'Cannot move a ' . $from . ' delivery to ' . $to . '.'];
        }
        if ($to === 'failed' && ($reason === null || trim($reason) === '')) {
            return ['ok' => false, 'error' => 'Give a reason when marking a delivery failed.'];
        }

        $set = ['status' => $to, 'updated_by' => $actorId];
        if ($to === 'picked_up') {
            $set['picked_up_at'] = date('Y-m-d H:i:s');
        } elseif ($to === 'delivered') {
            $set['delivered_at'] = date('Y-m-d H:i:s');
        } elseif ($to === 'failed') {
            $set['failure_reason'] = mb_substr((string) $reason, 0, 191);
        }

        Database::connect()->table('mfg_deliveries')->where('id', $deliveryId)->update($set);

        return ['ok' => true, 'error' => ''];
    }

    // ---- riders ------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function riders(int $manufacturerId): array
    {
        return Database::connect()->table('delivery_boys db')
            ->select('db.id, db.user_id, db.vehicle_type, db.vehicle_no, db.availability, db.status, u.name, u.phone')
            ->join('users u', 'u.id = db.user_id', 'left')
            ->where('db.vendor_id', $manufacturerId)->where('db.deleted_at', null)
            ->orderBy('db.id', 'ASC')
            ->get()->getResultArray();
    }

    public function ownsRider(int $manufacturerId, int $riderUserId): bool
    {
        return (bool) Database::connect()->table('delivery_boys')
            ->where('vendor_id', $manufacturerId)->where('user_id', $riderUserId)
            ->where('status', 'active')->where('deleted_at', null)
            ->countAllResults();
    }

    public function phoneExists(string $phone): bool
    {
        return (bool) Database::connect()->table('users')
            ->where('phone', $phone)->where('deleted_at', null)->countAllResults();
    }

    /**
     * Create a rider (users + delivery_boys) for the manufacturer.
     *
     * principal_type is 'rider', not 'manufacturer': these logins are for the rider
     * app, and LoginController::landingFor() routes on that value.
     *
     * @param array<string,mixed> $d
     * @return int|null new delivery_boys id
     */
    public function addRider(int $manufacturerId, array $d, ?int $actorId = null): ?int
    {
        $db = Database::connect();
        $db->transBegin();

        try {
            $db->table('users')->insert([
                'uuid'           => bin2hex(random_bytes(18)),
                'principal_type' => 'rider',
                'name'           => mb_substr((string) $d['name'], 0, 191),
                'phone'          => $d['phone'],
                'email'          => ($d['email'] ?? '') ?: null,
                'password_hash'  => ! empty($d['password']) ? password_hash((string) $d['password'], PASSWORD_BCRYPT) : null,
                'status'         => 'active',
                'created_by'     => $actorId,
            ]);
            $userId = (int) $db->insertID();

            $db->table('delivery_boys')->insert([
                'uuid'              => bin2hex(random_bytes(18)),
                'user_id'           => $userId,
                'vendor_id'         => $manufacturerId,
                'vehicle_type'      => in_array($d['vehicle_type'] ?? '', self::VEHICLES, true) ? $d['vehicle_type'] : 'bike',
                'vehicle_no'        => ($d['vehicle_no'] ?? '') ?: null,
                'availability'      => 'offline',
                'max_active_orders' => (int) ($d['max_active_orders'] ?? 5) ?: 5,
                'status'            => 'active',
                'created_by'        => $actorId,
            ]);
            $riderId = (int) $db->insertID();

            $db->transComplete();

            return $db->transStatus() ? $riderId : null;
        } catch (Throwable) {
            $db->transRollback();

            return null;
        }
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
