<?php

declare(strict_types=1);

use App\Libraries\Inventory\ManufacturerInventoryService;
use App\Models\ManufacturerTransferRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Moving stock between a manufacturer's own units.
 *
 * The vendor equivalent, stock_transfers, is keyed on shop_id with FKs to shops(id), so
 * an mshop cannot use it — the same block that required mfg_deliveries. Hence a parallel
 * pair of tables and this service.
 *
 * Run against real tables because the invariant that matters is CONSERVATION: every unit
 * that leaves one location must arrive at another, or be provably still in transit. A
 * mocked inventory service would let a transfer destroy stock and report success.
 *
 * Two-step on purpose — dispatch decrements the source, receipt credits the destination.
 * A single atomic move would be simpler and wrong: goods on a lorry are at neither end,
 * and a warehouse that counts them as arrived before they do has an inventory that
 * disagrees with the shelf.
 */
final class ManufacturerTransferTest extends CIUnitTestCase
{
    use MinimalSchema;

    private const MINE   = 1;
    private const THEIRS = 2;
    private const PLANT  = 11;
    private const WH     = 12;

    private ManufacturerTransferRepository $repo;
    private ManufacturerInventoryService $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureMfgTransferTables();

        $db = $this->schemaConn();
        foreach (['mfg_stock_transfers', 'mfg_stock_transfer_items', 'mfg_inventory', 'mfg_inventory_ledger', 'mshops'] as $t) {
            $db->table($t)->truncate();
        }
        $db->query('INSERT INTO db_mshops (id, vendor_id, name, code, status) VALUES (?,?,?,?,?)', [self::PLANT, self::MINE, 'Plant A', 'PA', 'active']);
        $db->query('INSERT INTO db_mshops (id, vendor_id, name, code, status) VALUES (?,?,?,?,?)', [self::WH, self::MINE, 'Warehouse B', 'WB', 'active']);

        $this->inv = new ManufacturerInventoryService();
        Services::injectMock('manufacturerInventoryService', $this->inv);
        $this->repo = new ManufacturerTransferRepository();

