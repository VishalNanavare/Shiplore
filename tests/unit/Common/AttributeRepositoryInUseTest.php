<?php

declare(strict_types=1);

use App\Models\AttributeRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Phase A1.2 — the guard behind attribute Deactivate/Delete: an attribute must not
 * be deactivated or deleted while a published product still references it, either as
 * a non-variant spec value (product_attribute_values) or via a variant-defining value
 * (variant_attribute_values).
 */
final class AttributeRepositoryInUseTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAttributesTable();
        $this->ensureAttributeValuesTable();
        $this->ensureProductsTable();
        $this->ensureProductVariantsTable();
        $this->ensureProductAttributeValuesTable();
        $this->ensureVariantAttributeValuesTable();
    }

    public function testUnusedAttributeReturnsEmpty(): void
    {
        $db = Database::connect();
        $db->table('attributes')->insert([
            'id' => 501, 'code' => 'unused_attr', 'name' => 'Unused Attr',
            'type' => 'text', 'is_variant_defining' => 0, 'status' => 'active',
        ]);

        $repo = new AttributeRepository();
        $this->assertSame([], $repo->inUseBy(501));
    }

    public function testAttributeUsedAsProductSpecOnPublishedProductIsBlocked(): void
    {
        $db = Database::connect();
        $db->table('attributes')->insert([
            'id' => 502, 'code' => 'used_spec_attr', 'name' => 'Used Spec Attr',
            'type' => 'text', 'is_variant_defining' => 0, 'status' => 'active',
        ]);
        $db->table('products')->insert([
            'id' => 601, 'vendor_id' => 1, 'title' => 'Live Product', 'status' => 'published', 'is_online_enabled' => 0,
        ]);
        $db->table('product_attribute_values')->insert([
            'product_id' => 601, 'attribute_id' => 502, 'value_text' => 'some spec value',
        ]);

        $repo = new AttributeRepository();
        $this->assertSame(['Live Product'], $repo->inUseBy(502));
    }

    public function testAttributeUsedOnlyByDraftProductIsNotBlocked(): void
    {
        $db = Database::connect();
        $db->table('attributes')->insert([
            'id' => 503, 'code' => 'draft_only_attr', 'name' => 'Draft Only Attr',
            'type' => 'text', 'is_variant_defining' => 0, 'status' => 'active',
        ]);
        $db->table('products')->insert([
            'id' => 602, 'vendor_id' => 1, 'title' => 'Draft Product', 'status' => 'draft',
        ]);
        $db->table('product_attribute_values')->insert([
            'product_id' => 602, 'attribute_id' => 503, 'value_text' => 'draft spec value',
        ]);

        $repo = new AttributeRepository();
        $this->assertSame([], $repo->inUseBy(503));
    }

    public function testAttributeUsedAsVariantValueOnPublishedProductIsBlocked(): void
    {
        $db = Database::connect();
        $db->table('attributes')->insert([
            'id' => 504, 'code' => 'used_variant_attr', 'name' => 'Used Variant Attr',
            'type' => 'select', 'is_variant_defining' => 1, 'status' => 'active',
        ]);
        $db->table('attribute_values')->insert([
            'id' => 504001, 'attribute_id' => 504, 'value' => 'Red',
        ]);
        $db->table('products')->insert([
            'id' => 603, 'vendor_id' => 1, 'title' => 'Variant Product', 'status' => 'published', 'is_online_enabled' => 0,
        ]);
        $db->table('product_variants')->insert([
            'id' => 701, 'product_id' => 603, 'vendor_id' => 1, 'sku' => 'VP-RED',
            'mrp' => '100.00', 'base_price' => '90.00', 'status' => 'active',
        ]);
        $db->table('variant_attribute_values')->insert([
            'variant_id' => 701, 'attribute_id' => 504, 'attribute_value_id' => 504001,
        ]);

        $repo = new AttributeRepository();
        $this->assertSame(['Variant Product'], $repo->inUseBy(504));
    }
}
