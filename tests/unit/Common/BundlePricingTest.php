<?php

declare(strict_types=1);

use App\Libraries\Catalog\BundlePricing;
use CodeIgniter\Test\CIUnitTestCase as TestCase;

/** P6 — pure bundle/combo pricing + gift-card amount validation. */
final class BundlePricingTest extends TestCase
{
    private function comps(): array
    {
        return [
            ['qty' => 2.0, 'unit_price' => 100.0, 'price_contribution' => null],   // 200
            ['qty' => 1.0, 'unit_price' => 300.0, 'price_contribution' => 250.0],  // 250 (override)
        ];
    }

    public function testDynamicTotalSumsComponents(): void
    {
        $this->assertSame(450.0, BundlePricing::dynamicTotal($this->comps()));
    }

    public function testBundleModeUsesDynamicSum(): void
    {
        $r = BundlePricing::effective('bundle', 0.0, $this->comps());
        $this->assertSame(450.0, $r['price']);
        $this->assertSame(0.0, $r['savings']);
    }

    public function testComboModeUsesFixedPriceAndReportsSavings(): void
    {
        $r = BundlePricing::effective('combo', 399.0, $this->comps());
        $this->assertSame(399.0, $r['price']);
        $this->assertSame(450.0, $r['components_total']);
        $this->assertSame(51.0, $r['savings']);   // 450 - 399
    }

    public function testGiftCardDenominationMustMatch(): void
    {
        $this->assertTrue(BundlePricing::giftCardAmountValid(500, [100, 500, 1000], null, null));
        $this->assertFalse(BundlePricing::giftCardAmountValid(750, [100, 500, 1000], null, null));
    }

    public function testGiftCardRangeWhenNoDenominations(): void
    {
        $this->assertTrue(BundlePricing::giftCardAmountValid(750, [], 100.0, 1000.0));
        $this->assertFalse(BundlePricing::giftCardAmountValid(50, [], 100.0, 1000.0));
        $this->assertFalse(BundlePricing::giftCardAmountValid(2000, [], 100.0, 1000.0));
        $this->assertFalse(BundlePricing::giftCardAmountValid(0, [], null, null));
    }
}