        $this->inv->produce(5, self::PLANT, 100.0, 40.0, [], 1);
    }

    protected function tearDown(): void
    {
        $this->dropMfgTransferTables();
        Services::reset();
        parent::tearDown();
    }

    private function create(array $lines = [['variant_id' => 5, 'qty' => 30]], int $from = self::PLANT, int $to = self::WH): array
    {
        return $this->repo->create(self::MINE, $from, $to, $lines, '', 1);
    }

    // ------------------------------------------------------------------ creation

    public function testATransferIsCreatedAsADraftAndMovesNothingYet(): void
    {
        $res = $this->create();

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(100.0, (float) $this->inv->levels(5, self::PLANT)['on_hand'], 'a draft must not move stock');
        $this->assertSame(0.0, (float) $this->inv->levels(5, self::WH)['on_hand']);
    }

    /**
     * The two ends must differ — moving stock to itself is always a mistake.
     *
     * Asserted on the MESSAGE, not merely on failure. Deleting the dedicated guard still
     * fails the call, because ownsBoth() does whereIn('id', [11, 11]) which matches one
     * distinct row and so never reaches its `=== 2`. That is an accident of SQL
     * deduplication, not a rule, and a mutation run showed a plain assertFalse could not
     * tell the difference.
     */
    public function testAUnitCannotTransferToItself(): void
    {
        $res = $this->create([['variant_id' => 5, 'qty' => 10]], self::PLANT, self::PLANT);

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('different', (string) $res['error'], 'the self-transfer guard must be the thing that refuses it');
        $this->assertSame(0, $this->schemaConn()->table('mfg_stock_transfers')->countAllResults());
    }

    /**
     * More cannot arrive than left. Accepting it would CREATE stock out of a typo — the
     * one thing a transfer must never do, since its whole purpose is conservation.
     */
    public function testReceivingMoreThanWasSentIsRefused(): void
    {
        $id = $this->create()['id'];
        $this->repo->dispatch((int) $id, self::MINE, 1);

        $this->assertFalse($this->repo->receive((int) $id, self::MINE, [5 => 999.0], 1)['ok']);

        $this->assertSame(0.0, (float) $this->inv->levels(5, self::WH)['on_hand'], 'nothing may be credited');
        $this->assertSame(70.0, (float) $this->inv->levels(5, self::PLANT)['on_hand'], 'and the source stays as dispatched');
    }

    /** Both ends must belong to the acting manufacturer. */
    public function testAUnitOfAnotherManufacturerIsRefused(): void
    {
        $this->schemaConn()->query('INSERT INTO db_mshops (id, vendor_id, name, code, status) VALUES (99, ?, ?, ?, ?)', [self::THEIRS, 'Their Plant', 'TP', 'active']);

        $this->assertFalse($this->create([['variant_id' => 5, 'qty' => 10]], self::PLANT, 99)['ok']);
        $this->assertFalse($this->create([['variant_id' => 5, 'qty' => 10]], 99, self::PLANT)['ok']);
    }

    public function testAnEmptyTransferIsRefused(): void
    {
        $this->assertFalse($this->create([])['ok']);
    }

    // ------------------------------------------------------------------ dispatch

    public function testDispatchDecrementsTheSourceOnly(): void
    {
        $id = $this->create()['id'];

        $this->assertTrue($this->repo->dispatch((int) $id, self::MINE, 1)['ok']);

        $this->assertSame(70.0, (float) $this->inv->levels(5, self::PLANT)['on_hand'], '100 - 30');
        $this->assertSame(0.0, (float) $this->inv->levels(5, self::WH)['on_hand'], 'goods in transit have not arrived');
    }

    /** Dispatching more than the source holds is refused before anything moves. */
    public function testDispatchBeyondAvailableStockIsRefused(): void
    {
        $id = $this->create([['variant_id' => 5, 'qty' => 500]])['id'];

        $this->assertFalse($this->repo->dispatch((int) $id, self::MINE, 1)['ok']);
        $this->assertSame(100.0, (float) $this->inv->levels(5, self::PLANT)['on_hand'], 'stock must be untouched');
    }

    public function testATransferCannotBeDispatchedTwice(): void
    {
        $id = $this->create()['id'];
        $this->repo->dispatch((int) $id, self::MINE, 1);

        $this->assertFalse($this->repo->dispatch((int) $id, self::MINE, 1)['ok']);
        $this->assertSame(70.0, (float) $this->inv->levels(5, self::PLANT)['on_hand'], 'the second dispatch must not decrement again');
    }

    // ------------------------------------------------------------------- receipt

    public function testReceiptCreditsTheDestinationAndConservesTotal(): void
    {
        $id = $this->create()['id'];
        $this->repo->dispatch((int) $id, self::MINE, 1);

        $this->assertTrue($this->repo->receive((int) $id, self::MINE, [], 1)['ok']);

        $plant = (float) $this->inv->levels(5, self::PLANT)['on_hand'];
        $wh    = (float) $this->inv->levels(5, self::WH)['on_hand'];

        $this->assertSame(70.0, $plant);
        $this->assertSame(30.0, $wh);
        $this->assertSame(100.0, $plant + $wh, 'conservation: nothing may be created or destroyed');
    }

    /** A short receipt credits only what arrived — the difference is shrinkage. */
    public function testAShortReceiptCreditsOnlyWhatArrived(): void
    {
        $id = $this->create()['id'];
        $this->repo->dispatch((int) $id, self::MINE, 1);

        $this->repo->receive((int) $id, self::MINE, [5 => 28.0], 1);

        $this->assertSame(28.0, (float) $this->inv->levels(5, self::WH)['on_hand']);
        $this->assertSame(98.0, (float) $this->inv->levels(5, self::PLANT)['on_hand'] + 28.0, '2 units short — recorded, not invented');
    }

    /** Receipt before dispatch is refused: goods cannot arrive before they leave. */
    public function testADraftCannotBeReceived(): void
    {
        $id = $this->create()['id'];

        $this->assertFalse($this->repo->receive((int) $id, self::MINE, [], 1)['ok']);
        $this->assertSame(0.0, (float) $this->inv->levels(5, self::WH)['on_hand']);
    }

    // ------------------------------------------------------------------ tenancy

    /** Another manufacturer cannot act on this transfer, whatever its state. */
    public function testAnotherManufacturerCannotDispatchOrReceiveIt(): void
    {
        $id = $this->create()['id'];

        $this->assertFalse($this->repo->dispatch((int) $id, self::THEIRS, 1)['ok']);
        $this->repo->dispatch((int) $id, self::MINE, 1);
        $this->assertFalse($this->repo->receive((int) $id, self::THEIRS, [], 1)['ok']);
        $this->assertSame(0.0, (float) $this->inv->levels(5, self::WH)['on_hand']);
    }

    /** The list is scoped to the acting manufacturer. */
    public function testTheListIsTenantScoped(): void
    {
        $this->create();

        $this->assertCount(1, $this->repo->list(self::MINE));
        $this->assertSame([], $this->repo->list(self::THEIRS));
    }

    // ------------------------------------------------------------------- ledger

    /** Both ends are ledgered, so a stock movement is always explainable. */
    public function testBothEndsAreLedgered(): void
    {
        $id = $this->create()['id'];
        $this->repo->dispatch((int) $id, self::MINE, 1);
        $this->repo->receive((int) $id, self::MINE, [], 1);

        $out = $this->inv->ledger(5, self::PLANT);
        $in  = $this->inv->ledger(5, self::WH);

        $this->assertSame('transfer_out', $out[0]['movement_type']);
        $this->assertSame(-30.0, (float) $out[0]['qty']);
        $this->assertSame('transfer_in', $in[0]['movement_type']);
        $this->assertSame(30.0, (float) $in[0]['qty']);
        $this->assertSame((int) $id, (int) $out[0]['ref_id'], 'the movement must name its transfer');
    }
}
