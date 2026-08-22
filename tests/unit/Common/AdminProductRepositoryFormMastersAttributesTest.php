<?php

declare(strict_types=1);

use App\Models\AdminProductRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Phase A2.1 — formMasters()['attributes'] fed the specs tab with EVERY
 * non-variant-defining attribute on the platform, unconditionally, regardless of
 * category. Must become category-scoped: mapped-only when a category is known,
 * empty (never the full list) when the category has no mapping or is unknown.
 */
final class AdminProductRepositoryFormMastersAttributesTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureCategoriesTable();
        $this->ensureCategoryAttributesTable();
        $this->ensureAttributesTable();
        $this->ensureTaxClassesTable();
        $this->ensureUnitsTable();
        $this->ensureBrandsTable();
        $this->ensureProductLabelsTable();

        $db = Database::connect();
        $db->table('categories')->where('id', 68001)->delete();
        $db->table('categories')->insert(['id' => 68001, 'name' => 'Women Clothes', 'slug' => 'women-clothes', 'path' => '/68001/', 'level' => 0]);
        $db->table('categories')->where('id', 68002)->delete();
        $db->table('categories')->insert(['id' => 68002, 'name' => 'Unmapped Category', 'slug' => 'unmapped', 'path' => '/68002/', 'level' => 0]);

        $db->table('attributes')->where('id', 6003)->delete();
        $db->table('attributes')->insert(['id' => 6003, 'code' => 'material', 'name' => 'Material', 'type' => 'text', 'is_variant_defining' => 0, 'status' => 'active']);
        $db->table('attributes')->where('id', 6004)->delete();
        $db->table('attributes')->insert(['id' => 6004, 'code' => 'junk', 'name' => 'patel', 'type' => 'text', 'is_variant_defining' => 0, 'status' => 'active']);

        $db->table('category_attributes')->where('category_id', 68001)->delete();
        $db->table('category_attributes')->insert(['category_id' => 68001, 'attribute_id' => 6003]);
    }

    public function testAttributesRestrictedToMappedForKnownCategory(): void
    {
        $repo = new AdminProductRepository();
        $masters = $repo->formMasters(68001);

        $this->assertCount(1, $masters['attributes']);
        $this->assertSame('Material', $masters['attributes'][0]['name']);
    }

    public function testAttributesEmptyForUnconfiguredCategoryNotTheFullPlatformList(): void
    {
        $repo = new AdminProductRepository();
        $masters = $repo->formMasters(68002);

        $this->assertSame([], $masters['attributes']);
    }

    public function testAttributesEmptyWhenNoCategoryKnownYet(): void
    {
        $repo = new AdminProductRepository();
        $masters = $repo->formMasters(null);

        $this->assertSame([], $masters['attributes']);
    }
}
