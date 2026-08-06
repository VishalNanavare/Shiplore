<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Tax/GstCalculator.php';

use App\Libraries\Tax\GstCalculator;

/**
 * Indian GST engine — inclusive vs exclusive, intra-state (CGST+SGST) vs
 * inter-state (IGST). @see docs/architecture/10-GST-ARCHITECTURE.md
 */
final class GstCalculatorTest extends TestCase
{
    public function testExclusiveIntraState(): void
    {
        $r = (new GstCalculator())->compute('100', 18.0, false, false);
        $this->assertSame('100.00', $r['taxable']);
        $this->assertSame('18.00', $r['tax']);
        $this->assertSame('9.00', $r['cgst']);
        $this->assertSame('9.00', $r['sgst']);
        $this->assertSame('0.00', $r['igst']);
        $this->assertSame('118.00', $r['total']);
    }

    public function testInclusiveIntraState(): void
    {
        // ₹118 already includes 18% GST -> taxable 100, tax 18
        $r = (new GstCalculator())->compute('118', 18.0, true, false);
        $this->assertSame('100.00', $r['taxable']);
        $this->assertSame('18.00', $r['tax']);
        $this->assertSame('9.00', $r['cgst']);
        $this->assertSame('9.00', $r['sgst']);
        $this->assertSame('118.00', $r['total']);
    }

    public function testExclusiveInterStateUsesIgst(): void
    {
        $r = (new GstCalculator())->compute('100', 18.0, false, true);
        $this->assertSame('18.00', $r['igst']);
        $this->assertSame('0.00', $r['cgst']);
        $this->assertSame('0.00', $r['sgst']);
        $this->assertSame('118.00', $r['total']);
    }

    public function testZeroRate(): void
    {
        $r = (new GstCalculator())->compute('500', 0.0, false, false);
        $this->assertSame('0.00', $r['tax']);
        $this->assertSame('500.00', $r['total']);
    }

    public function testOddSplitRoundsCgstSgstToTotal(): void
    {
        // 5% of 99.99 = 4.9995 -> 5.00; cgst+sgst must equal tax
        $r = (new GstCalculator())->compute('99.99', 5.0, false, false);
        $this->assertSame(
            $r['tax'],
            number_format((float) $r['cgst'] + (float) $r['sgst'], 2, '.', '')
        );
    }

    public function testInterStateDetection(): void
    {
        $c = new GstCalculator();
        $this->assertTrue($c->isInterState('27', '29'));
        $this->assertFalse($c->isInterState('27', '27'));
    }

    // ------------------------------------------------------------------ L10 (Money-backed arithmetic)

    /** Hand-verified reference values for ₹1000 inclusive @ 18% — pins the Money-backed computation exactly. */
    public function testInclusiveEighteenPercentOnOneThousandRupees(): void
    {
        $r = (new GstCalculator())->compute('1000', 18.0, true, false);
        $this->assertSame('847.46', $r['taxable']);
        $this->assertSame('152.54', $r['tax']);
        $this->assertSame('76.27', $r['cgst']);
        $this->assertSame('76.27', $r['sgst']);
        $this->assertSame('1000.00', $r['total']);
    }

    /**
     * A malformed amount must not throw — callers pass (string) casts of float
     * expressions, which can render as "1.0E-5"; Money::of() throws on that, and
     * an exception inside StoreOrderRepository::place() would roll back the whole
     * order. The norm() guard must catch this before it ever reaches Money::of().
     */
    public function testMalformedAmountIsNormalisedRatherThanThrown(): void
    {
        $r = (new GstCalculator())->compute('1.0E-5', 18.0, true, false);
        $this->assertSame('0.00', $r['taxable']);
        $this->assertSame('0.00', $r['total']);
    }

    /** A plain decimal amount must still work exactly as before after norm(). */
    public function testNormLeavesAPlainDecimalUnchanged(): void
    {
        $r = (new GstCalculator())->compute('  100.50  ', 18.0, false, false);
        $this->assertSame('100.50', $r['taxable']);
    }
}
