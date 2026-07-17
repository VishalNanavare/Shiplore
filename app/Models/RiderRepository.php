<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Workflow\StatusMachine;
use Config\Database;
use Throwable;

/**
 * RiderRepository — data for the Delivery Boy app, scoped to the logged-in rider
 * (delivery_assignments.rider_user_id). Assigned orders with pickup + drop +
 * COD, status updates, proof-of-delivery, COD collection, earnings, availability
 * and location. Every mutation verifies the rider owns the delivery.
 *
 * @see docs/architecture/28-DELIVERY-APP.md
 */
final class RiderRepository
{
    private const ACTIVE_DELIVERY = ['assigned', 'picked_up', 'out_for_delivery'];

    /**
     * Find a rider account by phone for the web panel login (users.principal_type
     * ='rider' linked to an active delivery_boys row). Tolerates +91/10-digit/91
     * stored shapes. @return array{user_id:int,name:string,status:string}|null
     */
    public function findByPhone(string $e164): ?array
    {
        $nat = substr($e164, -10);
        $row = Database::connect()->table('users u')
            ->select('u.id AS user_id, u.name, db.status')
            ->join('delivery_boys db', 'db.user_id = u.id AND db.deleted_at IS NULL', 'left')
            ->where('u.principal_type', 'rider')->where('u.deleted_at', null)
            ->where('db.id IS NOT NULL', null, false)
            ->groupStart()->where('u.phone', $e164)->orWhere('u.phone', $nat)->orWhere('u.phone', '91' . $nat)->groupEnd()
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null Rider profile. */
    public function profile(int $userId): ?array
    {
        $row = Database::connect()->table('delivery_boys db')
            ->select('db.id, db.availability, db.status, db.vehicle_type, db.vehicle_no, db.current_lat, db.current_lng, db.max_active_orders, u.name, u.phone')
            ->join('users u', 'u.id = db.user_id', 'left')
            ->where('db.user_id', $userId)->where('db.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function setAvailability(int $userId, string $availability): bool
    {
        return Database::connect()->table('delivery_boys')
            ->where('user_id', $userId)->where('deleted_at', null)
            ->update(['availability' => $availability]);
    }

    public function updateLocation(int $userId, float $lat, float $lng): bool
    {
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('delivery_boys')->where('user_id', $userId)
            ->update(['current_lat' => $lat, 'current_lng' => $lng, 'last_location_at' => $now]);
        $db->table('rider_locations')->insert(['rider_user_id' => $userId, 'latitude' => $lat, 'longitude' => $lng, 'recorded_at' => $now]);

        return true;
    }

    /** @return list<array<string,mixed>> Assigned deliveries (pickup + drop + COD). */
    public function assignments(int $userId, ?string $status = null): array
    {
        $b = Database::connect()->table('delivery_assignments da')
            ->select('d.id AS delivery_id, da.status AS assignment_status, d.status AS delivery_status, d.eta_at, d.delivery_fee, d.pod_type, d.pod_ref, o.order_no, so.sub_order_no, so.grand_total, p.method AS pay_method, o.payment_status, s.name AS pickup_shop, s.latitude AS pickup_lat, s.longitude AS pickup_lng, oa.name AS drop_name, oa.phone AS drop_phone, oa.line1 AS drop_line1, oa.city AS drop_city, oa.pincode AS drop_pincode, oa.latitude AS drop_lat, oa.longitude AS drop_lng')
            ->join('deliveries d', 'd.id = da.delivery_id', 'left')
            ->join('sub_orders so', 'so.id = d.sub_order_id', 'left')
            ->join('orders o', 'o.id = so.order_id', 'left')
            ->join('order_addresses oa', 'oa.order_id = o.id', 'left')
            ->join('shops s', 's.id = d.shop_id', 'left')
            ->join('payments p', 'p.order_id = o.id', 'left')
            ->where('da.rider_user_id', $userId)
            ->orderBy('d.eta_at', 'ASC');

        if ($status === 'active') {
            $b->whereIn('d.status', self::ACTIVE_DELIVERY);
        } elseif ($status !== null && $status !== '') {
            $b->where('d.status', $status);
        }

        return array_map([$this, 'shape'], $b->get()->getResultArray());
    }

    /** @return array<string,mixed>|null One delivery (rider-scoped) + items. */
    public function assignment(int $deliveryId, int $userId): ?array
    {
        $rows = $this->assignments($userId);
        $match = null;
        foreach ($rows as $r) {
            if ((int) $r['delivery_id'] === $deliveryId) {
                $match = $r;
                break;
            }
        }
        if ($match === null) {
            return null;
        }

        $sub = Database::connect()->table('deliveries d')
            ->select('d.sub_order_id')->where('d.id', $deliveryId)->get()->getRowArray();
        $match['items'] = $sub ? Database::connect()->table('order_items')
            ->select('product_title_snapshot, sku_snapshot, qty')
            ->where('sub_order_id', $sub['sub_order_id'])->get()->getResultArray() : [];

        return $match;
    }

    public function owns(int $deliveryId, int $userId): bool
    {
        return (bool) Database::connect()->table('delivery_assignments')
            ->where('delivery_id', $deliveryId)->where('rider_user_id', $userId)->countAllResults();
    }

    /** Public ownership check for in-trip actions (the rider holds an ACCEPTED assignment). */
    public function ownsAcceptedDelivery(int $deliveryId, int $userId): bool
    {
        return $this->ownsAccepted($deliveryId, $userId);
    }

    /** Stricter ownership for in-trip actions: the rider must hold an ACCEPTED assignment. */
    private function ownsAccepted(int $deliveryId, int $userId): bool
    {
        return (bool) Database::connect()->table('delivery_assignments')
            ->where('delivery_id', $deliveryId)->where('rider_user_id', $userId)->where('status', 'accepted')->countAllResults();
    }

    /** Accept a LIVE offer (rejects already-taken or lapsed offers). */
    public function accept(int $deliveryId, int $userId): bool
    {
        $db    = Database::connect();
        $now   = date('Y-m-d H:i:s');
        $offer = $db->table('delivery_assignments')
            ->where('delivery_id', $deliveryId)->where('rider_user_id', $userId)->where('status', 'offered')
            ->orderBy('id', 'DESC')->get()->getRowArray();
        if ($offer === null) {
            return false; // no live offer (taken by someone else / already actioned)
        }
        if ($offer['expires_at'] !== null && strtotime((string) $offer['expires_at']) < time()) {
            $db->table('delivery_assignments')->where('id', $offer['id'])->update(['status' => 'reassigned']);

            return false; // lapsed — will be re-offered to the next rider
        }
        // Another rider must not already hold this delivery.
        if ($db->table('delivery_assignments')->where('delivery_id', $deliveryId)->where('status', 'accepted')->countAllResults() > 0) {
            $db->table('delivery_assignments')->where('id', $offer['id'])->update(['status' => 'reassigned']);

            return false;
        }
        // The uq_da_accepted unique index is the atomic backstop: if another rider
        // accepted in the race window, this UPDATE throws and we report failure.
        try {
            $db->table('delivery_assignments')->where('id', $offer['id'])->update(['status' => 'accepted', 'accepted_at' => $now]);
        } catch (Throwable) {
            return false;
        }
        $db->table('deliveries')->where('id', $deliveryId)->update(['status' => 'assigned']);
        $db->table('delivery_status_history')->insert([
            'delivery_id' => $deliveryId, 'from_status' => 'pending', 'to_status' => 'assigned', 'actor_id' => $userId,
            'note' => 'Accepted by rider', 'created_at' => $now,
        ]);

        return true;
    }

    /** Rider declines a trip → back to pending + auto re-offer to the next-nearest rider. */
    public function decline(int $deliveryId, int $userId, ?string $reason = null): bool
    {
        $db = Database::connect();
        // Require a LIVE offered/accepted assignment for this rider — so a stale
        // (already declined/reassigned) row can't revert someone else's active trip.
        if (! $db->table('delivery_assignments')->where('delivery_id', $deliveryId)->where('rider_user_id', $userId)
            ->whereIn('status', ['offered', 'accepted'])->countAllResults()) {
            return false;
        }
        // Can't decline a trip that's already in progress (picked up / out for delivery /
        // done) — only an offered or freshly-assigned delivery may be handed back.
        $from = $db->table('deliveries')->select('status')->where('id', $deliveryId)->get()->getRowArray()['status'] ?? 'assigned';
        if (in_array($from, ['picked_up', 'out_for_delivery', 'delivered', 'failed'], true)) {
            return false;
        }
        $db->table('delivery_assignments')->where('delivery_id', $deliveryId)->where('rider_user_id', $userId)
            ->whereIn('status', ['offered', 'accepted'])
            ->update(['status' => 'declined', 'declined_at' => date('Y-m-d H:i:s')]);
        $db->table('deliveries')->where('id', $deliveryId)->update(['status' => 'pending']);
        $db->table('delivery_status_history')->insert([
            'delivery_id' => $deliveryId, 'from_status' => $from, 'to_status' => 'pending', 'actor_id' => $userId,
            'note' => 'Declined by rider' . ($reason !== null ? ': ' . mb_substr($reason, 0, 160) : ''), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        // Re-offer immediately to the next-nearest online rider (this rider is now
        // excluded as 'declined'); the dispatch engine also retries on its cron tick.
        try {
            service('riderDispatchService')->dispatchPending();
        } catch (\Throwable) {
        }

        return true;
    }

    public function updateStatus(int $deliveryId, int $userId, string $status, ?string $reason = null, ?float $lat = null, ?float $lng = null): bool
    {
        if (! $this->ownsAccepted($deliveryId, $userId)) {
            return false;
        }
        $db   = Database::connect();
        $from = $db->table('deliveries')->select('status')->where('id', $deliveryId)->get()->getRowArray()['status'] ?? 'pending';
        // Reject illegal delivery transitions (e.g. delivered -> picked_up) at the source.
        if ($from !== $status && ! StatusMachine::canDelivery((string) $from, $status)) {
            return false;
        }
        // Proof-of-delivery gate: a rider cannot flip 'delivered' via a plain status
        // update — they must confirm through /pod (OTP / photo / signature). This mirrors
        // the vendor otp_unverified gate so no delivery completes without proof.
        if ($status === 'delivered') {
            $proof = $db->table('deliveries d')
                ->select('d.pod_type, so.delivery_otp, so.otp_verified_at')
                ->join('sub_orders so', 'so.id = d.sub_order_id', 'left')
                ->where('d.id', $deliveryId)->get()->getRowArray();
            $hasProof = ! empty($proof['pod_type']) || ! empty($proof['otp_verified_at']);
            if ($proof !== null && ! empty($proof['delivery_otp']) && ! $hasProof) {
                return false;
            }
        }
        $patch = ['status' => $status];
        if ($status === 'delivered') {
            $patch['delivered_at'] = date('Y-m-d H:i:s'); // Phase 3 — on-time / SLA metric
        }
        $db->table('deliveries')->where('id', $deliveryId)->update($patch);
        // Keep the customer-visible order timeline in sync with the rider's progress.
        $this->syncOrderFromDelivery($deliveryId, $status);
        // Notify the customer of the delivery milestone (in-app + push).
        $this->notifyCustomerOfDelivery($deliveryId, $status);

        // route + failure-reason history (lat/lng captured from the rider device)
        $db->table('delivery_status_history')->insert([
            'delivery_id' => $deliveryId, 'from_status' => $from, 'to_status' => $status, 'actor_id' => $userId,
            'latitude' => $lat, 'longitude' => $lng,
            'note' => $reason !== null ? mb_substr($reason, 0, 191) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Phase 2: dispatching issues a one-time delivery code to the customer; the
        // rider confirms it as OTP proof-of-delivery.
        if ($status === 'out_for_delivery') {
            $this->issueDeliveryOtp($deliveryId);
        }
        if ($status === 'delivered') {
            $this->accruePayout($deliveryId, $userId); // X5 — best-effort
        }

        return true;
    }

    public function recordPod(int $deliveryId, int $userId, string $type, string $ref): bool
    {
        if (! $this->ownsAccepted($deliveryId, $userId)) {
            return false;
        }
        // OTP proof verifies against the CUSTOMER-visible delivery OTP (sub_orders.delivery_otp,
        // the exact code shown in the customer app) — one source of truth, attempt-limited.
        if ($type === 'otp' && ! $this->verifyCustomerOtp($deliveryId, $ref)) {
            return false;
        }

        $ok = Database::connect()->table('deliveries')->where('id', $deliveryId)
            ->update(['pod_type' => $type, 'pod_ref' => mb_substr($ref, 0, 120), 'status' => 'delivered', 'delivered_at' => date('Y-m-d H:i:s')]);

        if ($ok) {
            Database::connect()->table('delivery_status_history')->insert([
                'delivery_id' => $deliveryId, 'from_status' => 'out_for_delivery', 'to_status' => 'delivered', 'actor_id' => $userId,
                'note' => 'Delivered (proof: ' . $type . ')', 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $this->syncOrderFromDelivery($deliveryId, 'delivered');
            $this->notifyCustomerOfDelivery($deliveryId, 'delivered');
            $this->accruePayout($deliveryId, $userId); // X5 — best-effort
        }

        return $ok;
    }

    /**
     * Mirror the rider's delivery progress onto the customer-facing sub-order (and
     * parent order) so the order timeline + per-product status stay correct.
     */
    private function syncOrderFromDelivery(int $deliveryId, string $deliveryStatus): void
    {
        $target = ['out_for_delivery' => 'out_for_delivery', 'delivered' => 'delivered', 'returned' => 'returned'][$deliveryStatus] ?? null;
        if ($target === null) {
            return;
        }
        try {
            $db  = Database::connect();
            $sub = $db->table('deliveries')->select('sub_order_id')->where('id', $deliveryId)->get()->getRowArray();
            if ($sub === null) {
                return;
            }
            $subId = (int) $sub['sub_order_id'];
            $patch = ['status' => $target];
            if ($target === 'delivered') {
                $patch['delivered_at'] = date('Y-m-d H:i:s');
            }
            $db->table('sub_orders')->where('id', $subId)->whereNotIn('status', ['cancelled', 'returned'])->update($patch);
            $db->table('order_items')->where('sub_order_id', $subId)->where('status', 'active')->update(['updated_at' => date('Y-m-d H:i:s')]);

            if ($target === 'delivered') {
                $oid = (int) ($db->table('sub_orders')->select('order_id')->where('id', $subId)->get()->getRowArray()['order_id'] ?? 0);
                if ($oid > 0) {
                    // Cancelled/returned products don't count toward completion.
                    $subs  = $db->table('sub_orders')->select('status')->where('order_id', $oid)->whereNotIn('status', ['cancelled', 'returned'])->get()->getResultArray();
                    $total = count($subs);
                    $done  = 0;
                    foreach ($subs as $s) {
                        if (in_array($s['status'], ['delivered', 'completed'], true)) {
                            $done++;
                        }
                    }
                    if ($total > 0 && $done === $total) {
                        $db->table('orders')->where('id', $oid)->update(['status' => 'completed']);
                    } elseif ($done > 0) {
                        $db->table('orders')->where('id', $oid)->whereIn('status', ['created', 'confirmed', 'partially_fulfilled'])->update(['status' => 'partially_fulfilled']);
                    }
                }
            }
        } catch (Throwable) {
        }
    }

    /** Notify the customer of a rider-driven delivery milestone (in-app + push). */
    private function notifyCustomerOfDelivery(int $deliveryId, string $status): void
    {
        if (! in_array($status, ['picked_up', 'arrived', 'out_for_delivery', 'delivered', 'failed'], true)) {
            return;
        }
        try {
            $row = Database::connect()->table('deliveries d')
                ->select('u.id AS user_id, o.order_no')
                ->join('sub_orders so', 'so.id = d.sub_order_id', 'left')
                ->join('orders o', 'o.id = so.order_id', 'left')
                ->join('customers c', 'c.id = o.customer_id', 'left')
                ->join('users u', 'u.id = c.user_id', 'left')
                ->where('d.id', $deliveryId)->get()->getRowArray();
            if ($row === null || empty($row['user_id'])) {
                return;
            }
            $orderNo = (string) ($row['order_no'] ?? '');
            if ($status === 'out_for_delivery') {
                // Prompt the customer to open the app for their OTP (never in the body).
                service('notificationService')->notify((int) $row['user_id'], 'order.out_for_delivery', ['order_no' => $orderNo]);
            } else {
                service('notificationService')->notify((int) $row['user_id'], 'delivery_update', [
                    'order_no' => $orderNo,
                    'status'   => str_replace('_', ' ', $status),
                ]);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * On dispatch, start the 24h expiry window on the customer's delivery OTP. The
     * customer reads their OTP from the app (sub_orders.delivery_otp) and gives it to
     * the rider — we never issue a second code or put the OTP in a notification/SMS.
     */
    private function issueDeliveryOtp(int $deliveryId): void
    {
        try {
            $db    = Database::connect();
            $subId = (int) ($db->table('deliveries')->select('sub_order_id')->where('id', $deliveryId)->get()->getRowArray()['sub_order_id'] ?? 0);
            if ($subId > 0) {
                $db->table('sub_orders')->where('id', $subId)
                    ->where('otp_expires_at IS NULL', null, false)
                    ->update(['otp_expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours'))]);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Verify a rider-entered OTP against the customer-facing sub_orders.delivery_otp.
     * Attempt-limited (5) and expiry-gated; flips otp_verified_at on success. Returns
     * true if the code matches (or was already verified).
     */
    private function verifyCustomerOtp(int $deliveryId, string $ref): bool
    {
        $db  = Database::connect();
        $row = $db->table('deliveries d')
            ->select('so.id AS sub_order_id, so.delivery_otp, so.otp_attempts, so.otp_verified_at, so.otp_expires_at')
            ->join('sub_orders so', 'so.id = d.sub_order_id', 'left')
            ->where('d.id', $deliveryId)->get()->getRowArray();
        if ($row === null || empty($row['sub_order_id'])) {
            return false;
        }
        if (! empty($row['otp_verified_at'])) {
            return true; // already confirmed — idempotent
        }
        if ((int) $row['otp_attempts'] >= 5) {
            return false; // locked
        }
        if (! empty($row['otp_expires_at']) && strtotime((string) $row['otp_expires_at']) < time()) {
            return false; // expired
        }
        $subId = (int) $row['sub_order_id'];
        if ($ref !== (string) ($row['delivery_otp'] ?? '')) {
            $db->table('sub_orders')->where('id', $subId)
                ->set('otp_attempts', 'otp_attempts + 1', false)
                ->set('otp_last_attempt_at', date('Y-m-d H:i:s'))
                ->update();

            return false;
        }
        $db->table('sub_orders')->where('id', $subId)
            ->where('otp_verified_at IS NULL', null, false)
            ->update(['otp_verified_at' => date('Y-m-d H:i:s')]);

        return true;
    }

    /** X5 — rider payout entry on delivery (idempotent; never blocks the flow). */
    private function accruePayout(int $deliveryId, int $riderUserId): void
    {
        try {
            $row = Database::connect()->table('deliveries d')
                ->select('d.id, so.grand_total, so.vendor_id, s.latitude AS shop_lat, s.longitude AS shop_lng, oa.latitude AS drop_lat, oa.longitude AS drop_lng')
                ->join('sub_orders so', 'so.id = d.sub_order_id', 'left')
                ->join('shops s', 's.id = d.shop_id', 'left')
                ->join('order_addresses oa', "oa.order_id = so.order_id AND oa.type = 'shipping'", 'left')
                ->where('d.id', $deliveryId)->get()->getRowArray();
            if ($row === null) {
                return;
            }
            // Real shop→drop distance (Haversine) so per-km payout models pay correctly.
            $distance = 0.0;
            if ($row['shop_lat'] !== null && $row['drop_lat'] !== null) {
                $distance = \App\Libraries\Store\LocationService::distanceKm(
                    (float) $row['shop_lat'], (float) $row['shop_lng'], (float) $row['drop_lat'], (float) $row['drop_lng']
                );
            }
            service('riderPayoutService')->accrueForDelivery([
                'id'            => $deliveryId,
                'rider_user_id' => $riderUserId,
                'vendor_id'     => $row['vendor_id'] !== null ? (int) $row['vendor_id'] : null,
                'order_value'   => (float) ($row['grand_total'] ?? 0),
                'distance_km'   => $distance,
            ]);
        } catch (\Throwable) {
        }
    }

    // ---- Admin oversight (cross-vendor) ----

    /** @return list<array<string,mixed>> all riders with vendor, plan + active load */
    public function adminList(?int $vendorId = null, ?string $status = null): array
    {
        $b = Database::connect()->table('delivery_boys db')
            ->select('db.id, db.user_id, db.vehicle_type, db.vehicle_no, db.availability, db.status, db.payout_plan_id, u.name, u.phone, v.display_name AS vendor, rpp.name AS plan')
            ->select("(SELECT COUNT(*) FROM delivery_assignments da JOIN deliveries d ON d.id = da.delivery_id WHERE da.rider_user_id = db.user_id AND d.status IN ('assigned','picked_up','out_for_delivery')) AS active_load", false)
            ->join('users u', 'u.id = db.user_id', 'left')
            ->join('vendors v', 'v.id = db.vendor_id', 'left')
            ->join('rider_payout_plans rpp', 'rpp.id = db.payout_plan_id', 'left')
            ->where('db.deleted_at', null)->orderBy('db.id', 'DESC');
        if ($vendorId !== null) {
            $b->where('db.vendor_id', $vendorId);
        }
        if ($status !== null && $status !== '') {
            $b->where('db.status', $status);
        }

        return $b->get()->getResultArray();
    }

    /** @return array<string,mixed>|null full rider record (by delivery_boys.id) */
    public function adminFind(int $deliveryBoyId): ?array
    {
        $row = Database::connect()->table('delivery_boys db')
            ->select('db.*, u.name, u.phone, u.email, v.display_name AS vendor, rpp.name AS plan')
            ->join('users u', 'u.id = db.user_id', 'left')
            ->join('vendors v', 'v.id = db.vendor_id', 'left')
            ->join('rider_payout_plans rpp', 'rpp.id = db.payout_plan_id', 'left')
            ->where('db.id', $deliveryBoyId)->where('db.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function setStatus(int $deliveryBoyId, string $status, ?int $actorId = null): bool
    {
        if (! in_array($status, ['active', 'suspended', 'terminated'], true)) {
            return false;
        }

        return (bool) Database::connect()->table('delivery_boys')->where('id', $deliveryBoyId)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }

    public function assignPlan(int $deliveryBoyId, ?int $planId, ?int $actorId = null): bool
    {
        return (bool) Database::connect()->table('delivery_boys')->where('id', $deliveryBoyId)->where('deleted_at', null)
            ->update(['payout_plan_id' => $planId ?: null, 'updated_by' => $actorId]);
    }

    /** COD collected: capture the order's cash payment + mark order paid. */
    public function recordCod(int $deliveryId, int $userId): bool
    {
        if (! $this->ownsAccepted($deliveryId, $userId)) {
            return false;
        }
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        $row = $db->table('deliveries d')
            ->select('d.status AS delivery_status, so.id AS sub_order_id, so.order_id, so.grand_total AS sub_total, p.id AS payment_id, p.status AS pay_status')
            ->join('sub_orders so', 'so.id = d.sub_order_id', 'left')
            ->join('payments p', "p.order_id = so.order_id AND p.method = 'cod'", 'left')
            ->where('d.id', $deliveryId)->get()->getRowArray();
        if ($row === null) {
            return false;
        }
        // Only a COD order can have cash collected — never record phantom cash or flip a
        // prepaid (non-COD) order to paid. Already-captured COD is a no-op success.
        if ($row['payment_id'] === null) {
            return false;
        }
        // Cash is collected at the doorstep — not before the parcel is even out for delivery.
        if (! in_array($row['delivery_status'], ['out_for_delivery', 'delivered'], true)) {
            return false;
        }
        try {
            // Cash the rider collected = THIS sub-order's total (per-product delivery),
            // not the whole multi-product order. record() is idempotent per delivery.
            $amount = (float) ($row['sub_total'] ?? 0);
            service('codCollectionRepository')->record($deliveryId, $userId, (int) $row['payment_id'], $amount, $userId);

            // Capture the order-level COD payment + mark paid ONLY once every non-cancelled
            // sub-order of the order has had its cash collected (partial COD stays pending).
            if ($this->allCodCollected((int) $row['order_id'])) {
                $db->table('payments')->where('order_id', $row['order_id'])->where('method', 'cod')
                    ->update(['status' => 'captured', 'captured_at' => $now]);
                $db->table('orders')->where('id', $row['order_id'])->update(['payment_status' => 'paid']);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** True once every non-cancelled sub-order of the order has a (non-reversed) COD collection. */
    private function allCodCollected(int $orderId): bool
    {
        $db   = Database::connect();
        $subs = $db->table('sub_orders')->where('order_id', $orderId)
            ->whereNotIn('status', ['cancelled', 'returned'])->countAllResults();
        if ($subs === 0) {
            return false;
        }
        $collected = $db->table('cod_collections cc')
            ->join('deliveries d', 'd.id = cc.delivery_id')
            ->join('sub_orders so', 'so.id = d.sub_order_id')
            ->where('so.order_id', $orderId)->where('cc.status !=', 'reversed')
            ->whereNotIn('so.status', ['cancelled', 'returned'])
            ->countAllResults();

        return $collected >= $subs;
    }

    /** @return array<string,mixed> Earnings (if the feature flag is enabled). */
    public function earnings(int $userId): array
    {
        $enabled = $this->featureEnabled('rider_earnings');
        if (! $enabled) {
            return ['enabled' => false, 'total' => '0.00', 'deliveries' => 0];
        }
        $row = Database::connect()->table('delivery_assignments da')
            ->select('COUNT(*) AS deliveries, COALESCE(SUM(d.delivery_fee),0) AS total')
            ->join('deliveries d', 'd.id = da.delivery_id', 'left')
            ->where('da.rider_user_id', $userId)->where('d.status', 'delivered')
            ->get()->getRowArray();

        return ['enabled' => true, 'total' => number_format((float) ($row['total'] ?? 0), 2, '.', ''), 'deliveries' => (int) ($row['deliveries'] ?? 0)];
    }

    // ---- Live rider app (polling, duty shifts, earnings, performance) ----

    /**
     * Live snapshot for the rider app poll: online state, fresh offers (with the
     * seconds left to accept), active-trip count and today's stats.
     * @return array<string,mixed>
     */
    public function poll(int $userId): array
    {
        $db     = Database::connect();
        $now    = date('Y-m-d H:i:s');
        $prof   = $this->profile($userId);
        $online = ($prof['availability'] ?? 'offline') === 'available';

        $offers = [];
        if ($online) {
            $rows = $db->table('delivery_assignments da')
                ->select('d.id AS delivery_id, da.expires_at, o.order_no, s.name AS pickup, oa.line1 AS drop_line1, oa.city AS drop_city, d.delivery_fee, so.grand_total, p.method AS pay_method, o.payment_status')
                ->join('deliveries d', 'd.id = da.delivery_id', 'left')
                ->join('sub_orders so', 'so.id = d.sub_order_id', 'left')
                ->join('orders o', 'o.id = so.order_id', 'left')
                ->join('order_addresses oa', 'oa.order_id = o.id', 'left')
                ->join('shops s', 's.id = d.shop_id', 'left')
                ->join('payments p', "p.order_id = o.id AND p.method = 'cod'", 'left')
                ->where('da.rider_user_id', $userId)->where('da.status', 'offered')->where('da.expires_at >', $now)
                ->where('d.status', 'pending')
                ->orderBy('da.expires_at', 'ASC')->get()->getResultArray();
            foreach ($rows as $r) {
                $cod = ($r['pay_method'] ?? '') === 'cod' && ($r['payment_status'] ?? '') !== 'paid';
                $offers[] = [
                    'delivery_id' => (int) $r['delivery_id'],
                    'order_no'    => $r['order_no'],
                    'pickup'      => $r['pickup'] ?? 'Store',
                    'drop'        => trim(((string) $r['drop_line1']) . ', ' . ((string) $r['drop_city']), ', '),
                    'fee'         => number_format((float) ($r['delivery_fee'] ?? 0), 0),
                    'cod'         => $cod ? number_format((float) $r['grand_total'], 0) : null,
                    'seconds'     => max(0, strtotime((string) $r['expires_at']) - time()),
                ];
            }
        }

        $activeCount = $db->table('delivery_assignments da')->join('deliveries d', 'd.id = da.delivery_id')
            ->where('da.rider_user_id', $userId)->where('da.status', 'accepted')
            ->whereIn('d.status', self::ACTIVE_DELIVERY)->countAllResults();

        return [
            'online'       => $online,
            'offers'       => $offers,
            'active_count' => $activeCount,
            'today'        => $this->todayStats($userId),
        ];
    }

    /** @return array<string,mixed> today's delivered count, earnings, cash-in-hand, on-duty hours. */
    private function todayStats(int $userId): array
    {
        $db    = Database::connect();
        $start = date('Y-m-d 00:00:00');
        $earn  = (float) ($db->table('rider_payout_entries')->selectSum('amount', 't')
            ->where('rider_user_id', $userId)->where('status !=', 'reversed')->where('created_at >=', $start)->get()->getRowArray()['t'] ?? 0);
        $delivered = $db->table('delivery_assignments da')->join('deliveries d', 'd.id = da.delivery_id')
            ->where('da.rider_user_id', $userId)->where('da.status', 'accepted')->where('d.status', 'delivered')->where('d.delivered_at >=', $start)->countAllResults();

        return [
            'earnings'  => number_format($earn, 0),
            'delivered' => $delivered,
            'cash'      => number_format($this->cashInHand($userId), 0),
            'hours'     => round($this->shiftMinutesToday($userId) / 60, 1),
        ];
    }

    /** Cash the rider is currently holding (COD collected, not yet deposited). */
    public function cashInHand(int $userId): float
    {
        return (float) (Database::connect()->table('cod_collections')->selectSum('amount', 't')
            ->where('rider_user_id', $userId)->whereIn('status', ['pending', 'collected'])->get()->getRowArray()['t'] ?? 0);
    }

    /** Open a duty session when the rider goes online (no-op if already on duty). */
    public function startShift(int $userId): void
    {
        $db = Database::connect();
        if ($db->table('rider_shifts')->where('rider_user_id', $userId)->where('ended_at', null)->countAllResults() > 0) {
            return;
        }
        $db->table('rider_shifts')->insert(['uuid' => bin2hex(random_bytes(18)), 'rider_user_id' => $userId, 'started_at' => date('Y-m-d H:i:s')]);
    }

    /** Close the open duty session when the rider goes offline. */
    public function endShift(int $userId): void
    {
        $db  = Database::connect();
        $row = $db->table('rider_shifts')->select('id, started_at')->where('rider_user_id', $userId)->where('ended_at', null)
            ->orderBy('id', 'DESC')->get()->getRowArray();
        if ($row === null) {
            return;
        }
        $dur = max(0, (int) round((time() - strtotime((string) $row['started_at'])) / 60));
        $db->table('rider_shifts')->where('id', (int) $row['id'])->update(['ended_at' => date('Y-m-d H:i:s'), 'duration_min' => $dur]);
    }

    public function shiftMinutesToday(int $userId): int
    {
        $start = date('Y-m-d 00:00:00');
        $total = 0;
        foreach (Database::connect()->table('rider_shifts')->select('started_at, ended_at')
            ->where('rider_user_id', $userId)->where('started_at >=', $start)->get()->getResultArray() as $r) {
            $s = strtotime((string) $r['started_at']);
            $e = $r['ended_at'] !== null ? strtotime((string) $r['ended_at']) : time();
            $total += max(0, (int) round(($e - $s) / 60));
        }

        return $total;
    }

    /** @return array<string,mixed> earnings breakdown (today/week/total + recent entries + cash). */
    public function earningsDetail(int $userId): array
    {
        $db        = Database::connect();
        $today     = date('Y-m-d 00:00:00');
        $weekStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $sum = function (?string $since) use ($db, $userId): float {
            $b = $db->table('rider_payout_entries')->selectSum('amount', 't')->where('rider_user_id', $userId)->where('status !=', 'reversed');
            if ($since !== null) {
                $b->where('created_at >=', $since);
            }

            return (float) ($b->get()->getRowArray()['t'] ?? 0);
        };
        $entries = $db->table('rider_payout_entries rpe')
            ->select('rpe.amount, rpe.distance_km, rpe.order_value, rpe.status, rpe.created_at, o.order_no')
            ->join('deliveries d', 'd.id = rpe.delivery_id', 'left')
            ->join('sub_orders so', 'so.id = d.sub_order_id', 'left')
            ->join('orders o', 'o.id = so.order_id', 'left')
            ->where('rpe.rider_user_id', $userId)->orderBy('rpe.id', 'DESC')->limit(40)->get()->getResultArray();

        return [
            'today'        => number_format($sum($today), 2),
            'week'         => number_format($sum($weekStart), 2),
            'total'        => number_format($sum(null), 2),
            'cash_in_hand' => number_format($this->cashInHand($userId), 2),
            'entries'      => $entries,
        ];
    }

    /** @return array<string,mixed> performance metrics for the rider. */
    public function performance(int $userId): array
    {
        $db = Database::connect();
        $m  = [];
        try {
            $m = service('deliveryTrackingRepository')->metricsForRider($userId);
        } catch (Throwable) {
        }
        $weekStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $weekMin   = 0;
        foreach ($db->table('rider_shifts')->select('started_at, ended_at')->where('rider_user_id', $userId)->where('started_at >=', $weekStart)->get()->getResultArray() as $r) {
            $s = strtotime((string) $r['started_at']);
            $e = $r['ended_at'] !== null ? strtotime((string) $r['ended_at']) : time();
            $weekMin += max(0, (int) round(($e - $s) / 60));
        }
        $completed = $db->table('delivery_assignments da')->join('deliveries d', 'd.id = da.delivery_id')
            ->where('da.rider_user_id', $userId)->where('da.status', 'accepted')->where('d.status', 'delivered')->countAllResults();

        return [
            'acceptance_pct' => (float) ($m['acceptance_pct'] ?? 0),
            'on_time_pct'    => (float) ($m['on_time_pct'] ?? 0),
            'avg_rating'     => (float) ($m['avg_rating'] ?? 0),
            'rating_count'   => (int) ($m['rating_count'] ?? $m['reviews'] ?? 0),
            'open_disputes'  => (int) ($m['open_disputes'] ?? 0),
            'completed'      => $completed,
            'week_hours'     => round($weekMin / 60, 1),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function notifications(int $userId): array
    {
        return Database::connect()->table('notifications')
            ->select('event_code, title, body, status, created_at')
            ->where('user_id', $userId)->where('deleted_at', null)
            ->orderBy('created_at', 'DESC')->limit(50)
            ->get()->getResultArray();
    }

    private function featureEnabled(string $key): bool
    {
        try {
            $row = Database::connect()->table('feature_flags')->select('is_enabled')->where('key', $key)->get()->getRowArray();

            return $row !== null ? (bool) $row['is_enabled'] : true; // default-on if not configured
        } catch (Throwable) {
            return true;
        }
    }

    /** @param array<string,mixed> $r */
    private function shape(array $r): array
    {
        $cod = ($r['pay_method'] ?? '') === 'cod' && ($r['payment_status'] ?? '') !== 'paid'
            ? number_format((float) $r['grand_total'], 2, '.', '') : '0.00';

        return [
            'delivery_id'       => (int) $r['delivery_id'],
            'order_no'          => $r['order_no'],
            'sub_order_no'      => $r['sub_order_no'],
            'assignment_status' => $r['assignment_status'],
            'status'            => $r['delivery_status'],
            'eta_at'            => $r['eta_at'],
            'delivery_fee'      => number_format((float) ($r['delivery_fee'] ?? 0), 2, '.', ''),
            'cod_amount'        => $cod,
            'pod_type'          => $r['pod_type'],
            'pickup'            => ['shop' => $r['pickup_shop'], 'lat' => $r['pickup_lat'], 'lng' => $r['pickup_lng']],
            'drop'             => [
                'name' => $r['drop_name'], 'phone' => $r['drop_phone'],
                'address' => trim(((string) $r['drop_line1']) . ', ' . ((string) $r['drop_city']) . ' ' . ((string) $r['drop_pincode'])),
                'lat' => $r['drop_lat'], 'lng' => $r['drop_lng'],
            ],
        ];
    }
}
