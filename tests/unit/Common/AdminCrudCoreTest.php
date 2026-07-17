<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Media/MediaService.php';
require_once __DIR__ . '/../../../app/Models/AdminProductRepository.php';
require_once __DIR__ . '/../../../app/Libraries/Import/ImportService.php';

use App\Libraries\Import\ImportService;
use App\Libraries\Media\MediaService;
use App\Models\AdminProductRepository;

/**
 * Batch 1 pure logic — media validation, product slug/gating, import row
 * validation (incl. category-gating + dup SKU edge cases).
 */
final class AdminCrudCoreTest extends TestCase
{
    public function testMediaAllowsImagesUnder5mb(): void
    {
        $this->assertTrue(MediaService::isAllowed('image/jpeg', 1024)['ok']);
        $this->assertSame('png', MediaService::isAllowed('image/png', 2048)['ext']);
    }

    public function testMediaRejectsBadMimeAndSize(): void
    {
        $this->assertFalse(MediaService::isAllowed('application/x-php', 10)['ok']);
        $this->assertFalse(MediaService::isAllowed('image/jpeg', 6_000_000)['ok']);
        $this->assertFalse(MediaService::isAllowed('image/jpeg', 0)['ok']);
    }

    public function testDocumentValidationAllowsPdfUnder10mb(): void
    {
        $this->assertTrue(MediaService::isAllowedDocument('application/pdf', 1_000_000)['ok']);
        $this->assertSame('pdf', MediaService::isAllowedDocument('application/pdf', 100)['ext']);
        $this->assertFalse(MediaService::isAllowedDocument('application/pdf', 11_000_000)['ok']); // over 10MB
        $this->assertFalse(MediaService::isAllowedDocument('application/x-msdownload', 100)['ok']); // exe rejected
    }

    public function testSlugify(): void
    {
        $this->assertSame('running-sneakers', AdminProductRepository::slugify('Running  Sneakers!!'));
        $this->assertSame('product', AdminProductRepository::slugify('@#$'));
    }

    public function testCategoryAllowed(): void
    {
        $this->assertTrue(AdminProductRepository::isCategoryAllowed([3, 5, 7], 5));
        $this->assertFalse(AdminProductRepository::isCategoryAllowed([3, 5, 7], 9));
    }

    public function testEnumGuardsToAllowedOrDefault(): void
    {
        // valid value passes through; junk/empty/non-string fall back to default
        $this->assertSame('exclusive', AdminProductRepository::enum('exclusive', ['inclusive', 'exclusive'], 'inclusive'));
        $this->assertSame('inclusive', AdminProductRepository::enum('bogus', ['inclusive', 'exclusive'], 'inclusive'));
        $this->assertSame('inclusive', AdminProductRepository::enum('', ['inclusive', 'exclusive'], 'inclusive'));
        $this->assertSame('simple', AdminProductRepository::enum(null, ['simple', 'variant'], 'simple'));
        $this->assertSame('simple', AdminProductRepository::enum(['x'], ['simple', 'variant'], 'simple'));
    }

    private function ctx(): array
    {
        return [
            'vendors' => ['sole mate' => ['id' => 1, 'allowed_cats' => [10, 11]], '1' => ['id' => 1, 'allowed_cats' => [10, 11]]],
            'cats'    => ['mens-shoes' => 10, 'rice' => 99],
            'tax'     => ['GST_18' => 4],
            'units'   => ['pcs' => 1],
            'brands'  => ['nike' => 7, 'adidas' => 8],
            'hsn'     => ['6403' => 12],
            'skus'    => ['existing-sku' => true],
        ];
    }

