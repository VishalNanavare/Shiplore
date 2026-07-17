<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * VendorOrderRepository — a vendor's own sub-orders (the part of each customer
 * order fulfilled by this vendor). Scoped by vendor_id; findSubOrder enforces
 * isolation so a vendor can't open another vendor's sub-order by id.
 */
final class VendorOrderRepository
{
    /**
     * @param string|null $own           ownership filter: 'mine'|'unclaimed'|'escalated'|'urgent'
     * @param int|null    $currentUserId  the acting user (required for $own = 'mine')
     * @return list<array<string,mixed>>
     */
    public function list(int $vendorId, ?string $status = null, ?int $shopId = null, int $limit = 0, int $offset = 0, ?string $q = null, ?string $dateFrom = null, ?string $dateTo = null, ?string $own = null, ?int $currentUserId = null): array
    {
        // FIFO for confirmed queue (oldest first); newest-first for all other statuses
        $sortDir = ($status === 'confirmed') ? 'ASC' : 'DESC';

        $builder = $this->listQuery($vendorId, $status, $shopId, $q, $dateFrom, $dateTo, $own, $currentUserId)
            ->select('so.id, so.sub_order_no, so.grand_total, so.commission_amount, so.status, so.created_at, so.claimed_by_role, so.claim_expires_at, so.escalation_level, so.priority_level, o.order_no, s.name AS shop, u.name AS customer, uh.name AS handler_name, (SELECT COUNT(*) FROM order_items WHERE sub_order_id = so.id) AS item_count, TIMESTAMPDIFF(MINUTE, so.created_at, NOW()) AS waiting_min, TIMESTAMPDIFF(SECOND, NOW(), so.claim_expires_at) AS claim_expires_in', false)
            ->join('users uh', 'uh.id = so.claimed_by_user_id', 'left')
            ->orderBy('so.priority_level', 'DESC')
            ->orderBy('so.created_at', $sortDir);
        if ($limit > 0) {
            $builder->limit($limit, max(0, $offset));
        }

        return $builder->get()->getResultArray();
    }

    /** Total sub-orders matching the same filters (for pagination). */
    public function count(int $vendorId, ?string $status = null, ?int $shopId = null, ?string $q = null, ?string $dateFrom = null, ?string $dateTo = null, ?string $own = null, ?int $currentUserId = null): int
    {
        return $this->listQuery($vendorId, $status, $shopId, $q, $dateFrom, $dateTo, $own, $currentUserId)->countAllResults();
    }

    /** Shared filtered builder for list()/count(). */
    private function listQuery(int $vendorId, ?string $status, ?int $shopId, ?string $q = null, ?string $dateFrom = null, ?string $dateTo = null, ?string $own = null, ?int $currentUserId = null): object
    {
        $b = Database::connect()->table('sub_orders so')
            ->join('orders o', 'o.id = so.order_id', 'left')
            ->join('shops s', 's.id = so.shop_id', 'left')
            ->join('customers c', 'c.id = o.customer_id', 'left')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->where('so.vendor_id', $vendorId)->where('so.deleted_at', null);
        if ($status !== null && $status !== '') {
            $b->where('so.status', $status);
        }
        if ($shopId !== null) {
            $b->where('so.shop_id', $shopId);
        }
        // Ownership lens (G8): scope the queue to the handler's perspective.
        if ($own === 'mine' && $currentUserId !== null) {
            $b->where('so.claimed_by_user_id', $currentUserId)
              ->where('so.claim_expires_at > NOW()', null, false);
        } elseif ($own === 'unclaimed') {
            $b->where('(so.claimed_by_role IS NULL OR so.claim_expires_at < NOW())', null, false);
        } elseif ($own === 'escalated') {
            $b->where('so.escalation_level <>', 'shop');
        } elseif ($own === 'urgent') {
            $b->where('(so.priority_level = 3 OR TIMESTAMPDIFF(MINUTE, so.created_at, NOW()) > 10)', null, false);
        }
        if ($q !== null && $q !== '') {
            $b->groupStart()
                ->like('so.sub_order_no', $q, 'both')
                ->orLike('o.order_no', $q, 'both')
                ->orLike('u.name', $q, 'both')
              ->groupEnd();
        }
        if ($dateFrom !== null) {
            $b->where('DATE(so.created_at) >=', $dateFrom);
        }
        if ($dateTo !== null) {
            $b->where('DATE(so.created_at) <=', $dateTo);
        }

        return $b;
    }

