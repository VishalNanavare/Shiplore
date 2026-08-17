<?php

declare(strict_types=1);

use App\Models\ManufacturerProductRepository;
use CodeIgniter\Test\CIUnitTestCase;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * The product repository's SQL predicates, against real tables.
 *
 * These exist because the controller tests mock this repository, and a mock cannot
 * verify a WHERE clause. Every rule below is enforced by SQL and nothing else:
 * tenant scoping on delete/restore/trash, the draft-only restriction, and the unit
 * scoping that keeps a store keeper out of another unit's trash. A mutation run
 * confirmed all six were unprotected before this file existed.
 */
final class ManufacturerProductRepositoryTest extends CIUnitTestCase
{
    use MinimalSchema;

    private const MINE   = 1;
    private const THEIRS = 2;

    private ManufacturerProductRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->schemaConn();
        $db->query('CREATE TABLE IF NOT EXISTS db_products (
            id INTEGER PRIMARY KEY AUTOINCREMENT, vendor_id INTEGER NOT NULL, title TEXT, slug TEXT,
            category_id INTEGER, status TEXT NOT NULL DEFAULT "draft",
            created_at TEXT, updated_by INTEGER, deleted_at TEXT
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_product_variants (
            id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, sku TEXT,
            is_default INTEGER NOT NULL DEFAULT 0, making_price REAL, base_price REAL, deleted_at TEXT
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_product_mshops (
            id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, mshop_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "active", UNIQUE(product_id, mshop_id)
        )');
        // MinimalSchema owns the categories definition. Declaring a narrower one here
        // would be skipped whenever another file created the table first, and the
        // INSERT below would then fail on a NOT NULL column this file never declared.
        $this->ensureCategoriesTable();
        $db->query('CREATE TABLE IF NOT EXISTS db_media_assets (
            id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_product_media (
            id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, media_id INTEGER, sort_order INTEGER
        )');

        foreach (['products', 'product_variants', 'product_mshops', 'categories', 'media_assets', 'product_media'] as $t) {
            $db->table($t)->truncate();
        }

        $db->query('INSERT INTO db_categories (id, name, slug, path) VALUES (10, ?, ?, ?)', ['Fasteners', 'fasteners', '/10/']);

        // 77 = ours, live draft at unit 11.  88 = ours, trashed draft at unit 11.
        // 99 = ANOTHER manufacturer's trashed draft, also at "unit 11".
        $db->query('INSERT INTO db_products (id, vendor_id, title, category_id, status) VALUES (77, ?, ?, 10, ?)', [self::MINE, 'M8 Bolt', 'draft']);
        $db->query('INSERT INTO db_products (id, vendor_id, title, category_id, status, deleted_at) VALUES (88, ?, ?, 10, ?, ?)', [self::MINE, 'Old Draft', 'draft', '2026-08-01 10:00:00']);
        $db->query('INSERT INTO db_products (id, vendor_id, title, category_id, status, deleted_at) VALUES (99, ?, ?, 10, ?, ?)', [self::THEIRS, 'Their Draft', 'draft', '2026-08-01 10:00:00']);
        $db->query('INSERT INTO db_products (id, vendor_id, title, category_id, status) VALUES (66, ?, ?, 10, ?)', [self::MINE, 'Live Item', 'published']);

        foreach ([[77, 'B-1'], [88, 'B-2'], [99, 'B-3'], [66, 'B-4']] as [$pid, $sku]) {
            $db->query('INSERT INTO db_product_variants (product_id, sku, is_default, making_price, base_price) VALUES (?, ?, 1, 40.0, 60.0)', [$pid, $sku]);
        }
        foreach ([[77, 11], [88, 11], [99, 11], [66, 12]] as [$pid, $unit]) {
            $db->query('INSERT INTO db_product_mshops (product_id, mshop_id, status) VALUES (?, ?, ?)', [$pid, $unit, 'active']);
        }

        $this->repo = new ManufacturerProductRepository();
    }

    protected function tearDown(): void
    {
        foreach (['db_products', 'db_product_variants', 'db_product_mshops', 'db_categories',
            'db_media_assets', 'db_product_media'] as $t) {
            $this->schemaConn()->query('DROP TABLE IF EXISTS ' . $t);
        }
        parent::tearDown();
    }

    private function statusOf(int $id): ?string
    {
        $row = $this->schemaConn()->table('products')->select('deleted_at')->where('id', $id)->get()->getRowArray();

        return $row === null ? null : ($row['deleted_at'] === null ? 'live' : 'trashed');
    }

    // ------------------------------------------------------------------- trash list

    public function testTrashShowsOnlyThisManufacturersDeletedDrafts(): void
    {
        $rows = $this->repo->listTrashed(self::MINE);

        $this->assertCount(1, $rows);
        $this->assertSame(88, (int) $rows[0]['id'], "another manufacturer's trash must never appear");
    }

    public function testTrashIsUnitScopedForStaff(): void
    {
        $this->assertCount(1, $this->repo->listTrashed(self::MINE, 11));
        $this->assertSame([], $this->repo->listTrashed(self::MINE, 12), 'unit 12 has no trashed drafts');
    }

    public function testTrashNeverContainsLiveProducts(): void
    {
        foreach ($this->repo->listTrashed(self::MINE) as $row) {
            $this->assertNotSame(77, (int) $row['id']);
            $this->assertNotSame(66, (int) $row['id']);
        }
    }

    // ---------------------------------------------------------------- delete/restore

    public function testDeletingOwnDraftWorks(): void
    {
        $this->assertTrue($this->repo->softDeleteDraft(77, self::MINE, 9));
        $this->assertSame('trashed', $this->statusOf(77));
    }

    /** The tenant boundary: another manufacturer's product must be untouchable. */
    public function testCannotDeleteAnotherManufacturersProduct(): void
    {
        $this->schemaConn()->table('products')->where('id', 99)->update(['deleted_at' => null]);

        $this->assertFalse($this->repo->softDeleteDraft(99, self::MINE, 9));
        $this->assertSame('live', $this->statusOf(99), "another tenant's product must not be deleted");
    }

    /** Only drafts are deletable — a published product must survive. */
    public function testCannotDeleteALiveProduct(): void
    {
        $this->assertFalse($this->repo->softDeleteDraft(66, self::MINE, 9));
        $this->assertSame('live', $this->statusOf(66));
    }

    public function testRestoringOwnDraftWorks(): void
    {
        $this->assertTrue($this->repo->restoreDraft(88, self::MINE, 9));
        $this->assertSame('live', $this->statusOf(88));
    }

    public function testCannotRestoreAnotherManufacturersDraft(): void
    {
        $this->assertFalse($this->repo->restoreDraft(99, self::MINE, 9));
        $this->assertSame('trashed', $this->statusOf(99));
    }

    // ------------------------------------------------------------------------ list

    public function testListCarriesTheVariantCountAndThumbnail(): void
    {
        $db = $this->schemaConn();
        $db->query('INSERT INTO db_product_variants (product_id, sku, is_default) VALUES (77, ?, 0)', ['B-1-XL']);
        $db->query('INSERT INTO db_media_assets (id, uuid) VALUES (1, ?)', ['img-uuid']);
        $db->query('INSERT INTO db_product_media (product_id, media_id, sort_order) VALUES (77, 1, 1)');

        $rows = $this->repo->list(self::MINE);
        $row  = null;
        foreach ($rows as $r) {
            if ((int) $r['id'] === 77) {
                $row = $r;
            }
        }

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row['variant_count'], 'both variants must be counted');
        $this->assertSame('img-uuid', $row['image_uuid']);
    }

    public function testListExcludesTrashedAndOtherTenants(): void
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->repo->list(self::MINE));

        $this->assertContains(77, $ids);
        $this->assertContains(66, $ids);
        $this->assertNotContains(88, $ids, 'a trashed draft must not be in the main list');
        $this->assertNotContains(99, $ids, "another manufacturer's product must never appear");
    }
}
