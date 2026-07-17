<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Delivery/RiderPayoutService.php';

use App\Libraries\Delivery\RiderPayoutService;

/** X5 — rider payout models (doc 44 §7.2): fixed/per-km/per-order/salary/hybrid. */
final class RiderPayoutServiceTest extends TestCase
{
    public function testFixedCommission(): void
    {
        $this->assertSame(25.0, RiderPayoutService::compute(['model' => 'fixed_commission', 'pct_of_order' => '5'], 500.0, 3.0));
    }

    public function testPerKm(): void
    {
        $this->assertSame(34.5, RiderPayoutService::compute(['model' => 'per_km', 'per_km_rate' => '11.5'], 500.0, 3.0));
    }

    public function testPerOrder(): void
    {
        $this->assertSame(30.0, RiderPayoutService::compute(['model' => 'per_order', 'per_order_amount' => '30'], 999.0, 12.0));
    }

    public function testSalaryAccruesNothingPerDelivery(): void
    {
        $this->assertSame(0.0, RiderPayoutService::compute(['model' => 'salary', 'base_salary' => '18000'], 500.0, 3.0));
    }

    public function testHybridIncentiveOnlyAboveThreshold(): void
    {
        $plan = ['model' => 'hybrid', 'per_order_amount' => '15', 'incentive_threshold' => 100];

        $this->assertSame(0.0, RiderPayoutService::compute($plan, 500.0, 3.0, 99));   // below
        $this->assertSame(15.0, RiderPayoutService::compute($plan, 500.0, 3.0, 100)); // at threshold
        $this->assertSame(15.0, RiderPayoutService::compute($plan, 500.0, 3.0, 250)); // above
    }

    public function testAccrueWritesEntryFromPlan(): void
    {
        $written = [];
        $svc = new RiderPayoutService(
            static fn (int $u) => ['model' => 'fixed_commission', 'pct_of_order' => '4'],
            static function (array $e) use (&$written): void { $written[] = $e; },
        );

        $entry = $svc->accrueForDelivery(['id' => 8, 'rider_user_id' => 70, 'vendor_id' => 1, 'order_value' => 900.0, 'distance_km' => 2.5]);

        $this->assertNotNull($entry);
        $this->assertCount(1, $written);
        $this->assertSame('36.00', $written[0]['amount']);
        $this->assertSame('fixed_commission', $written[0]['model']);
        $this->assertSame('accrued', $written[0]['status']);
    }

    public function testNoPlanMeansNoEntry(): void
    {
        $written = [];
        $svc = new RiderPayoutService(
            static fn () => null,
            static function (array $e) use (&$written): void { $written[] = $e; },
        );

        $this->assertNull($svc->accrueForDelivery(['id' => 8, 'rider_user_id' => 70, 'order_value' => 900.0]));
        $this->assertSame([], $written);
    }
}
