<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Catalog/BarcodeLabelService.php';

use App\Libraries\Catalog\BarcodeLabelService;

/** S3 — barcode label sheet rendering (pure). */
final class BarcodeLabelServiceTest extends TestCase
{
    public function testExpandRepeatsByQty(): void
    {
        $cells = BarcodeLabelService::expand([
            ['title' => 'Coke', 'sku' => 'CK1', 'mrp' => '40', 'barcode' => '8901', 'qty' => 3],
            ['title' => 'Chips', 'sku' => 'CH1', 'mrp' => '20', 'barcode' => '8902', 'qty' => 1],
        ]);

        $this->assertCount(4, $cells);
        $this->assertSame('40.00', $cells[0]['mrp']);
        $this->assertSame('Chips', $cells[3]['title']);
    }

    public function testExpandDefaultsToOneCopy(): void
    {
        $this->assertCount(1, BarcodeLabelService::expand([['title' => 'X', 'barcode' => '1']]));
    }

    public function testSheetHtmlIsSelfContainedPdfReady(): void
    {
        $html = BarcodeLabelService::sheetHtml([['title' => 'Coke', 'sku' => 'CK1', 'mrp' => '40', 'barcode' => '8901030865275', 'qty' => 2]], 3);

        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('8901030865275', $html);
        $this->assertStringContainsString('CK1', $html);
        $this->assertStringContainsString('MRP 40.00', $html);
        // two copies → two barcode cells
        $this->assertSame(2, substr_count($html, 'class="bc"'));
    }

    public function testColumnsClampAndRowWrap(): void
    {
        // 4 labels at 2 columns → 2 rows, padded to a rectangle
        $html = BarcodeLabelService::sheetHtml([
            ['title' => 'A', 'barcode' => '1', 'qty' => 4],
        ], 2);

        $this->assertSame(2, substr_count($html, '<tr>'));
        $this->assertStringContainsString('width:50%', $html);
    }

    public function testEscapesHtml(): void
    {
        $html = BarcodeLabelService::sheetHtml([['title' => '<script>x</script>', 'barcode' => '1', 'qty' => 1]]);

        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testEmptyLabelsRenderGracefully(): void
    {
        $html = BarcodeLabelService::sheetHtml([]);

        $this->assertStringContainsString('No labels', $html);
    }
}
