<?php

declare(strict_types=1);

use App\Models\CatalogLookupRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Phase A2.1 — the "custom" (non-variant-defining/specs) side of
 * attributesForCategory() had its own copy of the unsafe fallback-to-everything
 * bug, independent of ProductVariantRepository::definingAttributes().
 */
final class CatalogLookupRepositoryAttributesForCategoryTest extends CIUnitTestCase
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

        $db->table('attributes')->where('id', 6003)->delete();
        $db->table('attributes')->insert(['id' => 6003, 'code' => 'material', 'name' => 'Material', 'type' => 'text', 'is_variant_defining' => 0, 'status' => 'active']);
        $db->table('attributes')->where('id', 6004)->delete();
        $db->table('attributes')->insert(['id' => 6004, 'code' => 'junk', 'name' => 'patel', 'type' => 'text', 'is_variant_defining' => 0, 'status' => 'active']);

        $db->table('category_attributes')->where('category_id', 68001)->delete();
        $db->table('category_attributes')->insert(['category_id' => 68001, 'attribute_id' => 6003]);

        Services::injectMock('productVariantRepository', new class {
            public function definingAttributes(int $categoryId): array { return []; }
        });
    }

    public function testCustomAttributesRestrictedToMappedForConfiguredCategory(): void
    {
        $repo = new CatalogLookupRepository();
        $result = $repo->attributesForCategory(68001);

        $this->assertCount(1, $result['custom']);
        $this->assertSame('Material', $result['custom'][0]['name']);
    }

    public function testCustomAttributesEmptyForUnconfiguredCategoryNotTheFullPlatformList(): void
    {
        $repo = new CatalogLookupRepository();
        $result = $repo->attributesForCategory(68002);

        $this->assertSame([], $result['custom']);
    }
}
