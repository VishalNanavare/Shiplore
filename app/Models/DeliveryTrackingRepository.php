<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Delivery\ContactMasker;
use Config\Database;
use Throwable;

/**
 * DeliveryTrackingRepository — Phase 3 customer-facing delivery tracking plus the
 * feedback loop around it: live status + rider last-known location for the buyer,
 * post-delivery ratings, customer disputes, and the admin-side rider performance
 * metrics / dispute queue built on the same data.
 *
 * Read access for tracking is by order number (a buyer can watch their order);
 * writes (rate / raise dispute) are always ownership-checked against the logged-in
 * customer. Rider metrics are cross-vendor admin reads.
 */
final class DeliveryTrackingRepository
{
    private const STEPS = ['pending', 'assigned', 'picked_up', 'out_for_delivery', 'delivered'];

    /**
     * Live tracking for one order: the order header + each delivery with status,
     * ETA, rider (masked contact + last-known GPS), status timeline, the buyer's
     * own review and any open dispute. `$customerUserId` decides `owned` (controls
     * whether the rate / dispute actions are shown).
     *
     * @return array<string,mixed>|null
     */
    public function forCustomerOrder(string $orderNo, ?int $customerUserId): ?array
    {
        $db    = Database::connect();
        $order = $db->table('orders o')
            ->select('o.id, o.order_no, o.status, o.payment_status, o.grand_total, o.created_at, c.user_id AS customer_user_id')
            ->join('customers c', 'c.id = o.customer_id', 'left')
            ->where('o.order_no', $orderNo)->where('o.deleted_at', null)
            ->get()->getRowArray();
        if ($order === null) {
            return null;
        }
        $owned = $customerUserId !== null && (int) $order['customer_user_id'] === $customerUserId;

        $rows = $db->table('deliveries d')
            ->select('d.id, d.status, d.eta_at, d.delivered_at, d.delivery_fee, d.mode, d.pod_type, so.sub_order_no, v.display_name AS vendor, s.name AS shop, s.latitude AS shop_lat, s.longitude AS shop_lng, oa.latitude AS drop_lat, oa.longitude AS drop_lng, u.id AS rider_user_id, u.name AS rider_name, u.phone AS rider_phone, db.vehicle_type, db.vehicle_no, db.current_lat AS rider_lat, db.current_lng AS rider_lng, db.last_location_at')
            ->join('sub_orders so', 'so.id = d.sub_order_id', 'left')
            ->join('vendors v', 'v.id = so.vendor_id', 'left')
            ->join('shops s', 's.id = d.shop_id', 'left')
            ->join('order_addresses oa', "oa.order_id = so.order_id AND oa.type = 'shipping'", 'left')
            ->join('delivery_assignments da', "da.delivery_id = d.id AND da.status = 'accepted'", 'left')
            ->join('users u', 'u.id = da.rider_user_id', 'left')
            ->join('delivery_boys db', 'db.user_id = u.id', 'left')
            ->where('so.order_id', $order['id'])->where('d.deleted_at', null)
            ->orderBy('d.id', 'ASC')
            ->get()->getResultArray();

        $deliveries = [];
        foreach ($rows as $r) {
            $deliveries[] = $this->shapeTracking($r, $owned);
        }

        return [
            'order_no'       => $order['order_no'],
            'order_status'   => $order['status'],
            'payment_status' => $order['payment_status'],
            'grand_total'    => $order['grand_total'],
            'created_at'     => $order['created_at'],
            'owned'          => $owned,
            'deliveries'     => $deliveries,
        ];
    }

