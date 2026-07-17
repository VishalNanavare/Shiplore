<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Store/LocationService.php';

use App\Libraries\Store\LocationService;

/**
 * Storefront location math — Haversine distance + delivery-radius visibility.
 * @see docs/architecture/24-ADMIN-PANEL.md (shop visibility rule)
 */
final class LocationServiceTest extends TestCase
{
    public function testSamePointIsZero(): void
    {
        $this->assertSame(0.0, LocationService::distanceKm(19.136, 72.826, 19.136, 72.826));
    }

    public function testOneDegreeLongitudeAtEquator(): void
    {
        // ~111.19 km per degree of longitude at the equator
        $this->assertEqualsWithDelta(111.19, LocationService::distanceKm(0, 0, 0, 1), 0.5);
    }

    public function testAndheriToPowaiAboutEightKm(): void
    {
        $d = LocationService::distanceKm(19.136, 72.826, 19.117, 72.905);
        $this->assertGreaterThan(7.0, $d);
        $this->assertLessThan(10.0, $d);
    }

    public function testWithinRadius(): void
    {
        // Customer at Andheri; a shop 8km away serves a 10km radius -> visible
        $d = LocationService::distanceKm(19.136, 72.826, 19.117, 72.905);
        $this->assertTrue($d <= 10.0);
        // A Bengaluru shop (>800km) is NOT within any city radius
        $far = LocationService::distanceKm(19.136, 72.826, 12.935, 77.624);
        $this->assertGreaterThan(100.0, $far);
    }
}
