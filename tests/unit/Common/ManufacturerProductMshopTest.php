<?php

declare(strict_types=1);

use App\Models\ManufacturerProductRepository;
use Config\Database;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * A manufacturer product must belong to at least one manufacturing unit (mshop),
 * or it is unreachable everywhere `product_mshops` is the join: the unit-scoped
 * product list (`ManufacturerProductRepository::list()`, the `pm.mshop_id = ?`
 * EXISTS clause) and the monline B2B catalogue (`MonlineCatalogRepository`).
 *
 * `create()`/`update()` never wrote to `product_mshops` at all — confirmed by the
 * demo seed (`database/sql/manufacturer_products_demo.sql`) doing that insert by
 * hand for every seeded product, which only makes sense if the live code was
 * expected to do it and doesn't. Every product created through the real UI ends
 * up assigned to zero units.
 *
 * This is a real behavioural test, not a source assertion: it stands up the
 * minimal schema this repository touches in the SQLite in-memory `tests` DB
 * group and calls the actual repository methods, then reads back the real rows
 * — because a fix this central to whether the feature works at all deserves
 * more than "the right words appear in the file."
 */
final class ManufacturerProductMshopTest extends CIUnitTestCase
{
    private object $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();

        // Minimal SQLite-compatible mirror of the columns this repository reads/writes,
        // matching database/sql/04_transaction.sql and database/sql/70_manufacturer.sql.
        $this->conn->query('CREATE TABLE IF NOT EXISTS db_products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, vendor_id INTEGER, category_id INTEGER,
            tax_class_id INTEGER, base_unit_id INTEGER,
            title TEXT, slug TEXT, description TEXT,
            is_online_enabled INTEGER, is_pos_enabled INTEGER,
            visibility TEXT, status TEXT,
            created_by INTEGER, updated_by INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
        $this->conn->query('CREATE TABLE IF NOT EXISTS db_product_variants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, product_id INTEGER, vendor_id INTEGER,
            sku TEXT, unit_id INTEGER,
            mrp TEXT, making_price TEXT, base_price TEXT,
            is_default INTEGER, status TEXT,
            created_by INTEGER, updated_by INTEGER,
            created_at TEXT, updated_at TEXT
        )');
        $this->conn->query('CREATE TABLE IF NOT EXISTS db_product_mshops (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL, mshop_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "active",
            listed_at TEXT, created_at TEXT, updated_at TEXT,
            UNIQUE(product_id, mshop_id)
        )');
    }