    public function testImportResolvesBrandHsnAndRichFields(): void
    {
        $row = [
            'vendor' => 'Sole Mate', 'category' => 'mens-shoes', 'brand' => 'Nike', 'hsn' => '6403',
            'title' => 'Air Max', 'sku' => 'AM-1', 'tax' => 'GST_18', 'unit' => 'pcs',
            'mrp' => '9999', 'base_price' => '7499', 'product_type' => 'simple', 'gst_type' => 'inclusive',
            'manufacturer' => 'Nike Inc', 'country_of_origin' => 'Vietnam', 'short_description' => 'Iconic runner',
        ];
        $r = ImportService::validateProductRow($row, $this->ctx());

        $this->assertTrue($r['ok']);
        $this->assertSame(7, $r['data']['brand_id']);
        $this->assertSame(12, $r['data']['hsn_sac_id']);
        $this->assertSame('simple', $r['data']['product_type']);
        $this->assertSame('Nike Inc', $r['data']['manufacturer']);
        $this->assertSame('Iconic runner', $r['data']['short_description']);
    }

    public function testImportRejectsUnknownBrandAndHsn(): void
    {
        $base = ['vendor' => 'Sole Mate', 'category' => 'mens-shoes', 'title' => 'X', 'sku' => 'B-1', 'tax' => 'GST_18', 'unit' => 'pcs'];

        $r1 = ImportService::validateProductRow($base + ['brand' => 'puma'], $this->ctx());
        $this->assertFalse($r1['ok']);
        $this->assertStringContainsString('Unknown brand', implode(' ', $r1['errors']));

        $r2 = ImportService::validateProductRow($base + ['hsn' => '9999'], $this->ctx());
        $this->assertFalse($r2['ok']);
        $this->assertStringContainsString('Unknown HSN', implode(' ', $r2['errors']));
    }

    public function testImportBrandAndHsnAreOptional(): void
    {
        // no brand / hsn columns → still valid, ids null
        $row = ['vendor' => 'Sole Mate', 'category' => 'mens-shoes', 'title' => 'Plain', 'sku' => 'PL-1', 'tax' => 'GST_18', 'unit' => 'pcs'];
        $r   = ImportService::validateProductRow($row, $this->ctx());

        $this->assertTrue($r['ok']);
        $this->assertNull($r['data']['brand_id']);
        $this->assertNull($r['data']['hsn_sac_id']);
    }

    public function testImportProductRowValid(): void
    {
        $row = ['vendor' => 'Sole Mate', 'category' => 'mens-shoes', 'title' => 'Loafers', 'sku' => 'LF-1', 'tax' => 'GST_18', 'unit' => 'pcs', 'mrp' => '2999', 'base_price' => '2499'];
        $r   = ImportService::validateProductRow($row, $this->ctx());
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['data']['vendor_id']);
        $this->assertSame(10, $r['data']['category_id']);
    }

    public function testImportRejectsCategoryNotAllowedForVendor(): void
    {
        $row = ['vendor' => 'Sole Mate', 'category' => 'rice', 'title' => 'X', 'sku' => 'Z-1', 'tax' => 'GST_18', 'unit' => 'pcs'];
        $r   = ImportService::validateProductRow($row, $this->ctx());
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('not allowed', implode(' ', $r['errors']));
    }

    public function testImportRejectsUnknownVendorAndDupSku(): void
    {
        $r1 = ImportService::validateProductRow(['vendor' => 'ghost', 'category' => 'mens-shoes', 'title' => 'X', 'sku' => 'A', 'tax' => 'GST_18', 'unit' => 'pcs'], $this->ctx());
        $this->assertFalse($r1['ok']);
        $this->assertStringContainsString('Unknown vendor', implode(' ', $r1['errors']));

        $r2 = ImportService::validateProductRow(['vendor' => 'Sole Mate', 'category' => 'mens-shoes', 'title' => 'X', 'sku' => 'existing-sku', 'tax' => 'GST_18', 'unit' => 'pcs'], $this->ctx());
        $this->assertFalse($r2['ok']);
        $this->assertStringContainsString('Duplicate SKU', implode(' ', $r2['errors']));
    }

    public function testImportRejectsMissingFields(): void
    {
        $r = ImportService::validateProductRow(['vendor' => 'Sole Mate'], $this->ctx());
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Missing', implode(' ', $r['errors']));
    }
}
