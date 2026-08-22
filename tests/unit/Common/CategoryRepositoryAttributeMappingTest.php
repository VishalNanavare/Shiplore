<?php

declare(strict_types=1);

use App\Models\CategoryRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Phase A2.2 — the admin screen that populates category_attributes, the mapping
 * A2.1's fix now strictly depends on (an unmapped category renders empty, so a
 * category can only ever show attributes an admin has actually mapped here).
 */
final class CategoryRepositoryAttributeMappingTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureCategoriesTable();
        $this->ensureCategoryAttributesTable();
        $this->ensureAttributesTable();

        $db = Database::connect();
        $db->table('categories')->where('id', 69001)->delete();
        $db->table('categories')->insert(['id' => 69001, 'name' => 'Short Top', 'slug' => 'short-top', 'path' => '/69001/', 'level' => 1]);

        $db->table('attributes')->where('id', 6011)->delete();
        $db->table('attributes')->insert(['id' => 6011, 'code' => 'color', 'name' => 'Color', 'type' => 'select', 'is_variant_defining' => 1, 'status' => 'active']);
        $db->table('attributes')->where('id', 6012)->delete();
        $db->table('attributes')->insert(['id' => 6012, 'code' => 'size', 'name' => 'Size', 'type' => 'select', 'is_variant_defining' => 1, 'status' => 'active']);
        $db->table('attributes')->where('id', 6013)->delete();
        $db->table('attributes')->insert(['id' => 6013, 'code' => 'material', 'name' => 'Material', 'type' => 'text', 'is_variant_defining' => 0, 'status' => 'active']);

        $db->table('category_attributes')->where('category_id', 69001)->delete();
    }

    public function testMappedAttributeIdsEmptyForUnmappedCategory(): void
    {
        $repo = new CategoryRepository();

        $this->assertSame([], $repo->mappedAttributeIds(69001));
    }

    public function testSetAttributeMappingThenMappedAttributeIdsReflectsIt(): void
    {
        $repo = new CategoryRepository();
        $repo->setAttributeMapping(69001, [6011, 6012]);

        $this->assertSame([6011, 6012], $repo->mappedAttributeIds(69001));
    }

    public function testSetAttributeMappingReplacesThePreviousSetEntirely(): void
    {
        $repo = new CategoryRepository();
        $repo->setAttributeMapping(69001, [6011, 6012, 6013]);
        $repo->setAttributeMapping(69001, [6013]);

        $this->assertSame([6013], $repo->mappedAttributeIds(69001));
    }

    public function testSetAttributeMappingToEmptyClearsIt(): void
    {
        $repo = new CategoryRepository();
        $repo->setAttributeMapping(69001, [6011]);
        $repo->setAttributeMapping(69001, []);

        $this->assertSame([], $repo->mappedAttributeIds(69001));
    }
}