    /** @param array<string,mixed> $r */
    private function shapeTracking(array $r, bool $owned): array
    {
        $did      = (int) $r['id'];
        $status   = (string) $r['status'];
        $stepIdx  = array_search($status, self::STEPS, true);
        $history  = Database::connect()->table('delivery_status_history')
            ->select('from_status, to_status, note, created_at')
            ->where('delivery_id', $did)->orderBy('created_at', 'ASC')->orderBy('id', 'ASC')
            ->get()->getResultArray();
        $review   = Database::connect()->table('delivery_reviews')
            ->select('rating, comment, created_at')->where('delivery_id', $did)->where('deleted_at', null)
            ->get()->getRowArray();
        $dispute  = Database::connect()->table('delivery_disputes')
            ->select('reason, status, resolution, created_at')->where('delivery_id', $did)->where('deleted_at', null)
            ->orderBy('id', 'DESC')->get()->getRowArray();

        $hasRider = ! empty($r['rider_user_id']);

        return [
            'id'           => $did,
            'sub_order_no' => $r['sub_order_no'],
            'vendor'       => $r['vendor'],
            'shop'         => $r['shop'],
            'status'       => $status,
            'step'         => $stepIdx === false ? null : (int) $stepIdx,
            'steps'        => self::STEPS,
            'is_terminal'  => in_array($status, ['delivered', 'failed', 'returned'], true),
            'eta_at'       => $r['eta_at'],
            'delivered_at' => $r['delivered_at'],
            'delivery_fee' => number_format((float) ($r['delivery_fee'] ?? 0), 2, '.', ''),
            'rider'        => $hasRider ? [
                'name'           => $r['rider_name'],
                'phone_masked'   => ContactMasker::mask((string) ($r['rider_phone'] ?? '')),
                'vehicle'        => trim((string) ($r['vehicle_type'] ?? '') . ' ' . (string) ($r['vehicle_no'] ?? '')),
                'lat'            => $r['rider_lat'] !== null ? (float) $r['rider_lat'] : null,
                'lng'            => $r['rider_lng'] !== null ? (float) $r['rider_lng'] : null,
                'last_seen'      => $r['last_location_at'],
            ] : null,
            'drop'         => ['lat' => $r['drop_lat'] !== null ? (float) $r['drop_lat'] : null, 'lng' => $r['drop_lng'] !== null ? (float) $r['drop_lng'] : null],
            'history'      => $history,
            'review'       => $review ?: null,
            'dispute'      => $dispute ?: null,
            'can_rate'     => $owned && $status === 'delivered' && ! $review,
            'can_dispute'  => $owned && ! $dispute,
        ];
    }

    /** Customer ownership of a delivery (delivery → sub-order → order → customer). */
    public function customerOwns(int $deliveryId, int $customerUserId): bool
    {
        return (bool) Database::connect()->table('deliveries d')
            ->join('sub_orders so', 'so.id = d.sub_order_id', 'left')
            ->join('orders o', 'o.id = so.order_id', 'left')
            ->join('customers c', 'c.id = o.customer_id', 'left')
            ->where('d.id', $deliveryId)->where('c.user_id', $customerUserId)->where('d.deleted_at', null)
            ->countAllResults();
    }

    /** @return array{order_id:?int,rider_user_id:?int}|null */
    private function refs(int $deliveryId): ?array
    {
        $row = Database::connect()->table('deliveries d')
            ->select('so.order_id, da.rider_user_id')
            ->join('sub_orders so', 'so.id = d.sub_order_id', 'left')
            ->join('delivery_assignments da', "da.delivery_id = d.id AND da.status = 'accepted'", 'left')
            ->where('d.id', $deliveryId)->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return ['order_id' => $row['order_id'] !== null ? (int) $row['order_id'] : null, 'rider_user_id' => $row['rider_user_id'] !== null ? (int) $row['rider_user_id'] : null];
    }

