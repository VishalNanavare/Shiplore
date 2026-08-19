<?php

declare(strict_types=1);

use App\Models\ManufacturerComboRepository;
use CodeIgniter\Test\CIUnitTestCase;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Combo offers for a manufacturer — several items sold as one.
 *
 * FORKED from Vendor\ComboRepository rather than reused, and the reason is containment.
 * That class is tenancy-clean (it scopes components by vendor_id, and a manufacturer is
 * a vendors row), but it carries two vendor assumptions that are actively wrong here:
 *
 *   - it can set is_online_enabled = 1 when the caller asks for the 'online' channel.
 *     A manufacturer product on the consumer storefront is the exact leak that
 *     is_online_enabled = 0 plus visibility = 'vendor' exist to prevent.
 *   - it prices the combo with MRP. Manufacturers have no MRP; they price with a making
 *     price and a selling price, and the invariant between them is different.
 *
 * Widening it to "also handle manufacturers" would put both of those one boolean away
 * from a storefront leak.
 */
final class ManufacturerComboTest extends CIUnitTestCase
{
    use MinimalSchema;

    private const MINE   = 1;
    private const THEIRS = 2;
    private const UNIT   = 11;

    private ManufacturerComboRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureMshopsTable();
        $this->ensureCategoriesTable();

