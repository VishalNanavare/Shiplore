<?php

declare(strict_types=1);

use App\Libraries\Inventory\ManufacturerInventoryService;
use CodeIgniter\Test\CIUnitTestCase;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Manufacturer stock, tested against real tables rather than mocks.
 *
 * mfg_inventory / mfg_inventory_ledger / mfg_stock_batches shipped in
 * 70_manufacturer.sql and had NO reader or writer anywhere in app/ until this
 * service — manufacturer stock was simply never tracked, which is also why
 * dispatching a purchase order raised the buyer's stock and decremented nobody's.
 *
 * These run against the SQLite test database because the thing most likely to be
 * wrong here is a COLUMN NAME, and a mocked query builder would happily accept the
 * wrong one. The manufacturer tables differ from the vendor tables in two places that
 * a copy-paste of InventoryService gets wrong and that fail silently:
 *
 *   - the ledger quantity column is `qty`, not `qty_delta`
 *   - the batch cost column is `making_cost`, not `cost_price`
 */
final class ManufacturerInventoryServiceTest extends CIUnitTestCase
{
    use MinimalSchema;

    private ManufacturerInventoryService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureMfgInventoryTables();
        $this->svc = new ManufacturerInventoryService();
    }

    protected function tearDown(): void
    {
        $this->dropMfgInventoryTables();
        $this->dropMshopsTable();
        foreach (['db_products', 'db_product_variants', 'db_product_mshops'] as $t) {
            $this->schemaConn()->query('DROP TABLE IF EXISTS ' . $t);
        }
        parent::tearDown();
    }

    /**
     * Successive decrements accumulate.
     *
     * Be clear about what this does and does not prove. It runs two sales in sequence, so
     * it passes under the old read-modify-write implementation too — a genuine lost update
     * needs two connections interleaving between the SELECT and the UPDATE, which PHPUnit
     * on one SQLite connection cannot stage. What this pins is the arithmetic and the
     * ledger: that switching to an SQL expression did not change the ordinary result.
     *
     * testTheDecrementIsAppliedByTheDatabase() below is what actually pins the race fix.
     */
    public function testSuccessiveDecrementsAccumulate(): void
    {
        $this->seedCatalogue();
        $this->svc->produce(5, 11, 10.0, 40.0, [], 1);

        $this->svc->sellFromOutlet(5, 11, 1.0, 101, 1);
        $this->svc->sellFromOutlet(5, 11, 1.0, 102, 1);

        $this->assertSame(
            8.0,
            (float) $this->svc->levels(5, 11)['on_hand'],
            'both decrements must land — 10 - 1 - 1',
        );
    }

    /** The floor still holds, and it is the DATABASE applying it, not PHP. */
    public function testStockCannotGoNegative(): void
    {
        $this->seedCatalogue();
        $this->svc->produce(5, 11, 2.0, 40.0, [], 1);
        $this->svc->adjust(5, 11, -50.0, 'damage', '', 1);

        $this->assertSame(0.0, (float) $this->svc->levels(5, 11)['on_hand']);
        $this->assertSame('out_of_stock', $this->svc->levels(5, 11)['status']);
    }

    /**
     * The decrement must be an SQL expression, not a value computed in PHP.
     *
     * The behavioural tests above pass under either implementation when nothing else is
     * touching the row, which is every test run. This is what actually pins the fix.
     * GREATEST is deliberately absent: it is MySQL-only, and SQLite spells it MAX(), so
     * using it would mean the suite never exercises production's real statement.
     */
    public function testTheDecrementIsAppliedByTheDatabase(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Libraries/Inventory/ManufacturerInventoryService.php');
        $body = $this->methodBody($src, 'bump');

        $this->assertNotSame('', $body, 'bump() not found');
        $this->assertMatchesRegularExpression(
            "/->set\(\s*'on_hand'\s*,\s*\\\$expr\s*,\s*false\s*\)/",
            $body,
            'on_hand must be updated by an unescaped SQL expression',
        );
        $this->assertStringContainsString('CASE WHEN on_hand', $body, 'the floor must be applied in SQL');
        $this->assertStringNotContainsString('GREATEST', $body, 'GREATEST is MySQL-only — SQLite would never run this path');
        $this->assertDoesNotMatchRegularExpression(
            "/update\(\[\s*'on_hand'\s*=>/",
            $body,
            'writing an absolute on_hand is the lost update this fix removes',
        );
    }

    private function methodBody(string $src, string $method): string
    {
        if (! preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $brace = strpos($src, '{', (int) $m[0][1]);
        $depth = 0;

        for ($i = (int) $brace, $len = strlen($src); $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, (int) $brace, $i - (int) $brace + 1);
                }
            }
        }

        return '';
    }

    public function testProduceCreatesTheRowAndRaisesOnHand(): void
    {
        $this->assertTrue($this->svc->produce(5, 11, 120.0, 40.0, [], 1));

        $lv = $this->svc->levels(5, 11);
        $this->assertSame(120.0, (float) $lv['on_hand']);
        $this->assertSame('in_stock', $lv['status']);
    }

    /** The ledger row must actually be written, with the right movement type. */
    public function testProducePostsAProductionLedgerRow(): void
    {
        $this->svc->produce(5, 11, 120.0, 40.0, [], 1);

        $rows = $this->svc->ledger(5, 11);
        $this->assertCount(1, $rows);
        // 'production', not 'purchase' — a manufacturer makes stock rather than buying
        // it, and the ledger enum has no 'purchase' member at all.
        $this->assertSame('production', $rows[0]['movement_type']);
        $this->assertSame(120.0, (float) $rows[0]['qty']);
        $this->assertSame(120.0, (float) $rows[0]['balance_after']);
    }

    /** The batch is what carries the production cost, under its own column name. */
    public function testProduceWritesAStockBatchWithItsMakingCost(): void
    {
        $this->svc->produce(5, 11, 120.0, 37.5, ['batch_no' => 'B-TEST'], 1);

        $batch = $this->schemaConn()->table('mfg_stock_batches')
            ->where('variant_id', 5)->where('mshop_id', 11)->get()->getRowArray();

        $this->assertNotNull($batch);
        $this->assertSame('B-TEST', $batch['batch_no']);
        $this->assertSame(37.5, (float) $batch['making_cost']);
    }

    public function testAdjustAppliesASignedDeltaAndLedgersIt(): void
    {
        $this->svc->produce(5, 11, 100.0, 40.0, [], 1);
        $this->assertTrue($this->svc->adjust(5, 11, -15.0, 'damage', 'water damage', 1));

        $this->assertSame(85.0, (float) $this->svc->levels(5, 11)['on_hand']);

        $rows = $this->svc->ledger(5, 11);
        $this->assertSame('write_off', $rows[0]['movement_type'], 'damage must ledger as a write-off');
        $this->assertSame(-15.0, (float) $rows[0]['qty']);
        $this->assertSame(85.0, (float) $rows[0]['balance_after']);
    }

    /** On-hand is floored at zero rather than going negative. */
    public function testOnHandNeverGoesNegative(): void
    {
        $this->svc->produce(5, 11, 10.0, 40.0, [], 1);
        $this->svc->adjust(5, 11, -50.0, 'manual', '', 1);

        $this->assertSame(0.0, (float) $this->svc->levels(5, 11)['on_hand']);
        $this->assertSame('out_of_stock', $this->svc->levels(5, 11)['status']);
    }

    /**
     * The behaviour this whole phase exists for: dispatching a purchase order must
     * take the goods off the manufacturer's own books.
     */
    public function testShippingForAPurchaseOrderDecrementsAndReferencesThePo(): void
    {
        $this->svc->produce(5, 11, 200.0, 40.0, [], 1);
        $this->assertTrue($this->svc->shipForPurchaseOrder(5, 11, 30.0, 4242, 1));

        $this->assertSame(170.0, (float) $this->svc->levels(5, 11)['on_hand']);

        $rows = $this->svc->ledger(5, 11);
        $this->assertSame('sale', $rows[0]['movement_type']);
        $this->assertSame(-30.0, (float) $rows[0]['qty']);
        // The movement has to say WHICH order moved it, or the balance is unexplainable.
        $this->assertSame('purchase_order', $rows[0]['ref_type']);
        $this->assertSame(4242, (int) $rows[0]['ref_id']);
    }

    public function testStockIsHeldPerUnitNotPerManufacturer(): void
    {
        $this->svc->produce(5, 11, 100.0, 40.0, [], 1);
        $this->svc->produce(5, 12, 25.0, 40.0, [], 1);

        $this->assertSame(100.0, (float) $this->svc->levels(5, 11)['on_hand']);
        $this->assertSame(25.0, (float) $this->svc->levels(5, 12)['on_hand'], 'unit 12 must have its own balance');
    }

    public function testLevelsAreZeroForAVariantWithNoRow(): void
    {
        $lv = $this->svc->levels(999, 11);

        $this->assertSame(0, (int) $lv['on_hand']);
        $this->assertNull($lv['id']);
    }

    public function testZeroAndNegativeProductionAreRefused(): void
    {
        $this->assertFalse($this->svc->produce(5, 11, 0.0, 40.0, [], 1));
        $this->assertFalse($this->svc->produce(5, 11, -5.0, 40.0, [], 1));
        $this->assertSame([], $this->svc->ledger(5, 11));
    }

    public function testAReorderLevelFlipsTheStatusToLow(): void
    {
        $this->svc->produce(5, 11, 100.0, 40.0, [], 1);
        $this->schemaConn()->table('mfg_inventory')
            ->where('variant_id', 5)->where('mshop_id', 11)->update(['reorder_level' => 50]);

        $this->svc->adjust(5, 11, -60.0, 'manual', '', 1);

        $this->assertSame('low', $this->svc->levels(5, 11)['status']);
    }

    /**
     * PurchaseOrderRepository must actually WIRE the dispatch to this service.
     *
     * transition() needs MariaDB (it takes a FOR UPDATE lock), so the wiring is
     * asserted from source rather than by driving a real dispatch. Comments are
     * stripped first: this file's own docblocks name shipForPurchaseOrder() while
     * explaining it, and a comment must not satisfy an assertion about code.
     */
    public function testPurchaseOrderDispatchIsWiredToStock(): void
    {
        $src = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . 'Models/PurchaseOrderRepository.php')) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $src .= $t[1];
            } else {
                $src .= $t;
            }
        }

        // Anchored on the CALL, not on `if ($to === 'dispatched')`: transition() already
        // contains `elseif ($to === 'dispatched') {` for the timestamp, and that string
        // contains the `if (...)` one as a substring — so asserting the condition passed
        // even with the stock call deleted. A mutation run caught it doing exactly that.
        $this->assertStringContainsString('$this->shipStockForPo($poId,', $src, 'dispatch must trigger a stock movement');
        $this->assertStringContainsString('private function shipStockForPo(', $src);
        $this->assertStringContainsString("service('manufacturerInventoryService')", $src);
        $this->assertStringContainsString('shipForPurchaseOrder(', $src);
        // Every line on the order, not just the first.
        $this->assertStringContainsString("table('mfg_purchase_order_items')", $src);
    }
    /**
     * THE BOOTSTRAP DEADLOCK. levelsForUnits() used to start at mfg_inventory and INNER
     * JOIN outward, so a manufacturer with no stock rows got an empty grid — and the
     * only link to the screen that RECORDS stock lived inside that grid. Stock could
     * never be recorded from the menu at all.
     *
     * A listed variant with no balance row must appear, at zero.
     */
    public function testAListedVariantWithNoStockRowStillAppears(): void
    {
        $this->seedCatalogue();

        $rows = $this->svc->levelsForUnits(1, [11]);

        $this->assertCount(1, $rows, 'a variant listed to the unit must appear before any stock exists');
        $this->assertSame(0.0, (float) $rows[0]['on_hand']);
        $this->assertSame('out_of_stock', $rows[0]['status']);
        $this->assertSame(77, (int) $rows[0]['product_id'], 'the row must carry the product id the Manage link needs');
    }

    /** ...and once stock exists, the real balance shows rather than the zero default. */
    public function testARealBalanceOverridesTheZeroDefault(): void
    {
        $this->seedCatalogue();
        $this->svc->produce(5, 11, 60.0, 40.0, [], 1);

        $rows = $this->svc->levelsForUnits(1, [11]);

        $this->assertCount(1, $rows);
        $this->assertSame(60.0, (float) $rows[0]['on_hand']);
        $this->assertSame('in_stock', $rows[0]['status']);
    }

    /** Another manufacturer's catalogue must not leak in, even for a shared unit id. */
    public function testTheGridIsScopedToTheOwningManufacturer(): void
    {
        $this->seedCatalogue();

        $this->assertCount(1, $this->svc->levelsForUnits(1, [11]));
        $this->assertSame([], $this->svc->levelsForUnits(999, [11]), 'another tenant must see nothing');
    }

    /** A variant listed to a DIFFERENT unit must not show under this one. */
    public function testTheGridIsScopedToTheRequestedUnits(): void
    {
        $this->seedCatalogue();

        $this->assertSame([], $this->svc->levelsForUnits(1, [12]));
    }

    /** An unlisted (product_mshops-less) product must not appear — it is not made here. */
    public function testAnUnlistedProductDoesNotAppear(): void
    {
        $this->seedCatalogue();
        $this->schemaConn()->table('product_mshops')->truncate();

        $this->assertSame([], $this->svc->levelsForUnits(1, [11]));
    }

    /** products + product_variants + product_mshops for one variant of one product. */
    private function seedCatalogue(): void
    {
        $db = $this->schemaConn();
        $db->query('CREATE TABLE IF NOT EXISTS db_products (
            id INTEGER PRIMARY KEY AUTOINCREMENT, vendor_id INTEGER NOT NULL, title TEXT,
            status TEXT NOT NULL DEFAULT "draft", deleted_at TEXT
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_product_variants (
            id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, sku TEXT,
            making_price REAL, base_price REAL, deleted_at TEXT
        )');
        $this->ensureProductShopsTable();
        $db->query('CREATE TABLE IF NOT EXISTS db_product_mshops (
            id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, mshop_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "active", listed_at TEXT, created_at TEXT, updated_at TEXT,
            UNIQUE(product_id, mshop_id)
        )');
        $this->ensureMshopsTable();

        foreach (['products', 'product_variants', 'product_mshops', 'mshops'] as $t) {
            $db->table($t)->truncate();
        }
        $db->query('INSERT INTO db_products (id, vendor_id, title, status) VALUES (77, 1, ?, ?)', ['M8 Bolt', 'draft']);
        $db->query('INSERT INTO db_product_variants (id, product_id, sku, making_price, base_price) VALUES (5, 77, ?, 40.0, 60.0)', ['B-1']);
        $db->query('INSERT INTO db_product_mshops (product_id, mshop_id, status) VALUES (77, 11, ?)', ['active']);
        $db->query('INSERT INTO db_mshops (id, vendor_id, name, status) VALUES (11, 1, ?, ?)', ['Bhiwandi Plant', 'active']);
    }
}
