<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Payment/RefundService.php';

use App\Libraries\Payment\RefundService;

/**
 * Refund GST-reversal math (credit note) — proportional to the refunded amount.
 * @see docs/architecture/38-PAYMENTS-REFUNDS-SETTLEMENTS.md
 */
final class RefundServiceTest extends TestCase
{
    private array $subOrder = ['taxable_value' => '3499.00', 'cgst' => '209.94', 'sgst' => '209.94', 'igst' => '0.00', 'grand_total' => '3918.88'];

    public function testFullRefundReversesAllTax(): void
    {
        $cn = RefundService::creditNoteTax($this->subOrder, 1.0);
        $this->assertSame('3499.00', $cn['taxable']);
        $this->assertSame('209.94', $cn['cgst']);
        $this->assertSame('209.94', $cn['sgst']);
        $this->assertSame('3918.88', $cn['grand']);
    }

    public function testPartialRefundProratesTax(): void
    {
        $cn = RefundService::creditNoteTax($this->subOrder, 0.5);
        $this->assertSame('1749.50', $cn['taxable']);
        $this->assertSame('104.97', $cn['cgst']);
        $this->assertSame('1959.44', $cn['grand']);
    }

    public function testFactorIsClampedToOne(): void
    {
        // a refund can never reverse more than the sub-order's tax
        $this->assertSame('1.0000', RefundService::factor('5000', '3918.88'));
        $this->assertSame('0.5000', RefundService::factor('1959.44', '3918.88'));
    }
}