        $db = $this->schemaConn();
        $db->query('CREATE TABLE IF NOT EXISTS db_products (
            id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, vendor_id INTEGER NOT NULL, title TEXT,
            category_id INTEGER, tax_class_id INTEGER, unit_id INTEGER, hsn_id INTEGER,
            product_type TEXT, combo_inventory_mode TEXT,
            is_online_enabled INTEGER NOT NULL DEFAULT 0, is_pos_enabled INTEGER NOT NULL DEFAULT 0,
            visibility TEXT, status TEXT NOT NULL DEFAULT "draft",
            created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_product_variants (
            id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, product_id INTEGER NOT NULL, vendor_id INTEGER,
            sku TEXT, is_default INTEGER NOT NULL DEFAULT 0, mrp REAL, making_price REAL, base_price REAL,
            status TEXT NOT NULL DEFAULT "active", created_by INTEGER, deleted_at TEXT
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_product_bundle_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL,
            component_variant_id INTEGER NOT NULL, qty REAL NOT NULL DEFAULT 1,
            created_at TEXT, UNIQUE(product_id, component_variant_id)
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_product_mshops (
            id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, mshop_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "active", UNIQUE(product_id, mshop_id)
        )');
        foreach (['products', 'product_variants', 'product_bundle_items', 'product_mshops', 'mshops'] as $t) {
            $db->table($t)->truncate();
        }
        $db->query('INSERT INTO db_mshops (id, vendor_id, name, code, status) VALUES (?,?,?,?,?)', [self::UNIT, self::MINE, 'Plant A', 'PA', 'active']);

        // Two components owned by MINE, one by THEIRS.
        foreach ([[5, self::MINE], [6, self::MINE], [9, self::THEIRS]] as [$vid, $owner]) {
            $db->query('INSERT INTO db_products (id, vendor_id, title, status) VALUES (?,?,?,?)', [$vid * 10, $owner, 'P' . $vid, 'published']);
            $db->query('INSERT INTO db_product_variants (id, product_id, vendor_id, sku, is_default, making_price, base_price)
                        VALUES (?,?,?,?,1,10,25)', [$vid, $vid * 10, $owner, 'SKU-' . $vid]);
        }

        $this->repo = new ManufacturerComboRepository();
    }

    protected function tearDown(): void
    {
        foreach (['db_products', 'db_product_variants', 'db_product_bundle_items', 'db_product_mshops'] as $t) {
            $this->schemaConn()->query('DROP TABLE IF EXISTS ' . $t);
        }
        $this->dropMshopsTable();
        parent::tearDown();
    }

    /** @param list<array{variant_id:int,qty:float}> $components */
    private function make(array $components = [['variant_id' => 5, 'qty' => 1], ['variant_id' => 6, 'qty' => 2]], array $over = []): ?int
    {
        return $this->repo->create(self::MINE, $over + [
            'name' => 'Starter Kit', 'category_id' => 10, 'tax_class_id' => 4, 'unit_id' => 1,
            'making_price' => '18', 'base_price' => '45',
            'components' => $components, 'mshop_ids' => [self::UNIT],
        ], 1);
    }

    // ------------------------------------------------------------- containment

    /**
     * The whole reason this is forked: a manufacturer combo must NEVER be sellable on
     * the consumer storefront, whatever the caller asks for.
     */
    public function testACombOisNeverOnlineEnabled(): void
    {
        $pid = $this->make([['variant_id' => 5, 'qty' => 1], ['variant_id' => 6, 'qty' => 1]], ['channel' => 'online']);

        $this->assertNotNull($pid);
        $row = $this->schemaConn()->table('products')->where('id', $pid)->get()->getRowArray();
        $this->assertSame(0, (int) $row['is_online_enabled'], 'asking for the online channel must not put it on the storefront');
        $this->assertSame(0, (int) $row['is_pos_enabled'], 'nor on the consumer POS');
    }

    public function testItIsMarkedAsABundle(): void
    {
        $pid = $this->make();
        $row = $this->schemaConn()->table('products')->where('id', $pid)->get()->getRowArray();

        $this->assertSame('bundle', $row['product_type']);
        $this->assertSame(self::MINE, (int) $row['vendor_id']);
    }

    // -------------------------------------------------------------- components

    /** A combo of one thing is not a combo. */
    public function testFewerThanTwoComponentsIsRefused(): void
    {
        $this->assertNull($this->make([['variant_id' => 5, 'qty' => 1]]));
        $this->assertSame(0, $this->schemaConn()->table('products')->where('product_type', 'bundle')->countAllResults());
    }

    /**
     * Another manufacturer's variant must be dropped, and dropping it must be able to
     * take the combo below the minimum — otherwise a two-item combo of one owned and one
     * foreign variant would quietly become a one-item combo.
     */
    public function testAnotherManufacturersComponentIsDropped(): void
    {
        $this->assertNull(
            $this->make([['variant_id' => 5, 'qty' => 1], ['variant_id' => 9, 'qty' => 1]]),
            'one owned + one foreign leaves a single valid component, which is not a combo',
        );
    }

    public function testComponentsAreStoredWithTheirQuantities(): void
    {
        $pid   = $this->make();
        $items = $this->schemaConn()->table('product_bundle_items')->where('product_id', $pid)->orderBy('component_variant_id')->get()->getResultArray();

        $this->assertCount(2, $items);
        $this->assertSame(5, (int) $items[0]['component_variant_id']);
        $this->assertSame(2.0, (float) $items[1]['qty'], 'qty 2 must survive');
    }

    /** A variant listed twice sums rather than violating the unique key. */
    public function testARepeatedComponentIsSummed(): void
    {
        $pid = $this->make([['variant_id' => 5, 'qty' => 1], ['variant_id' => 5, 'qty' => 2], ['variant_id' => 6, 'qty' => 1]]);

        $this->assertNotNull($pid);
        $row = $this->schemaConn()->table('product_bundle_items')
            ->where('product_id', $pid)->where('component_variant_id', 5)->get()->getRowArray();
        $this->assertSame(3.0, (float) $row['qty']);
    }

    // ------------------------------------------------------------------ pricing

    /** Manufacturer pricing: making + selling, and NO MRP. */
    public function testItIsPricedWithMakingAndSellingNotMrp(): void
    {
        $pid = $this->make();
        $v   = $this->schemaConn()->table('product_variants')->where('product_id', $pid)->get()->getRowArray();

        $this->assertSame(18.0, (float) $v['making_price']);
        $this->assertSame(45.0, (float) $v['base_price']);
        $this->assertSame(0.0, (float) $v['mrp'], 'manufacturers have no MRP concept');
    }

    /** The making < selling invariant applies to a combo exactly as to any product. */
    public function testTheMakingSellingInvariantIsEnforced(): void
    {
        $this->assertNull($this->make([['variant_id' => 5, 'qty' => 1], ['variant_id' => 6, 'qty' => 1]], ['making_price' => '50', 'base_price' => '45']));
    }

    // -------------------------------------------------------------------- units

    public function testItIsListedAtTheChosenUnits(): void
    {
        $pid = $this->make();

        $this->assertSame(
            1,
            $this->schemaConn()->table('product_mshops')->where('product_id', $pid)->where('mshop_id', self::UNIT)->countAllResults(),
        );
    }

    /** A unit belonging to someone else is not listed against. */
    public function testAForeignUnitIsNotListed(): void
    {
        $this->schemaConn()->query('INSERT INTO db_mshops (id, vendor_id, name, code, status) VALUES (99, ?, ?, ?, ?)', [self::THEIRS, 'Their Plant', 'TP', 'active']);

        $pid = $this->repo->create(self::MINE, [
            'name' => 'K', 'category_id' => 10, 'tax_class_id' => 4, 'unit_id' => 1,
            'making_price' => '18', 'base_price' => '45',
            'components' => [['variant_id' => 5, 'qty' => 1], ['variant_id' => 6, 'qty' => 1]],
            'mshop_ids' => [99],
        ], 1);

        $this->assertSame(0, $this->schemaConn()->table('product_mshops')->where('mshop_id', 99)->countAllResults());
    }

    // ------------------------------------------------------------------ listing

    public function testTheListIsTenantScoped(): void
    {
        $this->make();

        $this->assertCount(1, $this->repo->list(self::MINE));
        $this->assertSame([], $this->repo->list(self::THEIRS));
    }
}
