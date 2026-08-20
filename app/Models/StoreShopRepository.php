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
        // v.status selected alongside display name — same join, no extra query — so
        // filterInactiveVendors() below can gate on it without a second round-trip.
        $qb = Database::connect()->table('shops s')
            ->select('s.id, s.name, s.pincode, s.state_code, s.latitude, s.longitude, s.delivery_radius_km, s.prep_time_min, s.pickup_enabled, s.min_order_value, s.delivery_fee, s.free_delivery_above, s.vendor_id, v.display_name AS vendor, v.status AS vendor_status, bt.name AS business_type')
            ->join('vendors v', 'v.id = s.vendor_id', 'left')
            ->join('business_types bt', 'bt.id = v.business_type_id', 'left')
            ->where('s.status', 'active')->where('s.deleted_at', null);

        if ($lat === null || $lng === null) {
            return $this->filterInactiveVendors($qb->orderBy('s.name', 'ASC')->limit($limit)->get()->getResultArray(), 'nearby() without a location');
        }

        // Bounding box pre-filter (≈55 km radius) before the PHP Haversine loop, capped
        // as a safety valve so a dense metro cannot pull every shop in a ~55km box into
        // PHP on every request.
        //
        // The ORDER BY is load-bearing, not cosmetic. This cap originally shipped
        // WITHOUT one, so MySQL returned an arbitrary slice (in practice lowest-id
        // first): on an install with 10k shops the box held far more than the cap, the
        // slice never contained the genuinely nearest shops, and the whole storefront
        // rendered "no shops deliver to your location" for a location with shops 900 m
        // away. Ordering by squared planar distance keeps the NEAREST rows, so
        // truncation can only ever drop shops farther than the cap-th nearest.
        // Longitude is scaled by cos(lat) because a degree of longitude is shorter than
        // a degree of latitude away from the equator; without it the ordering skews
        // east-west. Squared distance is monotonic with real distance, so no sqrt is
        // needed and the Haversine loop below still does the authoritative filtering.
        $deg    = 0.5;
        $lngAdj = max(0.01, cos(deg2rad($lat))); // guard against cos()→0 at the poles
        $order  = sprintf(
            '(POW(s.latitude - %.8F, 2) + POW((s.longitude - %.8F) * %.8F, 2)) ASC',
            $lat,
            $lng,
            $lngAdj,
        );
        $shops = $qb
            ->where('s.latitude >=', $lat - $deg)->where('s.latitude <=', $lat + $deg)
            ->where('s.longitude >=', $lng - $deg)->where('s.longitude <=', $lng + $deg)
            ->orderBy($order, '', false)
            ->limit(max($limit * 5, 500))
            ->get()->getResultArray();
        $shops = $this->filterInactiveVendors($shops, 'nearby() location-scoped bounding box');

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

    /**
     * @return array<string,mixed>|null
     *
     * A direct/deep-linked URL used to render even a deactivated shop or one belonging
     * to a deactivated vendor — this checked NEITHER status. Staged behind
     * vendor.enforceStatusGate like every other call site in this file: log-only by
     * default, so pulling this row out never silently changes production behaviour
     * before the operator opts in.
     */
    public function find(int $id): ?array
    {
        $row = Database::connect()->table('shops s')
            ->select('s.*, v.display_name AS vendor, v.status AS vendor_status, bt.name AS business_type, bt.code AS business_type_code')
            ->join('vendors v', 'v.id = s.vendor_id', 'left')
            ->join('business_types bt', 'bt.id = v.business_type_id', 'left')
            ->where('s.id', $id)->where('s.deleted_at', null)
            ->get()->getRowArray();

        if ($row === null) {
            return null;
        }

        $shop   = ['id' => $row['id'], 'status' => $row['status']];
        $vendor = ['id' => $row['vendor_id'] ?? null, 'status' => $row['vendor_status'] ?? null];
        if (service('vendorStatusGate')->shouldBlockForShopStatus($shop, $vendor, 'StoreShopRepository::find shop #' . $id)) {
            return null;
        }

        return $row;
    }

    /**
     * @param list<array<string,mixed>> $rows each carrying its own vendor_id/vendor_status
     *        columns from the shared `v` join above — no second query needed.
     *
     * @return list<array<string,mixed>>
     */
    private function filterInactiveVendors(array $rows, string $context): array
    {
        return service('vendorStatusGate')->filterByVendorStatus(
            $rows,
            static fn (array $r): array => ['id' => $r['vendor_id'] ?? null, 'status' => $r['vendor_status'] ?? null],
            'StoreShopRepository::' . $context,
        );
    }
}