    /** Live delivery + assigned/offered rider for a sub-order (vendor order detail). */
    public function delivery(int $subOrderId): ?array
    {
        return Database::connect()->table('deliveries d')
            ->select('d.id, d.status AS delivery_status, d.eta_at, d.delivered_at, d.pod_type, d.delivery_fee, da.status AS assignment_status, da.expires_at, da.rider_user_id, u.name AS rider_name, u.phone AS rider_phone, db.current_lat AS rider_lat, db.current_lng AS rider_lng')
            ->join('delivery_assignments da', "da.delivery_id = d.id AND da.status IN ('offered','accepted')", 'left')
            ->join('users u', 'u.id = da.rider_user_id', 'left')
            ->join('delivery_boys db', 'db.user_id = da.rider_user_id', 'left')
            ->where('d.sub_order_id', $subOrderId)->where('d.deleted_at', null)
            ->orderBy('d.id', 'DESC')->get()->getRowArray() ?: null;
    }

    /** Return requests filed against a sub-order. @return list<array<string,mixed>> */
    public function returns(int $subOrderId): array
    {
        return Database::connect()->table('returns')
            ->select('id, uuid, status, customer_note, created_at')
            ->where('sub_order_id', $subOrderId)->where('deleted_at', null)
            ->orderBy('id', 'DESC')->get()->getResultArray();
    }

    /** Delivery address of the parent order. @return array<string,mixed>|null */
    public function deliveryAddress(int $orderId): ?array
    {
        return Database::connect()->table('order_addresses')
            ->select('name, phone, line1, line2, city, state_code, pincode, formatted_address, latitude, longitude')
            ->where('order_id', $orderId)->where('type', 'shipping')->get()->getRowArray() ?: null;
    }

