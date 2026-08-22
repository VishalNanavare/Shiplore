<?php

declare(strict_types=1);

use App\Models\CategoryRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Phase A2.3 — bootstrap inference: which attributes does a category's real
 * (published) product usage suggest, so an admin can review them into a real
 * category_attributes mapping via the existing A2.2 screen before Track C's wipe
 * deletes this historical usage data entirely.
 *
 * Scoped to published products only — a draft/rejected product's attribute usage
 * is exactly the kind of unreviewed noise (junk attributes like "patel") this
 * whole initiative exists to stop reintroducing.
 */
final class CategoryRepositoryInferredAttributesTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureCategoriesTable();
        $this->ensureAttributesTable();
        $this->ensureProductsTable();
        $this->ensureProductVariantsTable();
        $this->ensureProductAttributeValuesTable();
        $this->ensureVariantAttributeValuesTable();

        $db = Database::connect();
        $db->table('categories')->where('id', 69101)->delete();
        $db->table('categories')->insert(['id' => 69101, 'name' => 'Short Top', 'slug' => 'short-top', 'path' => '/69101/', 'level' => 1]);

        foreach ([6101 => 'Color', 6102 => 'Size', 6103 => 'patel'] as $id => $name) {
            $db->table('attributes')->where('id', $id)->delete();
            $db->table('attributes')->insert(['id' => $id, 'code' => strtolower($name), 'name' => $name, 'type' => 'select', 'is_variant_defining' => 1, 'status' => 'active']);
        }

        // Published product using Color (spec) — should be inferred. is_online_enabled=0
        // keeps this out of unrelated storefront-visibility queries (StoreCatalogRepository's
        // computeCount() has no category/vendor scope at all) while still satisfying this
        // test's own status='published' bar for "real usage".
        $db->table('products')->where('id', 7101)->delete();
        $db->table('products')->insert(['id' => 7101, 'vendor_id' => 1, 'category_id' => 69101, 'title' => 'Live Top', 'status' => 'published', 'is_online_enabled' => 0]);
        $db->table('product_attribute_values')->where('product_id', 7101)->delete();
        $db->table('product_attribute_values')->insert(['product_id' => 7101, 'attribute_id' => 6101, 'attribute_value_id' => 1]);

        // Same published product's variant using Size — should be inferred.
        $db->table('product_variants')->where('id', 8101)->delete();
        $db->table('product_variants')->insert(['id' => 8101, 'product_id' => 7101, 'vendor_id' => 1, 'sku' => 'LT-1', 'mrp' => '10', 'base_price' => '9', 'status' => 'active']);
        $db->table('variant_attribute_values')->where('variant_id', 8101)->delete();
        $db->table('variant_attribute_values')->insert(['variant_id' => 8101, 'attribute_id' => 6102, 'attribute_value_id' => 1]);

        // Draft (unpublished) product using the junk attribute — must NOT be inferred.
        $db->table('products')->where('id', 7102)->delete();
        $db->table('products')->insert(['id' => 7102, 'vendor_id' => 1, 'category_id' => 69101, 'title' => 'Draft Top', 'status' => 'draft']);
        $db->table('product_attribute_values')->where('product_id', 7102)->delete();
        $db->table('product_attribute_values')->insert(['product_id' => 7102, 'attribute_id' => 6103, 'attribute_value_id' => 1]);
    }

    public function testInfersAttributesFromPublishedProductsSpecAndVariantUsage(): void
    {
        $repo = new CategoryRepository();
        $ids  = $repo->inferredAttributeIds(69101);

        sort($ids);
        $this->assertSame([6101, 6102], $ids);
    }

    public function testDoesNotInferFromDraftProductUsage(): void
    {
        $repo = new CategoryRepository();
        $ids  = $repo->inferredAttributeIds(69101);

        $this->assertNotContains(6103, $ids);
    }
}
