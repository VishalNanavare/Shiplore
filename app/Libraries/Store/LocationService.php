<?php

declare(strict_types=1);

namespace App\Libraries\Store;

/**
 * LocationService — the customer's chosen delivery location (session) + the
 * Haversine distance math that powers location-based, delivery-radius shop
 * visibility (a shop is shown only if the customer is within its
 * delivery_radius_km).
 *
 * @see docs/architecture/05-SYSTEM-ARCHITECTURE.md
 */
final class LocationService
{
    private const KEY = 'store_loc';

    /** Delivery-address components carried alongside the geo point (auto-fill checkout). */
    private const COMPONENTS = ['city', 'state_code', 'line1', 'formatted', 'name', 'phone'];

    /**
     * @param array<string,mixed> $extra optional reverse-geocode components:
     *        city, state_code (GST code), line1 (street), formatted (full address)
     */
    public function set(float $lat, float $lng, string $label, ?string $pincode = null, array $extra = []): void
    {
        $loc = ['lat' => $lat, 'lng' => $lng, 'label' => $label, 'pincode' => $pincode];
        foreach (self::COMPONENTS as $k) {
            $v = trim((string) ($extra[$k] ?? ''));
            $loc[$k] = $v !== '' ? $v : null;
        }
        session()->set(self::KEY, $loc);
    }

    /** @return array{lat:float,lng:float,label:string,pincode:?string,city:?string,state_code:?string,line1:?string,formatted:?string,name:?string,phone:?string}|null */
    public function get(): ?array
    {
        $l = session()->get(self::KEY);

        return is_array($l) ? $l : null;
    }

    public function has(): bool
    {
        return $this->get() !== null;
    }

    public function clear(): void
    {
        session()->remove(self::KEY);
    }

    /**
     * Rough delivery ETA in minutes: shop prep time + travel at ~20 km/h local
     * speed. Pure; used for the "~N min" promise on shop cards / listings.
     */
    public static function etaMinutes(float $distanceKm, ?int $prepMin = null): int
    {
        $prep   = $prepMin ?? 10;
        $travel = (int) ceil($distanceKm / 20 * 60);

        return max(10, $prep + $travel);
    }

    /** Great-circle distance in km (rounded to 2dp). Pure. */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat  = deg2rad($lat2 - $lat1);
        $dLng  = deg2rad($lng2 - $lng1);
        $a     = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    /**
     * A shop's effective delivery radius (km). The admin "Max delivery radius" is
     * always the outer cap: a NULL shop radius means "use the admin max", and any
     * shop radius is clamped to it. (radius=0 = disabled is handled by the caller.)
     * Pure.
     */
    public static function effectiveRadiusKm(?float $shopRadiusKm, float $adminMaxKm): float
    {
        return $shopRadiusKm === null ? $adminMaxKm : min($shopRadiusKm, $adminMaxKm);
    }

    /**
     * Canonical reason a variant is undeliverable, given the distance to the
     * nearest carrying shop (null when no active carrying shop has coordinates)
     * and the admin max radius. Pure.
     */
    public static function undeliverableReason(?float $nearestDistKm, float $adminMaxKm): string
    {
        if ($nearestDistKm === null) {
            return 'no_serving_shop';
        }

        return $nearestDistKm > $adminMaxKm ? 'outside_service_area' : 'shop_no_delivery';
    }
}