    /** @return array<string,mixed>|null Scoped: null if not this vendor's sub-order. */
    public function findSubOrder(int $id, int $vendorId): ?array
    {
        $row = Database::connect()->table('sub_orders so')
            ->select('so.id, so.order_id, so.vendor_id, so.shop_id, so.sub_order_no, so.subtotal, so.discount_total, so.taxable_value, so.cgst, so.sgst, so.igst, so.cess, so.delivery_total, so.round_off, so.grand_total, so.commission_amount, so.accept_deadline_at, so.delivered_at, so.place_of_supply, so.status, so.delivery_otp, so.otp_attempts, so.otp_verified_at, so.otp_expires_at, so.otp_last_attempt_at, so.claimed_by_role, so.claim_expires_at, so.escalation_level, so.priority_level, so.created_at, o.order_no, s.name AS shop, u.name AS customer, u.phone AS customer_phone')
            ->join('orders o', 'o.id = so.order_id', 'left')
            ->join('shops s', 's.id = so.shop_id', 'left')
            ->join('customers c', 'c.id = o.customer_id', 'left')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->where('so.id', $id)->where('so.vendor_id', $vendorId)->where('so.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function items(int $subOrderId): array
    {
        return Database::connect()->table('order_items oi')
            ->select('oi.id, oi.product_title_snapshot, oi.sku_snapshot, oi.qty, oi.unit_price, oi.discount_amount, oi.taxable_value, oi.tax_rate, oi.status, pv.image_media_id')
            ->join('product_variants pv', 'pv.id = oi.variant_id', 'left')
            ->where('oi.sub_order_id', $subOrderId)
            ->orderBy('oi.id', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Vendor-scoped sub-order status transition (Vendor app + web panel).
     *
     * Returns ['ok'=>true] on success or ['ok'=>false,'reason'=>string,'owner'|'escalation_level'=>…]
     * on claim conflict / escalation block so the caller can surface the right HTTP response.
     *
     * @param array<string,mixed> $claim  ip + ua for audit logging
     * @return array{ok:bool,reason?:string,owner?:array<string,mixed>,escalation_level?:string}
     */
    public function updateSubOrderStatus(int $id, int $vendorId, string $status, string $actorRole = 'vendor', int $actorUserId = 0, string $ip = '', string $ua = ''): array
    {
        $claimSvc = service('orderClaimService');

        // Acquire (or refresh) claim before touching the row
        if ($actorUserId > 0) {
            $claimResult = $claimSvc->claim($id, $actorRole, $actorUserId, $ip, $ua);
            if (! $claimResult['ok']) {
                return $claimResult;
            }
        }

        // Pool rider ownership: once a pool rider has accepted the trip, the rider app
        // owns the out_for_delivery / delivered transitions (with POD). The vendor must
        // not push those from the panel and steal the trip. Self-delivery is exempt.
        if (in_array($status, ['out_for_delivery', 'delivered'], true)) {
            $riderHeld = Database::connect()->table('deliveries d')
                ->join('delivery_assignments da', "da.delivery_id = d.id AND da.status = 'accepted'", 'inner')
                ->where('d.sub_order_id', $id)->where('d.deleted_at', null)->where('d.mode', 'pool')
                ->countAllResults();
            if ($riderHeld > 0) {
                return ['ok' => false, 'reason' => 'rider_controls'];
            }
        }

        // Proof-of-handoff: a sub-order carrying a delivery OTP cannot be marked
        // delivered until that OTP has been verified by the vendor at the doorstep.
        if ($status === 'delivered') {
            $otpRow = Database::connect()->table('sub_orders')
                ->select('delivery_otp, otp_verified_at')
                ->where('id', $id)->where('vendor_id', $vendorId)
                ->get()->getRowArray();
            if ($otpRow !== null && ! empty($otpRow['delivery_otp']) && empty($otpRow['otp_verified_at'])) {
                return ['ok' => false, 'reason' => 'otp_unverified'];
            }
        }

        $patch = ['status' => $status];
        if ($status === 'delivered') {
            $patch['delivered_at'] = date('Y-m-d H:i:s');
        }
        if ($status === 'out_for_delivery') {
            // OTP expires 24 hours after dispatch
            $patch['otp_expires_at'] = date('Y-m-d H:i:s', strtotime('+24 hours'));
        }
        if ($status === 'accepted') {
            // The order is now actively handled — clear any escalation incident so the
            // downward-claim block doesn't outlive it (the claim system locks per-action).
            $patch['escalation_level']          = 'shop';
            $patch['escalation_notified_at']    = null;
            $patch['escalation_reminder_count'] = 0;
        }

        $db = Database::connect();
        $db->transStart();

        $ok = $db->table('sub_orders')
            ->where('id', $id)->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->update($patch);

        if (! $ok) {
            $db->transRollback();

            return ['ok' => false, 'reason' => 'update_failed'];
        }

        // Phase 2: auto-create delivery on 'accepted' (idempotent, best-effort)
        if ($status === 'accepted') {
            try {
                service('deliveryService')->createForSubOrder($id, null);
            } catch (\Throwable) {
            }
        }

        // Sync delivery record status to match sub-order transitions. Guarded so it
        // only advances a delivery forward — never regress a delivery that a rider has
        // already taken past this point (delivered / failed / returned).
        $deliveryStatusMap = [
            'out_for_delivery' => 'out_for_delivery',
            'delivered'        => 'delivered',
            'cancelled'        => 'returned',
            'returned'         => 'returned',
        ];
        if (isset($deliveryStatusMap[$status])) {
            // Forward-only for every mapped status: never clobber a delivery a rider
            // has already taken to a terminal/ahead state (delivered/failed/returned).
            // Cancelling an out_for_delivery sub-order must not rewrite a real
            // 'delivered'/'failed' delivery to 'returned'.
            $db->table('deliveries')
                ->where('sub_order_id', $id)
                ->where('deleted_at', null)
                ->whereNotIn('status', ['delivered', 'failed', 'returned'])
                ->update(['status' => $deliveryStatusMap[$status]]);
        }

        $db->transComplete();

        if (! $db->transStatus()) {
            return ['ok' => false, 'reason' => 'transaction_failed'];
        }

        // Log the status transition in the claim audit trail
        if ($actorUserId > 0) {
            // Release once the vendor is done acting on it — terminal states OR dispatch
            // (out_for_delivery hands ownership to the rider, so the vendor lock lifts).
            $event = in_array($status, ['delivered', 'cancelled', 'returned', 'completed', 'out_for_delivery'], true) ? 'released' : 'refreshed';
            $claimSvc->log($id, $event, $actorRole, $actorUserId, null, null, "status → {$status}", $ip, $ua);

            if ($event === 'released') {
                $claimSvc->release($id, $actorRole, $actorUserId, $ip, $ua);
            }
        }

        // Roll the parent order status up so the customer-facing order reflects fulfilment
        // (mirrors RiderRepository::syncOrderFromDelivery — vendor changes must not diverge).
        if (in_array($status, ['delivered', 'cancelled'], true)) {
            $this->rollUpOrder($id);
        }

        // Notify the customer at each visible milestone. The OTP is never put in the
        // push body — out_for_delivery tells them to open the app for their OTP.
        $this->notifyCustomerOfStatus($id, $status);

        return ['ok' => true];
    }

    /** Push an in-app + push notification to the order's customer on visible transitions. */
    private function notifyCustomerOfStatus(int $subOrderId, string $status): void
    {
        if (! in_array($status, ['packed', 'ready', 'out_for_delivery', 'delivered', 'cancelled'], true)) {
            return;
        }
        try {
            $row = Database::connect()->table('sub_orders so')
                ->select('o.order_no, u.id AS user_id')
                ->join('orders o', 'o.id = so.order_id', 'left')
                ->join('customers c', 'c.id = o.customer_id', 'left')
                ->join('users u', 'u.id = c.user_id', 'left')
                ->where('so.id', $subOrderId)->get()->getRowArray();
            if ($row === null || empty($row['user_id'])) {
                return;
            }
            $userId  = (int) $row['user_id'];
            $orderNo = (string) ($row['order_no'] ?? '');
            $svc     = service('notificationService');
            if ($status === 'out_for_delivery') {
                $svc->notify($userId, 'order.out_for_delivery', ['order_no' => $orderNo]);
            } else {
                $svc->notify($userId, 'order_update', ['order_no' => $orderNo, 'status' => str_replace('_', ' ', $status)]);
            }
        } catch (\Throwable) {
        }
    }

    /** Recompute the parent order status from its sub-orders (completed / partial / cancelled). */
    private function rollUpOrder(int $subOrderId): void
    {
        try {
            $db  = Database::connect();
            $oid = (int) ($db->table('sub_orders')->select('order_id')->where('id', $subOrderId)->get()->getRowArray()['order_id'] ?? 0);
            if ($oid <= 0) {
                return;
            }
            $subs = $db->table('sub_orders')->select('status')->where('order_id', $oid)
                ->whereNotIn('status', ['cancelled', 'returned'])->get()->getResultArray();
            $total = count($subs);
            if ($total === 0) {
                $db->table('orders')->where('id', $oid)->update(['status' => 'cancelled']);

                return;
            }
            $done = 0;
            foreach ($subs as $s) {
                if (in_array($s['status'], ['delivered', 'completed'], true)) {
                    $done++;
                }
            }
            if ($done === $total) {
                $db->table('orders')->where('id', $oid)->update(['status' => 'completed']);
            } elseif ($done > 0) {
                $db->table('orders')->where('id', $oid)->whereIn('status', ['created', 'confirmed', 'partially_fulfilled'])->update(['status' => 'partially_fulfilled']);
            }
        } catch (\Throwable) {
        }
    }

    public function incrementOtpAttempts(int $id): void
    {
        Database::connect()->table('sub_orders')
            ->where('id', $id)
            ->set('otp_attempts', 'otp_attempts + 1', false)
            ->set('otp_last_attempt_at', date('Y-m-d H:i:s'))
            ->update();
    }

    /** Mark verified only if still unverified; returns true if THIS call did it. */
    public function markOtpVerified(int $id): bool
    {
        $db = Database::connect();
        // CI4 update() returns true on any successful query (even 0 rows matched), so
        // we must read affectedRows() to know whether THIS call flipped the flag.
        $db->table('sub_orders')
            ->where('id', $id)
            ->where('otp_verified_at IS NULL', null, false)
            ->update(['otp_verified_at' => date('Y-m-d H:i:s')]);

        return $db->affectedRows() > 0;
    }

    /** Current OTP attempt count (fresh read). */
    public function otpAttempts(int $id): int
    {
        return (int) (Database::connect()->table('sub_orders')
            ->select('otp_attempts')->where('id', $id)
            ->get()->getRowArray()['otp_attempts'] ?? 0);
    }

    public function upsertItemAssignments(int $subOrderId, int $orderId, array $assignments, int $assignedBy): void
    {
        $db = Database::connect();
        foreach ($assignments as $a) {
            $db->table('order_item_assignments')->replace([
                'order_id'      => $orderId,
                'order_item_id' => (int) $a['order_item_id'],
                'mode'          => $a['mode'] ?? 'pool',
                'rider_user_id' => isset($a['rider_user_id']) ? (int) $a['rider_user_id'] : null,
                'assigned_by'   => $assignedBy,
                'assigned_at'   => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
