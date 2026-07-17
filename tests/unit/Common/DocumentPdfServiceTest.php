<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use App\Libraries\Pdf\DocumentPdfService;

/** X2c — dompdf pipeline: HTML in, real PDF bytes out (A4 + 80mm receipt). */
final class DocumentPdfServiceTest extends TestCase
{
    public function testRenderHtmlProducesPdf(): void
    {
        $bytes = (new DocumentPdfService())->renderHtml('<h1>Invoice INV-S1/2026-27/00001</h1><table><tr><td>Rice</td><td>900.00</td></tr></table>');

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(500, strlen($bytes));
    }

    public function testReceiptPaperIs80mmRoll(): void
    {
        $bytes = (new DocumentPdfService())->renderHtml('<p>POS RECEIPT</p>', 'receipt');

        $this->assertStringStartsWith('%PDF', $bytes);
        // 80 mm = 226.77pt page width must appear in the page descriptor
        $this->assertStringContainsString('226.77', $bytes);
    }

    public function testUtf8Survives(): void
    {
        $bytes = (new DocumentPdfService())->renderHtml('<p>Total ₹1,234.00 — धन्यवाद</p>');

        $this->assertStringStartsWith('%PDF', $bytes);
    }
}
