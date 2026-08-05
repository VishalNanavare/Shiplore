<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Store\LocationService;
use Config\Database;

/**
 * StoreShopRepository — location-aware shop discovery. Returns only the active
 * shops whose delivery radius covers the customer's location (the core
 * "location-based, delivery-radius-based visibility" rule), sorted nearest
 * first. Without a location, returns active shops unfiltered.
 */
final class StoreShopRepository
{
    /** @return list<array<string,mixed>> */
    public function nearby(?float $lat, ?float $lng, int $limit = 30): array
    {
        $qb = Database::connect()->table('shops s')
            ->select('s.id, s.name, s.pincode, s.state_code, s.latitude, s.longitude, s.delivery_radius_km, s.prep_time_min, s.pickup_enabled, s.min_order_value, s.delivery_fee, s.free_delivery_above, v.display_name AS vendor, bt.name AS business_type')
            ->join('vendors v', 'v.id = s.vendor_id', 'left')
            ->join('business_types bt', 'bt.id = v.business_type_id', 'left')
            ->where('s.status', 'active')->where('s.deleted_at', null);

        if ($lat === null || $lng === null) {
            return $qb->orderBy('s.name', 'ASC')->limit($limit)->get()->getResultArray();
        }

        // Bounding box pre-filter (≈55 km radius) before PHP Haversine loop. Capped as
        // a safety valve — a dense metro could otherwise return every shop within
        // ~55km with no SQL LIMIT at all; the Haversine loop below still sorts and
        // slices to the real $limit, so this only bites if a single box holds more
        // than max($limit*5, 500) active shops.
        $deg   = 0.5;
        $shops = $qb
            ->where('s.latitude >=', $lat - $deg)->where('s.latitude <=', $lat + $deg)
            ->where('s.longitude >=', $lng - $deg)->where('s.longitude <=', $lng + $deg)
            ->limit(max($limit * 5, 500))
            ->get()->getResultArray();

        // The admin "Max delivery radius (km)" is always the outer cap: a NULL shop
        // radius means "use the admin max", and any shop radius is clamped to it.
        $adminMax = service('settingsRepository')->deliveryMaxRadiusKm();

        $visible = [];
        foreach ($shops as $s) {
            if ($s['latitude'] === null || $s['longitude'] === null) {
                continue;
            }
            $dist   = LocationService::distanceKm($lat, $lng, (float) $s['latitude'], (float) $s['longitude']);
            $radius = $s['delivery_radius_km'] !== null ? (float) $s['delivery_radius_km'] : null;
            // radius=0 means disabled; NULL means capped at admin max; else min(radius, admin max).
            $deliverable = $radius === null
                ? $dist <= $adminMax
                : ($radius > 0 && $dist <= LocationService::effectiveRadiusKm($radius, $adminMax));
            if ($deliverable) {
                $s['distance_km'] = $dist;
                $s['eta_min']     = LocationService::etaMinutes($dist, $s['prep_time_min'] !== null ? (int) $s['prep_time_min'] : null);
                $visible[] = $s;
            }
        }
        usort($visible, static fn ($a, $b) => $a['distance_km'] <=> $b['distance_km']);

        return array_slice($visible, 0, $limit);
    }

    /** @var array<string,list<int>> per-request memo: "lat,lng,limit" => shop ids */
    private array $nearbyIdMemo = [];

    /** @return list<int> ids of shops that deliver to the location (empty if no location). */
    public function nearbyShopIds(?float $lat, ?float $lng, int $limit = 200): array
    {
        if ($lat === null || $lng === null) {
            return [];
        }
        // StoreController::home() calls this via scoped() up to 8 times per request
        // (root category facets, the deals rail, each of up to 5 category rails, the
        // main product grid) with the SAME session location — the result cannot
        // change within a request, so 7 of those 8 were pure waste on the busiest
        // page of the site. Keyed on rounded coords + limit so it can't be wrong for
        // a different point.
        $key = sprintf('%.6f,%.6f,%d', $lat, $lng, $limit);

        return $this->nearbyIdMemo[$key] ??= array_map(static fn ($s) => (int) $s['id'], $this->nearby($lat, $lng, $limit));
    }

    /**
     * Return the minimum min_order_value (most strict) across all active shops
     * belonging to the given vendor IDs. Returns null when no restriction applies.
     *
     * @param list<int> $vendorIds
     */
    public function minOrderValueForVendors(array $vendorIds): ?float
    {
        if ($vendorIds === []) {
            return null;
        }
        $row = Database::connect()->table('shops')
            ->selectMax('min_order_value', 'max_val') // most restrictive = highest minimum
            ->whereIn('vendor_id', $vendorIds)
            ->where('status', 'active')->where('deleted_at', null)
            ->where('min_order_value >', 0)
            ->get()->getRowArray();

        return ($row && $row['max_val'] !== null) ? (float) $row['max_val'] : null;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = Database::connect()->table('shops s')
            ->select('s.*, v.display_name AS vendor, bt.name AS business_type, bt.code AS business_type_code')
            ->join('vendors v', 'v.id = s.vendor_id', 'left')
            ->join('business_types bt', 'bt.id = v.business_type_id', 'left')
            ->where('s.id', $id)->where('s.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }
}
