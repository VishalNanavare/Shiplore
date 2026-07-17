<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

use Config\Database;

/**
 * RiderDispatchService — live order dispatch (Blinkit/Zomato style). Turns a
 * 'pending' delivery into a time-boxed OFFER to the nearest ONLINE rider; if the
 * offer lapses (expires_at) or is declined, it re-offers to the next-nearest rider
 * who hasn't seen it yet. Accepting an offer assigns the delivery.
 *
 * Driven two ways: lazily on the rider poll (so offers flow while riders are
 * online) and by the `rider:dispatch` cron command. Both are idempotent — at most
 * ONE live offer exists per delivery at a time (NOT EXISTS guard).
 */
final class RiderDispatchService
{
    public const OFFER_TTL_SEC      = 60;   // seconds a rider has to accept an offer
    private const MAX_PER_RUN       = 25;    // pending deliveries dispatched per run
    private const MAX_OFFER_ROUNDS  = 6;     // distinct riders offered before giving up

    /** @return array{expired:int,offered:int} */
    public function run(): array
    {
        return ['expired' => $this->expireStaleOffers(), 'offered' => $this->dispatchPending()];
    }

    /** Lapse offers past their TTL so the delivery can be re-offered to someone else. */
    public function expireStaleOffers(): int
    {
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        $ids = array_column($db->table('delivery_assignments')->select('id')
            ->where('status', 'offered')->where('expires_at IS NOT NULL', null, false)->where('expires_at <', $now)
            ->get()->getResultArray(), 'id');
        if ($ids === []) {
            return 0;
        }
        $db->table('delivery_assignments')->whereIn('id', array_map('intval', $ids))->update(['status' => 'reassigned']);

        return count($ids);
    }

    /** Offer each un-offered 'pending' delivery to its nearest eligible online rider. */
    public function dispatchPending(): int
    {
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        $pending = $db->table('deliveries d')
            ->select('d.id, s.vendor_id, s.latitude AS shop_lat, s.longitude AS shop_lng')
            ->join('shops s', 's.id = d.shop_id', 'left')
            ->where('d.status', 'pending')->where('d.deleted_at', null)
            ->where("NOT EXISTS (SELECT 1 FROM delivery_assignments da WHERE da.delivery_id = d.id AND (da.status = 'accepted' OR (da.status = 'offered' AND da.expires_at > '" . $now . "')))", null, false)
            ->orderBy('d.created_at', 'ASC')->limit(self::MAX_PER_RUN)
            ->get()->getResultArray();

        $offered = 0;
        foreach ($pending as $d) {
            if ($d['vendor_id'] === null || $d['shop_lat'] === null) {
                continue;
            }
            $rider = $this->pickRider((int) $d['id'], (int) $d['vendor_id'], (float) $d['shop_lat'], (float) $d['shop_lng']);
            if ($rider === null) {
                continue;
            }
            // uq_da_offered makes this atomic: a concurrent run that already offered
            // this delivery will make the INSERT throw, and we simply skip it.
            try {
                $db->table('delivery_assignments')->insert([
                    'delivery_id'   => (int) $d['id'],
                    'rider_user_id' => $rider,
                    'offered_at'    => $now,
                    'expires_at'    => date('Y-m-d H:i:s', time() + self::OFFER_TTL_SEC),
                    'status'        => 'offered',
                ]);
                $offered++;
            } catch (\Throwable) {
                // already offered by a concurrent run — skip
            }
        }

        return $offered;
    }

    /** Nearest online+available vendor rider who hasn't already seen this delivery. */
    private function pickRider(int $deliveryId, int $vendorId, float $shopLat, float $shopLng): ?int
    {
        $db = Database::connect();
        $seen = array_map('intval', array_column(
            $db->table('delivery_assignments')->select('rider_user_id')->where('delivery_id', $deliveryId)
                ->whereIn('status', ['offered', 'declined', 'reassigned', 'accepted'])->get()->getResultArray(),
            'rider_user_id'
        ));
        // Give up after enough distinct riders have been tried (leave pending for manual assign).
        if (count(array_unique($seen)) >= self::MAX_OFFER_ROUNDS) {
            return null;
        }
        $riders = $db->table('delivery_boys db')
            ->select('db.user_id, db.availability, db.current_lat AS lat, db.current_lng AS lng, db.max_active_orders AS max')
            ->select("(SELECT COUNT(*) FROM delivery_assignments da JOIN deliveries d2 ON d2.id = da.delivery_id WHERE da.rider_user_id = db.user_id AND da.status = 'accepted' AND d2.status IN ('assigned','picked_up','out_for_delivery')) AS active", false)
            ->where('db.vendor_id', $vendorId)->where('db.deleted_at', null)
            ->where('db.status', 'active')->where('db.availability', 'available')
            ->get()->getResultArray();
        $pool = array_values(array_filter($riders, static fn ($r) => ! in_array((int) $r['user_id'], $seen, true)));
        $best = service('deliveryAssignmentService')->pickBest($pool, $shopLat, $shopLng);

        return $best !== null ? (int) $best['user_id'] : null;
    }
}
