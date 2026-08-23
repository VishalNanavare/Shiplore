<?php

declare(strict_types=1);

use App\Models\StoreCatalogRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * StoreCatalogRepository — resolving a category filter to its whole SUBTREE.
 *
 * CategoryRepository::create() writes `categories.path` as a materialised path of
 * ids that ALREADY ends in a slash — `/<parent ids>/<own id>/` (see its closing
 * UPDATE). The subtree lookup built its "everything under this category" pattern
 * by appending ANOTHER slash, producing `/5301//%`, which can never match a
 * child's `/5301/5302/`. So a parent-category filter collapsed to the parent's own
 * id, and because products hang off LEAF categories the storefront returned zero
 * products for it — while the sidebar counts, which roll up through parent_id
 * rather than path, kept showing the real totals. That split is exactly how the
 * bug presented: "Women's Clothing 620" beside "0 products".
 *
 * Both path conventions in this codebase are covered: the id-with-trailing-slash
 * form written by CategoryRepository::create(), and the older slug form stored
 * without a trailing slash (see StoreCatalogRepositoryVendorStatusTest's
 * `'path' => 'snacks'`), which must keep working.
 *
 * Exercised through the private resolver by reflection, the same way
 * CatalogIndexHintTest covers categoryIndexHint(): it isolates the defect, and it
 * avoids running the full product query, whose `p.` aliases CI4 mis-prefixes under
 * the test database's `db_` DBPrefix (production sets no prefix).
 */
final class StoreCatalogRepositoryCategorySubtreeTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureCategoriesTable();

        $db = Database::connect();
        // Id-based paths exactly as CategoryRepository::create() writes them.
        $db->table('categories')->insert(['id' => 5301, 'parent_id' => null, 'name' => "Women's Clothing", 'slug' => 'womens-clothing', 'path' => '/5301/', 'level' => 0]);
        $db->table('categories')->insert(['id' => 5302, 'parent_id' => 5301, 'name' => 'Dresses', 'slug' => 'dresses', 'path' => '/5301/5302/', 'level' => 1]);
        $db->table('categories')->insert(['id' => 5303, 'parent_id' => 5301, 'name' => 'Tops & Tees', 'slug' => 'tops-tees', 'path' => '/5301/5303/', 'level' => 1]);
        // A separate root whose id shares the parent's digits, guarding against a
        // prefix match that ignores the slash boundary (`/5301` vs `/53010`).
        $db->table('categories')->insert(['id' => 53010, 'parent_id' => null, 'name' => 'Unrelated', 'slug' => 'unrelated', 'path' => '/53010/', 'level' => 0]);

        // Slug-based path without a trailing slash — the older convention.
        $db->table('categories')->insert(['id' => 5311, 'parent_id' => null, 'name' => 'Snacks', 'slug' => 'snacks', 'path' => 'snacks', 'level' => 0]);
        $db->table('categories')->insert(['id' => 5312, 'parent_id' => 5311, 'name' => 'Chips', 'slug' => 'chips', 'path' => 'snacks/chips', 'level' => 1]);
    }

    protected function tearDown(): void
    {
        Database::connect()->table('categories')->whereIn('id', [5301, 5302, 5303, 53010, 5311, 5312])->delete();
        Services::reset();
        parent::tearDown();
    }

    /**
     * @param array<string,mixed> $cat
     * @return list<int>
     */
    private function subtreeOf(array $cat): array
    {
        $m = new ReflectionMethod(StoreCatalogRepository::class, 'categorySubtreeIds');
        $m->setAccessible(true);
        $ids = $m->invoke(new StoreCatalogRepository(), $cat);
        sort($ids);

        return $ids;
    }

    public function testAParentCategoryResolvesToItselfAndAllItsChildren(): void
    {
        $this->assertSame(
            [5301, 5302, 5303],
            $this->subtreeOf(['id' => 5301, 'path' => '/5301/']),
            'products hang off the leaf categories, so the children must be included',
        );
    }

    public function testALeafCategoryResolvesToJustItself(): void
    {
        $this->assertSame([5302], $this->subtreeOf(['id' => 5302, 'path' => '/5301/5302/']));
    }

    public function testASiblingRootSharingLeadingDigitsIsNotSweptIn(): void
    {
        $this->assertNotContains(53010, $this->subtreeOf(['id' => 5301, 'path' => '/5301/']));
    }

    public function testSlugStylePathsWithoutATrailingSlashStillResolveChildren(): void
    {
        $this->assertSame(
            [5311, 5312],
            $this->subtreeOf(['id' => 5311, 'path' => 'snacks']),
            'the older slug-path convention must keep working',
        );
    }

    public function testACategoryWithNoPathResolvesToItsOwnId(): void
    {
        $this->assertSame([5301], $this->subtreeOf(['id' => 5301, 'path' => '']));
    }
}
