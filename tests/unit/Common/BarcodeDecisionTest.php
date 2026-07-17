<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Catalog/BarcodeDecision.php';

use App\Libraries\Catalog\BarcodeDecision;

/** X5 — POS quick-create decision engine (doc 44 §3.2, edge cases E1–E12). */
final class BarcodeDecisionTest extends TestCase
{
    private const EXISTING = ['mrp' => '60.00', 'gst_rate' => '12.00', 'pack_size' => '750ml', 'is_combo' => false, 'status' => 'active'];

    private function scan(array $over = []): array
    {
        return $over + ['barcode' => '8901030865275', 'mrp' => '60.00', 'gst_rate' => '12.00', 'pack_size' => '750ml'];
    }

    public function testE1ExactMatchUpdatesStock(): void
    {
        $this->assertSame('update_stock', BarcodeDecision::decide($this->scan(), self::EXISTING)['action']);
    }

    public function testE2MrpDiffersCreatesNewVariant(): void
    {
        $r = BarcodeDecision::decide($this->scan(['mrp' => '65.00']), self::EXISTING);

        $this->assertSame('new_variant', $r['action']);
        $this->assertStringContainsString('MRP', $r['reason']);
    }

    public function testE3OnlyGstDiffersRoutesToRateChangeRequest(): void
    {
        // a govt rate revision is NOT a new physical product
        $r = BarcodeDecision::decide($this->scan(['gst_rate' => '18.00']), self::EXISTING);

        $this->assertSame('gst_change_request', $r['action']);
    }

    public function testE4PackSizeDiffersCreatesNewVariant(): void
    {
        $this->assertSame('new_variant', BarcodeDecision::decide($this->scan(['pack_size' => '1.25L']), self::EXISTING)['action']);
    }

    public function testE5UnknownToThisVendorCreatesProduct(): void
    {
        $this->assertSame('new_product', BarcodeDecision::decide($this->scan(), null)['action']);
    }

    public function testE6ComboBarcodeRejected(): void
    {
        $this->assertSame('reject_combo', BarcodeDecision::decide($this->scan(), ['is_combo' => true] + self::EXISTING)['action']);
    }

    public function testE7EmptyBarcodeStillCreatesWithAutoCode(): void
    {
        $r = BarcodeDecision::decide($this->scan(['barcode' => '']), null);

        $this->assertSame('new_product', $r['action']);
        $this->assertStringContainsString('auto', $r['reason']);
    }

    public function testE8ChecksumStrictRejects(): void
    {
        $bad = $this->scan(['barcode' => '8901030865270']); // wrong check digit

        $this->assertSame('reject_invalid', BarcodeDecision::decide($bad, null, true)['action']);
        $this->assertSame('new_product', BarcodeDecision::decide($bad, null, false)['action']); // tolerated → internal
    }

    public function testE9DiscontinuedVariantGetsFreshVariant(): void
    {
        $r = BarcodeDecision::decide($this->scan(), ['mrp' => '60.00', 'gst_rate' => '12.00', 'pack_size' => '750ml', 'is_combo' => false, 'status' => 'discontinued']);

        $this->assertSame('new_variant', $r['action']);
    }

    public function testE12WeightedScaleBarcodeIsNeverACreate(): void
    {
        $this->assertSame('weighted_item', BarcodeDecision::decide($this->scan(['barcode' => '2101234012345']), null)['action']);
    }

    public function testMrpAndPackBothDifferAreReportedTogether(): void
    {
        $r = BarcodeDecision::decide($this->scan(['mrp' => '99.00', 'pack_size' => '2L']), self::EXISTING);

        $this->assertSame('new_variant', $r['action']);
        $this->assertStringContainsString('MRP + pack size', $r['reason']);
    }

    public function testChecksumMath(): void
    {
        $this->assertTrue(BarcodeDecision::checksumOk('8901030865275'));  // valid EAN-13
        $this->assertFalse(BarcodeDecision::checksumOk('8901030865278'));
        $this->assertTrue(BarcodeDecision::checksumOk('036000291452'));   // valid UPC-A
    }
}
