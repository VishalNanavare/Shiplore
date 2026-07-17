<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * POS 80mm PDFs (DMart-style): the sale receipt and credit note render to real
 * PDF bytes from loaded data (no DB), incl. the per-slab GST summary + savings.
 */
final class PosPdfTest extends CIUnitTestCase
{
    public function testReceiptPdfRenders(): void
    {
        $sale = [
            'server_invoice_no' => 'SMA-20260613-1', 'sold_at' => '2026-06-13 10:00', 'order_type' => 'takeaway',
            'subtotal' => 120, 'discount_total' => 20, 'taxable_value' => 95.24, 'cgst' => 2.38, 'sgst' => 2.38,
            'igst' => 0, 'round_off' => 0, 'grand_total' => 100,
            'items' => [[
                'product_title_snapshot' => 'Masala Tea', 'sku_snapshot' => 'TEA-1', 'hsn_snapshot' => '0902',
                'qty' => '1', 'unit_price' => 100, 'mrp_snapshot' => 120, 'tax_rate' => '5',
                'taxable_value' => 95.24, 'cgst' => 2.38, 'sgst' => 2.38, 'igst' => 0, 'line_total' => 100,
            ]],
            'payments' => [['tender_type' => 'cash', 'amount' => 100]],
        ];
        $bill = ['shop_name' => 'DMart Powai', 'gstin' => '27ABCDE1234F1Z5', 'settings' => ['show_savings' => 1, 'footer_note' => 'Visit Again']];

        $r = service('documentPdfService')->posReceiptPdf($sale, $bill);
        $this->assertTrue($r['ok']);
        $this->assertStringStartsWith('%PDF', (string) $r['bytes']);
    }

    public function testCreditNotePdfRenders(): void
    {
        $cn = [
            'credit_note_no' => 'CN-S3/2026-27/00001', 'created_at' => '2026-06-13 11:00', 'against_invoice' => 'SMA-20260613-1',
            'customer_name' => 'Anaya Rao', 'reason' => 'Damaged', 'refund_amount' => 50, 'refund_method' => 'cash',
            'taxable_value' => 47.62, 'cgst' => 1.19, 'sgst' => 1.19, 'igst' => 0,
            'items' => [['title' => 'Masala Tea', 'sku' => 'TEA-1', 'qty' => '1', 'amount' => 50]],
        ];
        $bill = ['shop_name' => 'DMart Powai', 'settings' => []];

        $r = service('documentPdfService')->posCreditNotePdf($cn, $bill);
        $this->assertTrue($r['ok']);
        $this->assertStringStartsWith('%PDF', (string) $r['bytes']);
    }
}
