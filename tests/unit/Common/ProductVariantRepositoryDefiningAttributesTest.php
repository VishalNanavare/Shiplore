<?php

declare(strict_types=1);

use App\Models\ProductVariantRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Phase A2.1 — definingAttributes() must never fall back to the full platform
 * attribute list. A category with zero category_attributes rows has variant
 * governance that simply isn't configured yet; showing every variant-defining
 * attribute on the platform (87+ of them, including junk) is the exact bug the
 * user's screenshot of the Variants page showed.
 */
final class ProductVariantRepositoryDefiningAttributesTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureCategoriesTable();
        $this->ensureCategoryAttributesTable();
        $this->ensureAttributesTable();
        $this->ensureAttributeValuesTable();

        $db = Database::connect();
        $db->table('categories')->where('id', 68001)->delete();
        $db->table('categories')->insert(['id' => 68001, 'name' => 'Women Clothes', 'slug' => 'women-clothes', 'path' => '/68001/', 'level' => 0]);
        $db->table('categories')->where('id', 68002)->delete();
        $db->table('categories')->insert(['id' => 68002, 'name' => 'Unmapped Category', 'slug' => 'unmapped', 'path' => '/68002/', 'level' => 0]);

        $db->table('attributes')->where('id', 6001)->delete();
        $db->table('attributes')->insert(['id' => 6001, 'code' => 'color', 'name' => 'Color', 'type' => 'select', 'is_variant_defining' => 1, 'status' => 'active']);
        $db->table('attributes')->where('id', 6002)->delete();
        $db->table('attributes')->insert(['id' => 6002, 'code' => 'junk', 'name' => 'patel', 'type' => 'text', 'is_variant_defining' => 1, 'status' => 'active']);

        $db->table('category_attributes')->where('category_id', 68001)->delete();
        $db->table('category_attributes')->insert(['category_id' => 68001, 'attribute_id' => 6001]);
    }

    public function testReturnsOnlyMappedAttributesForAConfiguredCategory(): void
    {
        $repo = new ProductVariantRepository();
        $rows = $repo->definingAttributes(68001);

        $this->assertCount(1, $rows);
        $this->assertSame('Color', $rows[0]['name']);
    }

    public function testReturnsEmptyForAnUnconfiguredCategoryNotTheFullPlatformList(): void
    {
        $repo = new ProductVariantRepository();
        $rows = $repo->definingAttributes(68002);

        $this->assertSame([], $rows);
    }
}
