<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

use App\Libraries\Store\LocationService;

/**
 * DeliveryAssignmentService — auto-assignment scoring. A rider is eligible when
 * available, not suspended, under capacity, and in the shop's service zone;
 * among eligible riders the nearest to the pickup shop wins.
 *
 * @see docs/architecture/36-DELIVERY-OPERATIONS.md
 */
final class DeliveryAssignmentService
{
    /**
     * Full eligibility check + score for one rider.
     *
     * @param array<string,mixed> $rider  Expected keys: user_id, availability (available|busy|offline),
     *                                    active_deliveries/active, max_active_orders/max, lat/current_lat,
     *                                    lng/current_lng, service_pincodes (comma-sep string, optional).
     *                                    Account status (active vs suspended/terminated) is filtered in SQL
     *                                    by every caller, not re-checked here.
     * @return array{eligible:bool,score:float,distance_km:float,reason:string}
     */
    public function score(array $rider, float $shopLat, float $shopLng, string $shopPincode = ''): array
    {
        // Field aliases across callers (delivery_boys real columns: availability,
        // status, max_active_orders, current_lat/lng).
        $availability  = (string) ($rider['availability'] ?? '');
        $activeCnt     = (int) ($rider['active'] ?? $rider['active_deliveries'] ?? 0);
        $maxCap        = (int) ($rider['max'] ?? $rider['max_active_orders'] ?? 1);
        $riderLat      = (float) ($rider['lat'] ?? $rider['current_lat'] ?? 0);
        $riderLng      = (float) ($rider['lng'] ?? $rider['current_lng'] ?? 0);
        $svcPincodes   = array_filter(array_map('trim', explode(',', (string) ($rider['service_pincodes'] ?? ''))));

        if ($availability !== 'available') {
            return ['eligible' => false, 'score' => 9999.0, 'distance_km' => 9999.0, 'reason' => 'not_available'];
        }
        if ($maxCap > 0 && $activeCnt >= $maxCap) {
            return ['eligible' => false, 'score' => 9999.0, 'distance_km' => 9999.0, 'reason' => 'at_capacity'];
        }
        // Service zone check: if rider has pincode list and shop's pincode is not in it, skip
        if ($shopPincode !== '' && $svcPincodes !== [] && ! in_array($shopPincode, $svcPincodes, true)) {
            return ['eligible' => false, 'score' => 9999.0, 'distance_km' => 9999.0, 'reason' => 'out_of_zone'];
        }

        $dist = ($riderLat !== 0.0 || $riderLng !== 0.0)
            ? LocationService::distanceKm($riderLat, $riderLng, $shopLat, $shopLng)
            : 9999.0;

        // Lower score = better; headroom nudge ensures freer riders win ties
        $headroom = max(0, $maxCap - $activeCnt);
        $score    = $dist - ($headroom * 0.01);

        return ['eligible' => true, 'score' => $score, 'distance_km' => $dist, 'reason' => 'ok'];
    }

    /**
     * Pick the best eligible rider from the candidate list.
     *
     * @param list<array<string,mixed>> $riders
     * @return array<string,mixed>|null best eligible rider, or null if none qualify
     */
    public function pickBest(array $riders, float $shopLat, float $shopLng, string $shopPincode = ''): ?array
    {
        $scored = [];
        foreach ($riders as $r) {
            $s = $this->score($r, $shopLat, $shopLng, $shopPincode);
            if ($s['eligible']) {
                $scored[] = ['rider' => $r, 'score' => $s['score']];
            }
        }
        if ($scored === []) {
            return null;
        }
        usort($scored, static fn ($a, $b) => $a['score'] <=> $b['score']);

        return $scored[0]['rider'];
    }
}