    protected function tearDown(): void
    {
        foreach (['db_product_mshops', 'db_product_variants', 'db_products'] as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    private function repo(): ManufacturerProductRepository
    {
        return new ManufacturerProductRepository();
    }

    /** @return array<string,mixed> the created row from product_mshops, or [] if none */
    private function mshopRow(int $productId): array
    {
        return (array) ($this->conn->table('product_mshops')
            ->where('product_id', $productId)->get()->getRowArray() ?? []);
    }

    public function testCreateAssignsTheProductToTheGivenUnit(): void
    {
        $res = $this->repo()->create(1, [
            'title' => 'Steel Rod 10mm', 'category_id' => 5,
            'making_price' => '100', 'base_price' => '150',
        ], 42, 7);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $row = $this->mshopRow((int) $res['id']);
        $this->assertNotSame([], $row, 'product_mshops row was never written — the product belongs to zero units');
        $this->assertSame(7, (int) $row['mshop_id']);
        $this->assertSame('active', $row['status']);
    }

    /** listed_at should reflect when the product was actually put on a unit, not stay perpetually null. */
    public function testCreateStampsListedAt(): void
    {
        $res = $this->repo()->create(1, [
            'title' => 'Steel Rod 12mm', 'category_id' => 5,
            'making_price' => '100', 'base_price' => '150',
        ], 42, 7);

        $row = $this->mshopRow((int) $res['id']);
        $this->assertNotNull($row['listed_at'] ?? null);
    }

    /**
     * A create with no unit selected must fail outright rather than silently produce
     * an orphaned product — that silent failure is the exact bug being fixed.
     */
    public function testCreateWithoutAUnitIsRejected(): void
    {
        $res = $this->repo()->create(1, [
            'title' => 'No Unit Product', 'category_id' => 5,
            'making_price' => '100', 'base_price' => '150',
        ], 42, null);

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('unit', strtolower($res['error']));
        $this->assertSame(
            0,
            (int) $this->conn->table('products')->countAllResults(),
            'nothing should be persisted when the unit is missing — not a half-created orphan',
        );
    }

    public function testCreateRejectsAZeroOrNegativeUnitId(): void
    {
        foreach ([0, -1] as $bad) {
            $res = $this->repo()->create(1, [
                'title' => 'Bad Unit', 'category_id' => 5,
                'making_price' => '100', 'base_price' => '150',
            ], 42, $bad);
            $this->assertFalse($res['ok'], "mshopId={$bad} should have been rejected");
        }
    }

    /** Reassigning to a different unit on update must move the row, not duplicate it. */
    public function testUpdateReassignsToADifferentUnit(): void
    {
        $created = $this->repo()->create(1, [
            'title' => 'Widget', 'category_id' => 5,
            'making_price' => '100', 'base_price' => '150',
        ], 42, 7);
        $id = (int) $created['id'];

        $res = $this->repo()->update($id, 1, ['title' => 'Widget'], 42, 9);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(
            1,
            $this->conn->table('product_mshops')->where('product_id', $id)->countAllResults(),
            'exactly one product_mshops row must exist after reassignment, not one per unit ever assigned',
        );
        $row = $this->mshopRow($id);
        $this->assertSame(9, (int) $row['mshop_id']);
    }

    /**
     * findById() must surface the current unit so the edit form can pre-select it —
     * without this the edit page has no way to know what's already assigned.
     */
    public function testFindByIdSurfacesTheAssignedMshop(): void
    {
        $created = $this->repo()->create(1, [
            'title' => 'Widget', 'category_id' => 5,
            'making_price' => '100', 'base_price' => '150',
        ], 42, 7);

        $found = $this->repo()->findById((int) $created['id'], 1);

        $this->assertNotNull($found);
        $this->assertSame(7, (int) ($found['mshop_id'] ?? 0));
    }

    /** A LEFT JOIN must not hide the product itself when it has no unit (e.g. legacy/orphaned data). */
    public function testFindByIdStillReturnsAProductWithNoAssignedUnit(): void
    {
        // Bypass create()'s now-mandatory unit guard to reproduce pre-fix orphaned data.
        $this->conn->table('products')->insert([
            'uuid' => 'x', 'vendor_id' => 1, 'category_id' => 5, 'title' => 'Orphan',
            'slug' => 'orphan', 'status' => 'draft',
        ]);
        $id = (int) $this->conn->insertID();

        $found = $this->repo()->findById($id, 1);

        $this->assertNotNull($found, 'a LEFT JOIN on product_mshops must not drop the product row');
        // array_key_exists, not ?? — a present-but-null key and an absent key both
        // trigger ?? 's fallback, which would make this assertion pass either way.
        $this->assertArrayHasKey('mshop_id', $found, 'findById() must select pm.mshop_id even when the LEFT JOIN has no match');
        $this->assertNull($found['mshop_id']);
    }

    /** Update WITHOUT a unit argument (e.g. a section-only autosave) must leave the existing assignment untouched. */
    public function testUpdateWithoutAUnitArgumentLeavesExistingAssignmentAlone(): void
    {
        $created = $this->repo()->create(1, [
            'title' => 'Widget', 'category_id' => 5,
            'making_price' => '100', 'base_price' => '150',
        ], 42, 7);
        $id = (int) $created['id'];

        $res = $this->repo()->update($id, 1, ['title' => 'Widget renamed'], 42, null);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $row = $this->mshopRow($id);
        $this->assertSame(7, (int) $row['mshop_id'], 'omitting the unit on update must not clear or change it');
    }
}
