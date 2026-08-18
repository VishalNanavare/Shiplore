<?php

declare(strict_types=1);

use App\Models\ManufacturerEarningsRepository;
use CodeIgniter\Test\CIUnitTestCase;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * What a manufacturer has earned — the gap that mattered most.
 *
 * A manufacturer could accept a purchase order, pack it, dispatch it and have the buyer
 * confirm receipt, and NOWHERE in their panel could they see a rupee of it. The vendor
 * panel has six Finance screens; the manufacturer panel had none, and
 * PurchaseOrderRepository never touched settlements, commission or any money ledger. A
 * seller who cannot see what they are owed does not trust the platform.
 *
 * Run against real tables: the thing most likely to be wrong is WHICH ORDERS COUNT, and
 * a mocked builder would accept any predicate. Two rules carry the whole feature —
 * revenue is recognised on RECEIPT, not on dispatch, and it is scoped to the acting
 * manufacturer as the SELLER.
 */
final class ManufacturerEarningsTest extends CIUnitTestCase
{
    use MinimalSchema;

    private const MINE   = 1;
    private const THEIRS = 2;

    private ManufacturerEarningsRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureMfgDeliveryTables();   // brings db_mfg_purchase_orders
        $this->schemaConn()->table('mfg_purchase_orders')->truncate();
        $this->repo = new ManufacturerEarningsRepository();
    }

    protected function tearDown(): void
    {
        $this->dropMfgDeliveryTables();
        parent::tearDown();
    }

    private function po(int $id, string $status, float $total, int $seller = self::MINE): void
    {
        $this->schemaConn()->query(
            'INSERT INTO db_mfg_purchase_orders (id, po_no, buyer_vendor_id, seller_vendor_id, grand_total, status, created_at)
             VALUES (?, ?, 9, ?, ?, ?, ?)',
            [$id, 'PO-' . $id, $seller, $total, $status, '2026-08-0' . min(9, $id) . ' 10:00:00'],
        );
    }

    // ----------------------------------------------------- what counts as earned

    /**
     * Revenue is recognised when the BUYER CONFIRMS RECEIPT, not when it ships.
     *
     * Dispatched stock can be refused at the door, and counting it would show a
     * manufacturer money that may never arrive — the worst possible error in a screen
     * whose entire purpose is trust.
     */
    public function testOnlyReceivedOrdersCount(): void
    {
        $this->po(1, 'received', 1000.0);
        $this->po(2, 'closed', 500.0);        // received then closed — still earned
        $this->po(3, 'dispatched', 9999.0);   // in transit, not yet earned
        $this->po(4, 'accepted', 8888.0);
        $this->po(5, 'cancelled', 7777.0);
        $this->po(6, 'rejected', 6666.0);

        $this->assertSame(1500.0, $this->repo->totalEarned(self::MINE));
    }

    /** Partially received counts too — the buyer has taken delivery of something. */
    public function testPartiallyReceivedCounts(): void
    {
        $this->po(1, 'partially_received', 250.0);

        $this->assertSame(250.0, $this->repo->totalEarned(self::MINE));
    }

    // ------------------------------------------------------------------ tenancy

    /** Another manufacturer's orders are never counted, whatever their status. */
    public function testAnotherManufacturersOrdersAreExcluded(): void
    {
        $this->po(1, 'received', 1000.0, self::MINE);
        $this->po(2, 'received', 5000.0, self::THEIRS);

        $this->assertSame(1000.0, $this->repo->totalEarned(self::MINE));
        $this->assertSame(5000.0, $this->repo->totalEarned(self::THEIRS));
    }

    /** Scoped as the SELLER. A manufacturer that also buys must not count its purchases. */
    public function testBuyingDoesNotCountAsEarning(): void
    {
        $this->schemaConn()->query(
            'INSERT INTO db_mfg_purchase_orders (id, po_no, buyer_vendor_id, seller_vendor_id, grand_total, status, created_at)
             VALUES (7, ?, ?, ?, 4000, ?, ?)',
            ['PO-7', self::MINE, self::THEIRS, 'received', '2026-08-07 10:00:00'],
        );

        $this->assertSame(0.0, $this->repo->totalEarned(self::MINE), 'this manufacturer was the BUYER here');
    }

    /** Soft-deleted orders drop out. */
    public function testDeletedOrdersAreExcluded(): void
    {
        $this->po(1, 'received', 1000.0);
        $this->schemaConn()->table('mfg_purchase_orders')->where('id', 1)->update(['deleted_at' => '2026-08-09 00:00:00']);

        $this->assertSame(0.0, $this->repo->totalEarned(self::MINE));
    }

    // ------------------------------------------------------------------ listing

    public function testTheListShowsEarnedOrdersNewestFirst(): void
    {
        $this->po(1, 'received', 100.0);
        $this->po(2, 'received', 200.0);
        $this->po(3, 'dispatched', 300.0);

        $rows = $this->repo->earnedOrders(self::MINE);

        $this->assertCount(2, $rows, 'only earned orders appear');
        $this->assertSame(2, (int) $rows[0]['id'], 'newest first');
    }

    /** Awaiting-receipt is shown SEPARATELY — visible, but never added to earnings. */
    public function testPendingIsReportedApartFromEarned(): void
    {
        $this->po(1, 'received', 1000.0);
        $this->po(2, 'dispatched', 400.0);
        $this->po(3, 'packed', 100.0);

        $s = $this->repo->summary(self::MINE);

        $this->assertSame(1000.0, $s['earned']);
        $this->assertSame(500.0, $s['in_transit'], 'dispatched + packed are owed but not yet earned');
        $this->assertSame(1, $s['earned_count']);
    }

    /** A manufacturer with no orders gets zeroes, not an error or a null. */
    public function testAnEmptyLedgerIsZeroNotNull(): void
    {
        $s = $this->repo->summary(self::MINE);

        $this->assertSame(0.0, $s['earned']);
        $this->assertSame(0.0, $s['in_transit']);
        $this->assertSame(0, $s['earned_count']);
        $this->assertSame([], $this->repo->earnedOrders(self::MINE));
    }
}
