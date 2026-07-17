<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Import/ImportTemplateService.php';

use App\Libraries\Import\ImportTemplateService;
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;

/**
 * Bulk-import Excel template: the column spec (pure) and a real .xlsx workbook
 * (verified by opening the produced bytes as a zip and reading its parts).
 */
final class ImportTemplateServiceTest extends TestCase
{
    public function testProductColumnsCoverTheRichModel(): void
    {
        $cols = ImportTemplateService::columns('product');

        foreach (['vendor', 'category', 'brand', 'hsn', 'title', 'sku', 'barcode', 'product_type', 'gst_type', 'tax', 'unit', 'mrp', 'base_price', 'manufacturer', 'short_description', 'description'] as $c) {
            $this->assertContains($c, $cols, "product template must include '{$c}'");
        }
    }

    public function testUnknownTypeFallsBackToProduct(): void
    {
        $this->assertSame(ImportTemplateService::columns('product'), ImportTemplateService::columns('galaxy'));
    }

    public function testXlsxIsARealExcelWorkbook(): void
    {
        $bytes = (new ImportTemplateService())->xlsx('product', [
            'tax'      => ['GST18', 'GST5'],
            'unit'     => ['PCS', 'KG'],
            'category' => ['mens-footwear'],
            'vendor'   => ['fresh-foods'],
            'brand'    => ['nike'],
        ]);

        // .xlsx is a ZIP — must start with the PK signature and be non-trivial
        $this->assertStringStartsWith('PK', $bytes);
        $this->assertGreaterThan(800, strlen($bytes));

        $tmp = tempnam(sys_get_temp_dir(), 'tpltest') . '.xlsx';
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp) === true, 'produced bytes must be a valid zip/xlsx');

        // two worksheets (data + Instructions)
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet2.xml'));

        // box/spout writes inline strings into the sheet XML; sheet names live in
        // the workbook part. Assert across all three.
        $all = (string) $zip->getFromName('xl/worksheets/sheet1.xml')
            . (string) $zip->getFromName('xl/worksheets/sheet2.xml')
            . (string) $zip->getFromName('xl/workbook.xml');
        foreach (['vendor', 'sku', 'hsn', 'Instructions', 'Required', 'Tax-class codes', 'GST18'] as $needle) {
            $this->assertStringContainsString($needle, $all, "workbook should contain '{$needle}'");
        }
        $zip->close();
        @unlink($tmp);
    }

    public function testTemplateRoundTripsThroughTheImporterReader(): void
    {
        // Generate the template, then read it back with the SAME reader the
        // importer uses — the header row must equal the documented columns, so a
        // user can fill it in and upload it unchanged.
        $bytes = (new ImportTemplateService())->xlsx('product');
        $tmp   = tempnam(sys_get_temp_dir(), 'rt') . '.xlsx';
        file_put_contents($tmp, $bytes);

        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->open($tmp);
        $header = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $header = array_map(static fn ($c) => strtolower(trim((string) $c)), $row->toArray());
                break;
            }
            break;
        }
        $reader->close();
        @unlink($tmp);

        $this->assertSame(ImportTemplateService::columns('product'), $header);
    }
}
