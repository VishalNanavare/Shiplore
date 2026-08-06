<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Models/StoreOrderRepository.php';

use App\Models\StoreOrderRepository;

/**
 * Audit H11, safe half: the order-level coupon discount is allocated pro-rata
 * across sub-orders and recorded in `sub_orders.discount_total`, so the drift
 * between `orders.discount_total` and `SUM(sub_orders.*)` becomes visible and
 * auditable — a report can now show it happening. This is deliberately NOT the
 * full fix: GST (`taxable_value`/`cgst`/`sgst`/`igst`) and `commission_amount`
 * are left computed on the undiscounted line total, exactly as before, because
 * moving that basis changes stored money on every future couponed order (rated
 * High regression risk) and needs a separate accounting decision.
 */
final class SubOrderDiscountAllocationTest extends TestCase
{
    public function testNoDiscountAllocatesNothing(): void
    {
        $out = StoreOrderRepository::allocateDiscount(0.0, [500.0, 500.0]);
        $this->assertSame(['0.0000', '0.0000'], $out);
    }

    public function testSingleLineGetsTheFullDiscount(): void
    {
        $out = StoreOrderRepository::allocateDiscount(200.0, [1000.0]);
        $this->assertSame(['200.0000'], $out);
    }

    public function testTwoUnequalLinesAllocateProRataByValue(): void
    {
        // 200 case (sub #1) + 5000 phone (sub #2), 20% coupon on 5200 = 1040 discount.
        $out = StoreOrderRepository::allocateDiscount(1040.0, [200.0, 5000.0]);
        // 1040 * 200/5200 = 40.00 ; last line absorbs the remainder: 1040 - 40 = 1000.00
        $this->assertSame(['40.0000', '1000.0000'], $out);
    }

    /** The last line absorbing the remainder must make the parts sum back exactly, to the paisa, every time. */
    public function testAllocationAlwaysSumsExactlyToTheDiscountTotal(): void
    {
        $cases = [
            [333.33, [100.0, 100.0, 100.0]],
            [1.00, [0.01, 998.99, 1.00]],
            [999.99, [1234.56, 7891.01, 42.42]],
        ];
        foreach ($cases as [$discount, $lines]) {
            $out = StoreOrderRepository::allocateDiscount($discount, $lines);
            $sum = array_sum(array_map('floatval', $out));
            $this->assertEqualsWithDelta($discount, $sum, 0.0001, 'allocated parts must sum to the whole exactly');
        }
    }
}
