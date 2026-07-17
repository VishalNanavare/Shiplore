<?php

declare(strict_types=1);

namespace App\Libraries\Store;

/**
 * GeoBucket — snaps a customer's exact lat/lng to a coarse delivery-zone cell so
 * that everyone within the same ~1 km cell shares one cache entry for facet/count
 * results. Counts are still computed *exactly* for the cell's canonical shop set
 * (the cell is the unit of exactness) — they are simply shared and cached per cell
 * instead of recomputed for every distinct GPS point.
 *
 * 0.01° ≈ 1.11 km of latitude (and ≈1.05 km of longitude at Mumbai's ~19°N), so
 * rounding to 2 decimal places gives a stable ~1 km grid cell. The product LIST
 * still uses the customer's exact coordinates — only the cached COUNTS are bucketed.
 *
 * Bash/Ruby parity: none — storefront-only PHP.
 */
final class GeoBucket
{
    /** Grid precision in decimal places (2 ⇒ ~1 km cell). */
    private const PRECISION = 2;

    /**
     * Cache scope key for a location. 'global' when no location is set (the
     * un-scoped catalog browse). Otherwise a stable per-cell key, e.g. "g19.12_72.85".
     */
    public static function keyFor(?float $lat, ?float $lng): string
    {
        if ($lat === null || $lng === null) {
            return 'global';
        }
        [$cLat, $cLng] = self::centroid($lat, $lng);

        return sprintf('g%s_%s', self::fmt($cLat), self::fmt($cLng));
    }

    /**
     * The cell centroid (lat/lng snapped to the grid). Used to resolve the cell's
     * canonical shop set deterministically, so every user in the cell computes the
     * same exact counts.
     *
     * @return array{0:float,1:float}
     */
    public static function centroid(float $lat, float $lng): array
    {
        return [round($lat, self::PRECISION), round($lng, self::PRECISION)];
    }

    /**
     * Recover the cell centroid from a bucket key produced by keyFor(). Lets the
     * background worker resolve a cell's canonical shop set from the queue's
     * scope_key alone. Returns null for 'global' or an unparseable key.
     *
     * @return array{0:float,1:float}|null
     */
    public static function centroidFromKey(string $key): ?array
    {
        if ($key === 'global' || ! str_starts_with($key, 'g')) {
            return null;
        }
        $parts = explode('_', substr($key, 1));
        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return null;
        }

        return [(float) $parts[0], (float) $parts[1]];
    }

    /** Stable string form of a rounded coordinate (avoids "-0" and locale issues). */
    private static function fmt(float $v): string
    {
        return number_format($v, self::PRECISION, '.', '');
    }
}