    /**
     * Customer rates a completed delivery (1–5 + optional comment). One review per
     * delivery (UNIQUE) — re-rating updates the existing row.
     * @return array{ok:bool,msg:string}
     */
    public function rate(int $deliveryId, int $customerUserId, int $rating, ?string $comment): array
    {
        if ($rating < 1 || $rating > 5) {
            return ['ok' => false, 'msg' => 'Please choose a rating from 1 to 5 stars.'];
        }
        if (! $this->customerOwns($deliveryId, $customerUserId)) {
            return ['ok' => false, 'msg' => 'That delivery is not on your account.'];
        }
        $d = Database::connect()->table('deliveries')->select('status')->where('id', $deliveryId)->get()->getRowArray();
        if (($d['status'] ?? '') !== 'delivered') {
            return ['ok' => false, 'msg' => 'You can rate a trip once it has been delivered.'];
        }
        $refs = $this->refs($deliveryId);
        $db   = Database::connect();
        $now  = date('Y-m-d H:i:s');
        $comment = $comment !== null ? mb_substr($comment, 0, 500) : null;

        $existing = $db->table('delivery_reviews')->select('id')->where('delivery_id', $deliveryId)->get()->getRowArray();
        if ($existing) {
            $db->table('delivery_reviews')->where('id', $existing['id'])
                ->update(['rating' => $rating, 'comment' => $comment, 'updated_at' => $now, 'deleted_at' => null]);
        } else {
            $db->table('delivery_reviews')->insert([
                'uuid' => $this->uuid(), 'delivery_id' => $deliveryId,
                'order_id' => $refs['order_id'] ?? null, 'rider_user_id' => $refs['rider_user_id'] ?? null,
                'customer_user_id' => $customerUserId, 'rating' => $rating, 'comment' => $comment,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return ['ok' => true, 'msg' => 'Thanks for rating your delivery!'];
    }

    /**
     * Customer raises a dispute about a delivery (e.g. item missing, never arrived,
     * rude rider). Opens a ticket for admin review.
     * @return array{ok:bool,msg:string}
     */
    public function raiseDispute(int $deliveryId, int $customerUserId, string $reason, ?string $detail): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'msg' => 'Please choose what went wrong.'];
        }
        if (! $this->customerOwns($deliveryId, $customerUserId)) {
            return ['ok' => false, 'msg' => 'That delivery is not on your account.'];
        }
        $open = Database::connect()->table('delivery_disputes')
            ->where('delivery_id', $deliveryId)->whereIn('status', ['open', 'investigating'])->where('deleted_at', null)
            ->countAllResults();
        if ($open > 0) {
            return ['ok' => false, 'msg' => 'A dispute for this delivery is already being reviewed.'];
        }
        $refs = $this->refs($deliveryId);
        $now  = date('Y-m-d H:i:s');
        Database::connect()->table('delivery_disputes')->insert([
            'uuid' => $this->uuid(), 'delivery_id' => $deliveryId,
            'order_id' => $refs['order_id'] ?? null, 'rider_user_id' => $refs['rider_user_id'] ?? null,
            'raised_by_user_id' => $customerUserId, 'raised_by_role' => 'customer',
            'reason' => mb_substr($reason, 0, 120), 'detail' => $detail !== null ? mb_substr($detail, 0, 2000) : null,
            'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return ['ok' => true, 'msg' => 'Your report was submitted — our team will review it.'];
    }

    // ---- Admin: rider performance + dispute queue ----

    /** @return array<string,mixed> Rider performance KPIs (by rider users.id). */
    public function metricsForRider(int $riderUserId): array
    {
        $db = Database::connect();

        // Assignment funnel → acceptance rate.
        $asg = $db->table('delivery_assignments')
            ->select("COUNT(*) AS total, SUM(status='accepted') AS accepted, SUM(status='declined') AS declined", false)
            ->where('rider_user_id', $riderUserId)->get()->getRowArray();
        $accepted = (int) ($asg['accepted'] ?? 0);
        $declined = (int) ($asg['declined'] ?? 0);

        // Delivery outcomes on accepted assignments.
        $del = $db->table('delivery_assignments da')
            ->select("SUM(d.status='delivered') AS delivered, SUM(d.status='failed') AS failed, SUM(d.status='delivered' AND d.delivered_at IS NOT NULL AND d.eta_at IS NOT NULL AND d.delivered_at <= d.eta_at) AS on_time, SUM(d.status IN ('assigned','picked_up','out_for_delivery')) AS active", false)
            ->join('deliveries d', 'd.id = da.delivery_id', 'left')
            ->where('da.rider_user_id', $riderUserId)->where('da.status', 'accepted')
            ->get()->getRowArray();
        $delivered = (int) ($del['delivered'] ?? 0);
        $onTime    = (int) ($del['on_time'] ?? 0);

        $rev = $db->table('delivery_reviews')
            ->select('COUNT(*) AS n, COALESCE(AVG(rating),0) AS avg_rating')
            ->where('rider_user_id', $riderUserId)->where('deleted_at', null)->get()->getRowArray();

        $openDisputes = (int) $db->table('delivery_disputes')
            ->where('rider_user_id', $riderUserId)->whereIn('status', ['open', 'investigating'])->where('deleted_at', null)
            ->countAllResults();

        return [
            'delivered'       => $delivered,
            'failed'          => (int) ($del['failed'] ?? 0),
            'active'          => (int) ($del['active'] ?? 0),
            'on_time'         => $onTime,
            'on_time_pct'     => $delivered > 0 ? (int) round($onTime / $delivered * 100) : null,
            'acceptance_pct'  => ($accepted + $declined) > 0 ? (int) round($accepted / ($accepted + $declined) * 100) : null,
            'declined'        => $declined,
            'avg_rating'      => round((float) ($rev['avg_rating'] ?? 0), 2),
            'rating_count'    => (int) ($rev['n'] ?? 0),
            'open_disputes'   => $openDisputes,
        ];
    }

    /** @return list<array<string,mixed>> Disputes involving a rider (admin queue). */
    public function disputesForRider(int $riderUserId): array
    {
        return Database::connect()->table('delivery_disputes dd')
            ->select('dd.id, dd.delivery_id, dd.reason, dd.detail, dd.status, dd.resolution, dd.raised_by_role, dd.created_at, dd.resolved_at, o.order_no')
            ->join('orders o', 'o.id = dd.order_id', 'left')
            ->where('dd.rider_user_id', $riderUserId)->where('dd.deleted_at', null)
            ->orderBy('dd.id', 'DESC')->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> Recent reviews for a rider (admin). */
    public function reviewsForRider(int $riderUserId, int $limit = 10): array
    {
        return Database::connect()->table('delivery_reviews dr')
            ->select('dr.rating, dr.comment, dr.created_at, o.order_no')
            ->join('orders o', 'o.id = dr.order_id', 'left')
            ->where('dr.rider_user_id', $riderUserId)->where('dr.deleted_at', null)
            ->orderBy('dr.id', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /** Admin transitions a dispute (investigating / resolved / rejected). */
    public function resolveDispute(int $disputeId, string $status, ?string $resolution, int $actorId): bool
    {
        if (! in_array($status, ['investigating', 'resolved', 'rejected'], true)) {
            return false;
        }
        $now   = date('Y-m-d H:i:s');
        $patch = ['status' => $status, 'resolution' => $resolution !== null ? mb_substr($resolution, 0, 2000) : null, 'updated_at' => $now];
        if (in_array($status, ['resolved', 'rejected'], true)) {
            $patch['resolved_by'] = $actorId;
            $patch['resolved_at'] = $now;
        }

        return (bool) Database::connect()->table('delivery_disputes')->where('id', $disputeId)->where('deleted_at', null)->update($patch);
    }

    private function uuid(): string
    {
        try {
            $d = random_bytes(16);
        } catch (Throwable) {
            $d = pack('N4', ...array_map(static fn ($x) => crc32($x), str_split(uniqid('', true) . __METHOD__, 4)));
        }
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
