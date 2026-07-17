<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Delivery\ContactMasker;
use App\Libraries\Delivery\SlaTracker;
use Config\Database;

/**
 * VendorDeliveryRepository — branch-scoped delivery operations for a vendor:
 * deliveries across the vendor's shops with SLA + masked customer contact,
 * manual / auto / re-assignment to the vendor's own riders (or self-delivery by
 * shop staff), failure reasons, route/status history, COD and reports. Every
 * query is scoped to the vendor's shops (tenant + branch isolation).
 *
 * @see docs/architecture/36-DELIVERY-OPERATIONS.md
 */
final class VendorDeliveryRepository
{
    private const ACTIVE = ['assigned', 'picked_up', 'out_for_delivery'];

    /** @return list<array<string,mixed>> */
    public function deliveries(int $vendorId, ?string $status = null, ?int $shopId = null, int $limit = 0, int $offset = 0): array
    {
        $b = Database::connect()->table('deliveries d')
            ->select('d.id, d.mode, d.status, d.eta_at, d.delivery_fee, d.pod_type, d.updated_at, so.sub_order_no, so.grand_total, COALESCE(o.order_no, ps.server_invoice_no) AS order_no, o.payment_status, p.method AS pay_method, s.id AS shop_id, s.name AS shop, COALESCE(oa.name, d.recipient_name) AS customer, COALESCE(oa.phone, d.recipient_phone) AS customer_phone, d.recipient_address, u.name AS rider')
            ->join('sub_orders so', 'so.id = d.sub_order_id', 'left')
            ->join('orders o', 'o.id = so.order_id', 'left')
            ->join('order_addresses oa', 'oa.order_id = o.id', 'left')
            ->join('pos_sales ps', 'ps.id = d.pos_sale_id', 'left')
            ->join('shops s', 's.id = d.shop_id', 'left')
            ->join('payments p', 'p.order_id = o.id', 'left')
            ->join('delivery_assignments da', "da.delivery_id = d.id AND da.status = 'accepted'", 'left')
            ->join('users u', 'u.id = da.rider_user_id', 'left')
            ->where('s.vendor_id', $vendorId)->where('d.deleted_at', null);

        if ($status === 'active') {
            $b->whereIn('d.status', self::ACTIVE);
        } elseif ($status !== null && $status !== '') {
            $b->where('d.status', $status);
        }
        if ($shopId !== null) {
            $b->where('s.id', $shopId);
        }
        $b->orderBy('d.eta_at', 'ASC');
        if ($limit > 0) {
            $b->limit($limit, $offset);
        }

        return array_map([$this, 'shape'], $b->get()->getResultArray());
    }

    public function deliveriesCount(int $vendorId, ?string $status = null, ?int $shopId = null): int
    {
        $b = Database::connect()->table('deliveries d')
            ->join('shops s', 's.id = d.shop_id', 'left')
            ->where('s.vendor_id', $vendorId)->where('d.deleted_at', null);
        if ($status === 'active') {
            $b->whereIn('d.status', self::ACTIVE);
        } elseif ($status !== null && $status !== '') {
            $b->where('d.status', $status);
        }
        if ($shopId !== null) {
            $b->where('s.id', $shopId);
        }

        return $b->countAllResults();
    }

    /** @return array<string,mixed>|null */
    public function find(int $deliveryId, int $vendorId): ?array
    {
        $rows = $this->deliveries($vendorId);
        foreach ($rows as $r) {
            if ((int) $r['id'] === $deliveryId) {
                return $r;
            }
        }

        return null;
    }

    public function owns(int $deliveryId, int $vendorId): bool
    {
        return (bool) Database::connect()->table('deliveries d')
            ->join('shops s', 's.id = d.shop_id', 'left')
            ->where('d.id', $deliveryId)->where('s.vendor_id', $vendorId)
            ->countAllResults();
    }

    /** @return list<array<string,mixed>> Vendor's riders with live active load. */
    public function riders(int $vendorId): array
    {
        $rows = Database::connect()->table('delivery_boys db')
            ->select('db.user_id, db.availability, db.max_active_orders AS max, db.current_lat AS lat, db.current_lng AS lng, u.name')
            ->join('users u', 'u.id = db.user_id', 'left')
            ->where('db.vendor_id', $vendorId)->where('db.deleted_at', null)
            ->where('db.status', 'active')
            ->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['active']  = $this->activeCount((int) $r['user_id']);
            $r['user_id'] = (int) $r['user_id'];
            $r['max']     = (int) $r['max'];
            $r['lat']     = $r['lat'] !== null ? (float) $r['lat'] : null;
            $r['lng']     = $r['lng'] !== null ? (float) $r['lng'] : null;
        }

