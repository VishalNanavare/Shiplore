<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Money.php';

use App\Libraries\Money;

/**
 * Phase 6 — Money value object spec.
 * Integer-backed (scale 4, matching DECIMAL(15,4)); no floats; half-up rounding.
 */
final class MoneyTest extends TestCase
{
    public function testParsesAndFormatsToFourDecimals(): void
    {
        $this->assertSame('47.6190', Money::of('47.6190')->amount());
        $this->assertSame('50.0000', Money::of('50')->amount());
        $this->assertSame('50.0000', Money::of(50)->amount());
    }

    public function testAdd(): void
    {
        $this->assertSame('200.0000', Money::of('100')->add(Money::of('100'))->amount());
    }

    public function testSubtract(): void
    {
        $this->assertSame('450.0000', Money::of('500')->sub(Money::of('50'))->amount());
    }

    public function testNoFloatDrift(): void
    {
        $this->assertSame('0.3000', Money::of('0.1')->add(Money::of('0.2'))->amount());
    }

    public function testMultiplyByQuantity(): void
    {
        $this->assertSame('250.0000', Money::of('100')->mulQty('2.5')->amount());
        $this->assertSame('200.0000', Money::of('100')->mulQty('2')->amount());
    }

    public function testRoundHalfUpToTwoDecimals(): void
    {
        // GST-inclusive ₹50 @ 5% -> taxable 47.6190 -> invoice round 2dp -> 47.62
        $this->assertSame('47.6200', Money::of('47.6190')->roundTo(2)->amount());
        // exact half rounds up
        $this->assertSame('1.0100', Money::of('1.005')->roundTo(2)->amount());
    }

    public function testEquals(): void
    {
        $this->assertTrue(Money::of('10')->equals(Money::of('10.0000')));
        $this->assertFalse(Money::of('10')->equals(Money::of('10.0001')));
    }
}
