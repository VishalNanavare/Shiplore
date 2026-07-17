<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Store/LocationService.php';

use App\Libraries\Store\LocationService;

/**
 * Delivery-area validation core — the pure radius math behind both conditions:
 * the admin "Max delivery radius (km)" is always the outer cap, and a shop's own
 * radius (when set) tightens it further. NULL shop radius => use the admin max;
 * the effective radius is min(shop, admin).
 *
 * @see app/Models/StoreCatalogRepository.php variantDeliverability()
 */
final class DeliveryRadiusTest extends TestCase
{
    // ---- effective radius: min(shop, admin); NULL => admin ----

    public function testNullShopRadiusUsesAdminMax(): void
    {
        $this->assertSame(5.0, LocationService::effectiveRadiusKm(null, 5.0));
    }

    public function testShopRadiusBelowAdminIsKept(): void
    {
        $this->assertSame(3.0, LocationService::effectiveRadiusKm(3.0, 5.0));
    }

    public function testShopRadiusAboveAdminIsCappedToAdmin(): void
    {
        $this->assertSame(5.0, LocationService::effectiveRadiusKm(8.0, 5.0));
    }

    // ---- deliverability decision: dist <= effective ----

    public function testWithinShopAndAdminIsDeliverable(): void
    {
        $effective = LocationService::effectiveRadiusKm(3.0, 5.0);
        $this->assertTrue(2.4 <= $effective);
    }

    public function testWithinAdminButBeyondShopIsNotDeliverable(): void
    {
        $effective = LocationService::effectiveRadiusKm(3.0, 5.0); // 3.0
        $this->assertFalse(4.2 <= $effective); // 4.2 km > 3 km shop radius
    }

    public function testNullRadiusBeyondAdminIsNotDeliverable(): void
    {
        $effective = LocationService::effectiveRadiusKm(null, 5.0); // 5.0
        $this->assertFalse(6.1 <= $effective); // 6.1 km > 5 km admin cap
    }

    // ---- reason classification ----

    public function testReasonNoServingShopWhenNoneCarry(): void
    {
        $this->assertSame('no_serving_shop', LocationService::undeliverableReason(null, 5.0));
    }

    public function testReasonOutsideServiceAreaBeyondAdmin(): void
    {
        $this->assertSame('outside_service_area', LocationService::undeliverableReason(6.1, 5.0));
    }

    public function testReasonShopNoDeliveryWithinAdmin(): void
    {
        $this->assertSame('shop_no_delivery', LocationService::undeliverableReason(4.2, 5.0));
    }

    public function testReasonAtAdminBoundaryIsShopNotOutside(): void
    {
        // exactly at the admin cap is still within service area → a shop-level miss
        $this->assertSame('shop_no_delivery', LocationService::undeliverableReason(5.0, 5.0));
    }
}