        return $rows;
    }

    public function activeCount(int $riderUserId): int
    {
        return (int) Database::connect()->table('delivery_assignments da')
            ->join('deliveries d', 'd.id = da.delivery_id', 'left')
            ->where('da.rider_user_id', $riderUserId)->where('da.status', 'accepted')
            ->whereIn('d.status', self::ACTIVE)
            ->countAllResults();
    }

    /** Manual (or self-delivery) assignment. */
    /** A rider may only be assigned if they belong to this vendor (closes IDOR). */
    public function riderBelongsToVendor(int $riderUserId, int $vendorId): bool
    {
        return (bool) Database::connect()->table('delivery_boys')
            ->where('user_id', $riderUserId)->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->countAllResults();
    }

    /**
     * Self-delivery is performed by someone who belongs to the vendor: the vendor
     * OWNER (shop delivers itself) or an active vendor-staff user. Validates
     * membership so a raw POST can't inject an arbitrary user (closes IDOR).
     */
    public function staffBelongsToVendor(int $userId, int $vendorId): bool
    {
        $db = Database::connect();
        // The vendor owner counts — "self delivery" by the shop itself.
        $isOwner = (bool) $db->table('vendors')
            ->where('id', $vendorId)->where('owner_user_id', $userId)->where('deleted_at', null)
            ->countAllResults();
        if ($isOwner) {
            return true;
        }

        return (bool) $db->table('vendor_staff')
            ->where('user_id', $userId)->where('vendor_id', $vendorId)
            ->where('status', 'active')->where('deleted_at', null)
            ->countAllResults();
    }

    public function assign(int $deliveryId, int $riderUserId, int $vendorId, ?int $actorId, string $mode = 'pool', ?string $note = null): bool
    {
        if (! $this->owns($deliveryId, $vendorId)) {
            return false;
        }
        // Every assignee must belong to this vendor — a pool/fleet rider (delivery_boys)
        // or, for self-delivery, an active vendor-staff user. Never trust a raw POST id.
        $belongs = $this->riderBelongsToVendor($riderUserId, $vendorId)
            || ($mode === 'self' && $this->staffBelongsToVendor($riderUserId, $vendorId));
        if (! $belongs) {
            return false;
        }
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        $from = $this->currentStatus($deliveryId);

        // supersede any current assignment, then create the new accepted one
        $db->table('delivery_assignments')->where('delivery_id', $deliveryId)->where('status', 'accepted')
            ->update(['status' => 'reassigned']);
        $db->table('delivery_assignments')->insert([
            'delivery_id' => $deliveryId, 'rider_user_id' => $riderUserId,
            'offered_at' => $now, 'accepted_at' => $now, 'status' => 'accepted',
        ]);
        $db->table('deliveries')->where('id', $deliveryId)->update(['status' => 'assigned', 'mode' => $mode]);
        $this->logStatus($deliveryId, $from, 'assigned', $actorId, $note ?? 'Assigned to rider #' . $riderUserId);

        return true;
    }

    /** Auto-assign the nearest available rider; returns the chosen rider user id or null. */
    public function autoAssign(int $deliveryId, int $vendorId, ?int $actorId): ?int
    {
        if (! $this->owns($deliveryId, $vendorId)) {
            return null;
        }
        $shop = Database::connect()->table('deliveries d')->select('s.latitude, s.longitude')
            ->join('shops s', 's.id = d.shop_id', 'left')->where('d.id', $deliveryId)->get()->getRowArray();
        if ($shop === null || $shop['latitude'] === null) {
            return null;
        }
        $best = service('deliveryAssignmentService')->pickBest($this->riders($vendorId), (float) $shop['latitude'], (float) $shop['longitude']);
        if ($best === null) {
            return null;
        }
        $this->assign($deliveryId, (int) $best['user_id'], $vendorId, $actorId, 'pool', 'Auto-assigned (nearest available)');

        return (int) $best['user_id'];
    }

    public function reassign(int $deliveryId, int $riderUserId, int $vendorId, string $reason, ?int $actorId): bool
    {
        return $this->assign($deliveryId, $riderUserId, $vendorId, $actorId, 'pool', 'Reassigned: ' . $reason);
    }

    /**
     * Phase 2 — re-offer a declined delivery to the next-nearest rider who hasn't
     * already declined it, capped at 5 attempts (then left 'pending' for a manual
     * assignment). Attempts are counted from declined assignments (no schema change).
     * @return int|null the newly-assigned rider user id, or null if none/cap hit
     */
    public function reofferNext(int $deliveryId, ?int $actorId = null): ?int
    {
        $db   = Database::connect();
        $info = $db->table('deliveries d')->select('s.vendor_id, s.latitude, s.longitude')
            ->join('shops s', 's.id = d.shop_id', 'left')->where('d.id', $deliveryId)->get()->getRowArray();
        if ($info === null || $info['vendor_id'] === null || $info['latitude'] === null) {
            return null;
        }
        $vendorId = (int) $info['vendor_id'];
        $declined = $db->table('delivery_assignments')->where('delivery_id', $deliveryId)->where('status', 'declined')->countAllResults();
        if ($declined >= 5) {
            return null; // attempt cap reached — leave pending for manual assignment
        }
        $excluded = array_map('intval', array_column(
            $db->table('delivery_assignments')->select('rider_user_id')->where('delivery_id', $deliveryId)->where('status', 'declined')->get()->getResultArray(),
            'rider_user_id'
        ));
        $pool = array_values(array_filter($this->riders($vendorId), static fn ($r) => ! in_array((int) $r['user_id'], $excluded, true)));
        $best = service('deliveryAssignmentService')->pickBest($pool, (float) $info['latitude'], (float) $info['longitude']);
        if ($best === null) {
            return null; // no eligible rider — stays pending
        }
        $this->assign($deliveryId, (int) $best['user_id'], $vendorId, $actorId, 'pool', 'Re-offered after decline (attempt ' . ($declined + 1) . ')');

        return (int) $best['user_id'];
    }

    public function markFailed(int $deliveryId, int $vendorId, string $reason, ?int $actorId): bool
    {
        if (! $this->owns($deliveryId, $vendorId)) {
            return false;
        }
        $from = $this->currentStatus($deliveryId);
        Database::connect()->table('deliveries')->where('id', $deliveryId)->update(['status' => 'failed']);
        $this->logStatus($deliveryId, $from, 'failed', $actorId, 'Failed: ' . $reason);

        return true;
    }

    /** @return list<array<string,mixed>> Route/status history. */
    public function history(int $deliveryId, int $vendorId): array
    {
        if (! $this->owns($deliveryId, $vendorId)) {
            return [];
        }

        return Database::connect()->table('delivery_status_history')
            ->select('from_status, to_status, note, latitude, longitude, created_at')
            ->where('delivery_id', $deliveryId)->orderBy('created_at', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string,mixed> Delivery report rollup for the vendor. */
    public function reports(int $vendorId): array
    {
        $rows = $this->deliveries($vendorId);
        $byStatus = [];
        $codDue = 0.0;
        $breaches = 0;
        foreach ($rows as $r) {
            $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
            $codDue += (float) $r['cod_amount'];
            if ($r['sla_status'] === 'breached') {
                $breaches++;
            }
        }

        return ['total' => count($rows), 'by_status' => $byStatus, 'sla_breaches' => $breaches, 'cod_due' => number_format($codDue, 2, '.', '')];
    }

    private function currentStatus(int $deliveryId): string
    {
        $row = Database::connect()->table('deliveries')->select('status')->where('id', $deliveryId)->get()->getRowArray();

        return $row['status'] ?? 'pending';
    }

    private function logStatus(int $deliveryId, string $from, string $to, ?int $actorId, string $note): void
    {
        Database::connect()->table('delivery_status_history')->insert([
            'delivery_id' => $deliveryId, 'from_status' => $from, 'to_status' => $to,
            'actor_id' => $actorId, 'note' => mb_substr($note, 0, 255), 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string,mixed> $r */
    private function shape(array $r): array
    {
        $cod = ($r['pay_method'] ?? '') === 'cod' && ($r['payment_status'] ?? '') !== 'paid'
            ? (float) $r['grand_total'] : 0.0;

        return [
            'id'                   => (int) $r['id'],
            'order_no'             => $r['order_no'],
            'sub_order_no'         => $r['sub_order_no'],
            'shop'                 => $r['shop'],
            'shop_id'              => (int) $r['shop_id'],
            'mode'                 => $r['mode'],
            'status'               => $r['status'],
            'eta_at'               => $r['eta_at'],
            'sla_status'           => SlaTracker::status($r['eta_at'], $r['status'] === 'delivered' ? $r['updated_at'] : null),
            'rider'                => $r['rider'],
            'customer'             => $r['customer'],
            'customer_phone_masked' => ContactMasker::mask($r['customer_phone']),
            'cod_amount'           => number_format($cod, 2, '.', ''),
            'delivery_fee'         => number_format((float) ($r['delivery_fee'] ?? 0), 2, '.', ''),
        ];
    }
}
