<?php

declare(strict_types=1);

use App\Models\ProductVariantRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Phase A3.1 — the variant builder must reflect a product's EXISTING variant
 * state on load (pre-checked attribute toggles, pre-populated values) instead of
 * always starting from a blank slate. This is the regression test for the exact
 * bug this session's screenshot showed: a product with existing Color variants,
 * revisited, showed every toggle unchecked and every picker empty.
 */
final class ProductVariantRepositoryExistingSelectionsTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAttributesTable();
        $this->ensureAttributeValuesTable();
        $this->ensureProductsTable();
        $this->ensureProductVariantsTable();
        $this->ensureVariantAttributeValuesTable();

        $db = Database::connect();
        $db->table('attributes')->where('id', 6201)->delete();
        $db->table('attributes')->insert(['id' => 6201, 'code' => 'color', 'name' => 'Color', 'type' => 'select', 'is_variant_defining' => 1, 'status' => 'active']);
        $db->table('attribute_values')->where('attribute_id', 6201)->delete();
        $db->table('attribute_values')->insertBatch([
            ['id' => 9201, 'attribute_id' => 6201, 'value' => 'Red'],
            ['id' => 9202, 'attribute_id' => 6201, 'value' => 'Blue'],
        ]);

        // is_online_enabled=0 keeps this out of unrelated storefront-visibility queries
        // (StoreCatalogRepository's computeCount() has no category/vendor scope at all)
        // while still satisfying this repository's own status='published'-independent read.
        $db->table('products')->where('id', 7201)->delete();
        $db->table('products')->insert(['id' => 7201, 'vendor_id' => 1, 'title' => 'Existing Top', 'status' => 'published', 'is_online_enabled' => 0]);

        $db->table('product_variants')->where('product_id', 7201)->delete();
        $db->table('product_variants')->insertBatch([
            ['id' => 8201, 'product_id' => 7201, 'vendor_id' => 1, 'sku' => 'ET-RED', 'mrp' => '10', 'base_price' => '9', 'status' => 'active'],
            ['id' => 8202, 'product_id' => 7201, 'vendor_id' => 1, 'sku' => 'ET-BLUE', 'mrp' => '10', 'base_price' => '9', 'status' => 'active'],
        ]);
        $db->table('variant_attribute_values')->whereIn('variant_id', [8201, 8202])->delete();
        $db->table('variant_attribute_values')->insertBatch([
            ['variant_id' => 8201, 'attribute_id' => 6201, 'attribute_value_id' => 9201],
            ['variant_id' => 8202, 'attribute_id' => 6201, 'attribute_value_id' => 9202],
        ]);
    }

    public function testReturnsDistinctExistingValuesGroupedByAttribute(): void
    {
        $repo = new ProductVariantRepository();
        $sel  = $repo->existingAttributeSelections(7201);

        $this->assertArrayHasKey(6201, $sel);
        $ids = array_column($sel[6201], 'id');
        sort($ids);
        $this->assertSame([9201, 9202], $ids);
        $texts = array_column($sel[6201], 'text');
        sort($texts);
        $this->assertSame(['Blue', 'Red'], $texts);
    }

    public function testEmptyForProductWithNoVariantsYet(): void
    {
        $repo = new ProductVariantRepository();

        $this->assertSame([], $repo->existingAttributeSelections(999999));
    }
}
