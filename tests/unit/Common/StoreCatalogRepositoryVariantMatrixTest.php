<?php

declare(strict_types=1);

use App\Models\StoreCatalogRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Track C-ii — the cascading storefront variant picker. variantMatrix() is the data
 * source for it: variant-defining attributes in order (Color, then Size), and each
 * variant's exact attribute-value combination + whether it's currently in stock, so
 * the JS can filter Size options down to what's available for the chosen Color.
 */
final class StoreCatalogRepositoryVariantMatrixTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureProductsTable();
        $this->ensureProductVariantsTable();
        $this->ensureAttributesTable();
        $this->ensureAttributeValuesTable();
        $this->ensureVariantAttributeValuesTable();
        $this->ensureInventoryTable();

        $db = Database::connect();
        $db->table('products')->where('id', 9401)->delete();
        $db->table('products')->insert(['id' => 9401, 'vendor_id' => 1, 'title' => 'Cascading Top', 'status' => 'published', 'is_online_enabled' => 0, 'inventory_mode' => 'managed', 'backorder_enabled' => 0]);

        $db->table('attributes')->where('id', 9411)->delete();
        $db->table('attributes')->insert(['id' => 9411, 'code' => 'color', 'name' => 'Color', 'type' => 'select', 'is_variant_defining' => 1, 'status' => 'active']);
        $db->table('attributes')->where('id', 9412)->delete();
        $db->table('attributes')->insert(['id' => 9412, 'code' => 'size', 'name' => 'Size', 'type' => 'select', 'is_variant_defining' => 1, 'status' => 'active']);

        $db->table('attribute_values')->where('attribute_id', 9411)->delete();
        $db->table('attribute_values')->insertBatch([
            ['id' => 9421, 'attribute_id' => 9411, 'value' => 'Black', 'sort_order' => 0],
            ['id' => 9422, 'attribute_id' => 9411, 'value' => 'Purple', 'sort_order' => 1],
        ]);
        $db->table('attribute_values')->where('attribute_id', 9412)->delete();
        $db->table('attribute_values')->insertBatch([
            ['id' => 9431, 'attribute_id' => 9412, 'value' => 'S', 'sort_order' => 0],
            ['id' => 9432, 'attribute_id' => 9412, 'value' => 'M', 'sort_order' => 1],
            ['id' => 9433, 'attribute_id' => 9412, 'value' => 'L', 'sort_order' => 2],
        ]);

        // Black: S (in stock), M (out of stock). Purple: L only (in stock).
        $db->table('product_variants')->where('product_id', 9401)->delete();
        $db->table('product_variants')->insertBatch([
            ['id' => 9441, 'product_id' => 9401, 'vendor_id' => 1, 'sku' => 'CT-BLK-S', 'mrp' => '999', 'base_price' => '799', 'status' => 'active'],
            ['id' => 9442, 'product_id' => 9401, 'vendor_id' => 1, 'sku' => 'CT-BLK-M', 'mrp' => '999', 'base_price' => '799', 'status' => 'active'],
            ['id' => 9443, 'product_id' => 9401, 'vendor_id' => 1, 'sku' => 'CT-PUR-L', 'mrp' => '999', 'base_price' => '799', 'status' => 'active'],
        ]);
        $db->table('variant_attribute_values')->whereIn('variant_id', [9441, 9442, 9443])->delete();
        $db->table('variant_attribute_values')->insertBatch([
            ['variant_id' => 9441, 'attribute_id' => 9411, 'attribute_value_id' => 9421],
            ['variant_id' => 9441, 'attribute_id' => 9412, 'attribute_value_id' => 9431],
            ['variant_id' => 9442, 'attribute_id' => 9411, 'attribute_value_id' => 9421],
            ['variant_id' => 9442, 'attribute_id' => 9412, 'attribute_value_id' => 9432],
            ['variant_id' => 9443, 'attribute_id' => 9411, 'attribute_value_id' => 9422],
            ['variant_id' => 9443, 'attribute_id' => 9412, 'attribute_value_id' => 9433],
        ]);

        $db->table('inventory')->whereIn('variant_id', [9441, 9442, 9443])->delete();
        $db->table('inventory')->insertBatch([
            ['variant_id' => 9441, 'shop_id' => 1, 'on_hand' => '10', 'reserved' => '0', 'available' => '10'],
            ['variant_id' => 9442, 'shop_id' => 1, 'on_hand' => '0', 'reserved' => '0', 'available' => '0'],
            ['variant_id' => 9443, 'shop_id' => 1, 'on_hand' => '5', 'reserved' => '0', 'available' => '5'],
        ]);
    }

    public function testAttributesAreOrderedByName(): void
    {
        $repo   = new StoreCatalogRepository();
        $matrix = $repo->variantMatrix(9401);

        $names = array_column($matrix['attributes'], 'name');
        $this->assertSame(['Color', 'Size'], $names);
    }

    public function testEachAttributeListsOnlyItsActuallyUsedValues(): void
    {
        $repo   = new StoreCatalogRepository();
        $matrix = $repo->variantMatrix(9401);

        $color = $matrix['attributes'][0];
        $this->assertSame(['Black', 'Purple'], array_column($color['values'], 'value'));

        $size = $matrix['attributes'][1];
        $this->assertSame(['S', 'M', 'L'], array_column($size['values'], 'value'));
    }

    public function testEachVariantCarriesItsAttributeValueIdsAndStock(): void
    {
        $repo   = new StoreCatalogRepository();
        $matrix = $repo->variantMatrix(9401);

        $byId = [];
        foreach ($matrix['variants'] as $v) {
            $byId[$v['variant_id']] = $v;
        }

        $this->assertSame(['9411' => 9421, '9412' => 9431], $byId[9441]['attribute_value_ids']);
        $this->assertTrue($byId[9441]['in_stock']);

        $this->assertSame(['9411' => 9421, '9412' => 9432], $byId[9442]['attribute_value_ids']);
        $this->assertFalse($byId[9442]['in_stock']);

        $this->assertSame(['9411' => 9422, '9412' => 9433], $byId[9443]['attribute_value_ids']);
        $this->assertTrue($byId[9443]['in_stock']);
    }
}
