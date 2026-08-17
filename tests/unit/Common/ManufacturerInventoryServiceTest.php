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
        parent::tearDown();
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
}
