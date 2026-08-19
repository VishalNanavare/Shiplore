<?php

declare(strict_types=1);

use App\Libraries\Manufacturer\B2bPolicy;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * The commercial rules of B2B trade.
 *
 * Every number here is a business decision, so the tests that matter most are about the
 * DEFAULTS: what the platform does before anybody has configured it. The rule is that an
 * unconfigured default must never be able to mislead — a made-up commission rate would
 * look authoritative on a settlement screen and a manufacturer would reconcile against
 * it, and an invented return window would silently authorise refunds nobody agreed to.
 */
final class B2bPolicyTest extends CIUnitTestCase
{
    private B2bPolicy $policy;

    /** @var array<string,mixed> */
    private array $store = [];

    protected function setUp(): void
    {
        parent::setUp();

        Services::injectMock('settingsRepository', new class ($this->store) {
            public array $store;
            public function __construct(array &$s) { $this->store = &$s; }

            public function get(string $ns, string $key, mixed $default = null): mixed
            {
                return $this->store[$ns . '.' . $key] ?? $default;
            }
        });
        $this->policy = new B2bPolicy();
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function set(string $key, mixed $value): void
    {
        $this->store['b2b.' . $key] = $value;
    }

    // ------------------------------------------------------------------ defaults

    /** Unconfigured commission is ZERO, not a guess — a manufacturer sees their gross. */
    public function testCommissionDefaultsToNothing(): void
    {
        $this->assertSame(0.0, $this->policy->commissionPercent());
        $this->assertSame(0.0, $this->policy->commissionOn(10000.0));
    }

    /** Unconfigured returns are CLOSED, not open for some invented window. */
    public function testReturnsAreClosedUntilAWindowIsSet(): void
    {
        $this->assertSame(0, $this->policy->returnWindowDays());
        $this->assertFalse($this->policy->isWithinReturnWindow(date('Y-m-d H:i:s')));
    }

    public function testPayoutsAreNotScheduledUntilConfigured(): void
    {
        $this->assertFalse($this->policy->payoutsConfigured());
        $this->assertTrue($this->policy->isUnconfigured(), 'a screen must be able to say so plainly');
    }

    // ---------------------------------------------------------------- commission

    public function testCommissionIsAppliedOnceConfigured(): void
    {
        $this->set('commission_percent', 5);

        $this->assertSame(5.0, $this->policy->commissionPercent());
        $this->assertSame(500.0, $this->policy->commissionOn(10000.0));
        $this->assertFalse($this->policy->isUnconfigured());
    }

    /**
     * Clamped to 0-100. A negative rate would pay the manufacturer MORE than the order
     * was worth; above 100 would invert the settlement and bill them for selling.
     */
    public function testAnOutOfRangeCommissionIsClamped(): void
    {
        $this->set('commission_percent', -20);
        $this->assertSame(0.0, $this->policy->commissionPercent());

        $this->set('commission_percent', 250);
        $this->assertSame(100.0, $this->policy->commissionPercent());
    }

    // -------------------------------------------------------------- return window

    public function testTheWindowIsCountedFromReceipt(): void
    {
        $this->set('return_window_days', 7);

        // Receipt, not dispatch: a buyer cannot inspect what has not arrived.
        $this->assertTrue($this->policy->isWithinReturnWindow('2026-08-01 10:00:00', '2026-08-05 10:00:00'));
        $this->assertTrue($this->policy->isWithinReturnWindow('2026-08-01 10:00:00', '2026-08-08 10:00:00'), 'the 7th day is still inside');
        $this->assertFalse($this->policy->isWithinReturnWindow('2026-08-01 10:00:00', '2026-08-09 10:00:00'), 'the 8th is not');
    }

    /** A PO that was never received has no window at all. */
    public function testAnUnreceivedOrderIsNotReturnable(): void
    {
        $this->set('return_window_days', 30);

        $this->assertFalse($this->policy->isWithinReturnWindow(null));
        $this->assertFalse($this->policy->isWithinReturnWindow(''));
    }

    /** A receipt date in the future is refused rather than treated as "just arrived". */
    public function testAFutureReceiptDateIsRefused(): void
    {
        $this->set('return_window_days', 30);

        $this->assertFalse($this->policy->isWithinReturnWindow('2026-09-01 10:00:00', '2026-08-01 10:00:00'));
    }

    // ------------------------------------------------------------------ deduction

    public function testTheDeductionIsWithheldFromTheRefund(): void
    {
        $this->set('return_deduction_percent', 10);

        $b = $this->policy->refundBreakdown(1000.0);

        $this->assertSame(100.0, $b['deduction']);
        $this->assertSame(900.0, $b['refund']);
    }

    /** With nothing configured the buyer gets the whole amount back. */
    public function testNoDeductionMeansAFullRefund(): void
    {
        $b = $this->policy->refundBreakdown(1000.0);

        $this->assertSame(0.0, $b['deduction']);
        $this->assertSame(1000.0, $b['refund']);
    }

    /** A 100% deduction refunds nothing — it cannot go below zero. */
    public function testAFullDeductionRefundsNothing(): void
    {
        $this->set('return_deduction_percent', 100);

        $this->assertSame(0.0, $this->policy->refundBreakdown(500.0)['refund']);
    }

    /**
     * A negative line value cannot become a payout.
     *
     * Asserted with NO deduction configured, deliberately. With a 100% deduction the
     * arithmetic cancels — -500 minus -500 is 0 — so the assertion passes even when the
     * max(0.0, …) clamp is deleted. A mutation run caught exactly that; the two cases
     * are separate tests now because they exercise different guards.
     */
    public function testANegativeLineValueCannotBecomeAPayout(): void
    {
        $b = $this->policy->refundBreakdown(-500.0);

        $this->assertSame(0.0, $b['refund']);
        $this->assertSame(0.0, $b['deduction']);
    }
}
