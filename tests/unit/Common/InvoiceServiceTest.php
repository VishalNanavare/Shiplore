<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Models/InvoiceSeriesRepository.php';
require_once __DIR__ . '/../../../app/Libraries/Invoicing/InvoiceService.php';

use App\Libraries\Invoicing\InvoiceService;
use App\Models\InvoiceSeriesRepository;

/** X2b — GST invoice writer: FY math, numbering shape, doc type, line mapping. */
final class InvoiceServiceTest extends TestCase
{
    public function testIndianFinancialYearBoundaries(): void
    {
        $this->assertSame('2026-27', InvoiceSeriesRepository::fyLabel(new DateTimeImmutable('2026-04-01')));
        $this->assertSame('2025-26', InvoiceSeriesRepository::fyLabel(new DateTimeImmutable('2026-03-31')));
        $this->assertSame('2026-27', InvoiceSeriesRepository::fyLabel(new DateTimeImmutable('2027-03-31')));
        $this->assertSame('2099-00', InvoiceSeriesRepository::fyLabel(new DateTimeImmutable('2099-06-12')));
    }

    public function testNumberFormatAndShopScopedPrefix(): void
    {
        $this->assertSame('INV-S3/2026-27/00001', InvoiceSeriesRepository::format(InvoiceSeriesRepository::defaultPrefix('tax_invoice', 3), '2026-27', 1));
        $this->assertSame('CN-S12/2026-27/00042', InvoiceSeriesRepository::format(InvoiceSeriesRepository::defaultPrefix('credit_note', 12), '2026-27', 42));
        $this->assertSame('BOS/2025-26/12345', InvoiceSeriesRepository::format(InvoiceSeriesRepository::defaultPrefix('bill_of_supply', null), '2025-26', 12345));
    }

    public function testDocTypeBySupplierGstin(): void
    {
        $this->assertSame('tax_invoice', InvoiceService::docTypeFor('27ABCDE1234F1Z5'));
        $this->assertSame('bill_of_supply', InvoiceService::docTypeFor(null));
        $this->assertSame('bill_of_supply', InvoiceService::docTypeFor('   '));
    }

    public function testLinesFromItemsMapsSnapshots(): void
    {
        $items = [[
            'id' => 77, 'product_title_snapshot' => 'Basmati Rice 5kg', 'sku_snapshot' => 'RICE-5',
            'hsn_snapshot' => '1006', 'qty' => '2.000', 'unit_price' => '450.0000',
            'taxable_value' => '857.1400', 'tax_rate' => '5.00',
            'cgst' => '21.4300', 'sgst' => '21.4300', 'igst' => '0.0000', 'cess' => '0.0000',
            'line_total' => '900.0000',
        ]];

        $lines = InvoiceService::linesFromItems($items);

        $this->assertCount(1, $lines);
        $this->assertSame(77, $lines[0]['order_item_id']);
        $this->assertSame('Basmati Rice 5kg', $lines[0]['description']);
        $this->assertSame('1006', $lines[0]['hsn']);
        $this->assertSame('857.1400', $lines[0]['taxable_value']);
        $this->assertSame('5.00', $lines[0]['tax_rate']);
        $this->assertSame('900.0000', $lines[0]['line_total']);
        $this->assertArrayNotHasKey('invoice_id', $lines[0]); // caller attaches
    }
}
