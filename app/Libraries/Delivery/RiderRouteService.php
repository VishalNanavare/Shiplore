<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

use App\Libraries\Store\LocationService;

/**
 * RiderRouteService — Phase 5 route batching. Orders a rider's active drops into
 * a sensible delivery sequence with a greedy nearest-neighbour heuristic starting
 * from the rider's current position. Pure computation (Haversine via
 * LocationService) — no I/O, so it is cheap to call on every route view and easy
 * to unit-test. Stops without coordinates are kept, appended at the end.
 */
final class RiderRouteService
{
    /**
     * @param list<array<string,mixed>> $stops each needs 'lat','lng' (nullable) + any payload
     * @return array{stops:list<array<string,mixed>>,total_km:float} ordered stops with
     *         per-leg + cumulative distance, plus the total route distance
     */
    public function optimize(array $stops, ?float $fromLat, ?float $fromLng): array
    {
        $located = [];
        $noCoords = [];
        foreach ($stops as $s) {
            if (isset($s['lat'], $s['lng']) && $s['lat'] !== null && $s['lng'] !== null) {
                $located[] = $s;
            } else {
                $noCoords[] = $s;
            }
        }

        $ordered = [];
        $total   = 0.0;
        $curLat  = $fromLat;
        $curLng  = $fromLng;
        $remaining = $located;

        while ($remaining !== []) {
            $bestIdx = 0;
            $bestKm  = null;
            if ($curLat !== null && $curLng !== null) {
                foreach ($remaining as $i => $s) {
                    $km = LocationService::distanceKm($curLat, $curLng, (float) $s['lat'], (float) $s['lng']);
                    if ($bestKm === null || $km < $bestKm) {
                        $bestKm  = $km;
                        $bestIdx = $i;
                    }
                }
            }
            $next = $remaining[$bestIdx];
            $leg  = $bestKm ?? 0.0;
            $total += $leg;
            $next['leg_km']   = round($leg, 2);
            $next['cum_km']   = round($total, 2);
            $next['seq']      = count($ordered) + 1;
            $ordered[]        = $next;
            $curLat           = (float) $next['lat'];
            $curLng           = (float) $next['lng'];
            array_splice($remaining, $bestIdx, 1);
        }

        foreach ($noCoords as $s) {
            $s['leg_km'] = null;
            $s['cum_km'] = round($total, 2);
            $s['seq']    = count($ordered) + 1;
            $ordered[]   = $s;
        }

        return ['stops' => $ordered, 'total_km' => round($total, 2)];
    }

    /**
     * Build a Google Maps directions URL that threads all located stops in order
     * (origin = rider position, last stop = destination, the rest = waypoints).
     * @param list<array<string,mixed>> $orderedStops
     */
    public static function mapsUrl(array $orderedStops, ?float $fromLat, ?float $fromLng): ?string
    {
        $pts = [];
        foreach ($orderedStops as $s) {
            if (isset($s['lat'], $s['lng']) && $s['lat'] !== null && $s['lng'] !== null) {
                $pts[] = $s['lat'] . ',' . $s['lng'];
            }
        }
        if ($pts === []) {
            return null;
        }
        $dest      = array_pop($pts);
        $params    = ['api' => 1, 'destination' => $dest, 'travelmode' => 'driving'];
        if ($fromLat !== null && $fromLng !== null) {
            $params['origin'] = $fromLat . ',' . $fromLng;
        }
        if ($pts !== []) {
            $params['waypoints'] = implode('|', $pts);
        }

        return 'https://www.google.com/maps/dir/?' . http_build_query($params);
    }
}
