<?php

declare(strict_types=1);

use App\Libraries\Manufacturer\ManufacturerSettlementService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Building a manufacturer's payout run.
 *
 * Reuses `settlements` rather than forking it: the header is keyed on vendor_id and a
 * manufacturer is a vendors row, so it already has period, gross, commission, refunds,
 * net_payable and a 'held' status.
 *
 * The rule that carries the whole thing is IDEMPOTENCY. A payout run is triggered by a
 * scheduler, and a scheduler retries. Running the same period twice must produce one
 * settlement, not two — the second is how a manufacturer gets paid twice and nobody
 * notices until the bank reconciliation.
 */
final class ManufacturerSettlementTest extends CIUnitTestCase
{
    use MinimalSchema;

    private const MINE   = 1;
    private const THEIRS = 2;

    private ManufacturerSettlementService $svc;

    /** @var array<string,mixed> */
    private array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureMfgDeliveryTables();   // brings db_mfg_purchase_orders
        $this->ensureSettlementTables();

        $db = $this->schemaConn();
        foreach (['mfg_purchase_orders', 'settlements', 'settlement_lines'] as $t) {
            $db->table($t)->truncate();
        }

        Services::injectMock('settingsRepository', new class ($this->settings) {
            public array $s;
            public function __construct(array &$s) { $this->s = &$s; }
            public function get(string $ns, string $key, mixed $d = null): mixed { return $this->s[$ns . '.' . $key] ?? $d; }
        });
        Services::injectMock('b2bPolicy', new \App\Libraries\Manufacturer\B2bPolicy());

        $this->svc = new ManufacturerSettlementService();
    }

    protected function tearDown(): void
    {
        $this->dropSettlementTables();
        $this->dropMfgDeliveryTables();
        Services::reset();
        parent::tearDown();
    }

    private function po(int $id, string $status, float $total, string $when = '2026-08-05 10:00:00', int $seller = self::MINE): void
    {
        $this->schemaConn()->query(
            'INSERT INTO db_mfg_purchase_orders (id, po_no, buyer_vendor_id, seller_vendor_id, grand_total, status, created_at, updated_at)
             VALUES (?,?,9,?,?,?,?,?)',
            [$id, 'PO-' . $id, $seller, $total, $status, $when, $when],
        );
    }

    private function build(string $from = '2026-08-01', string $to = '2026-08-31'): array
    {
        return $this->svc->buildForPeriod(self::MINE, $from, $to, 1);
    }

    // ------------------------------------------------------------- what it counts

    public function testOnlyReceivedOrdersInThePeriodAreSettled(): void
    {
        $this->po(1, 'received', 1000.0, '2026-08-05 10:00:00');
        $this->po(2, 'dispatched', 500.0, '2026-08-06 10:00:00');   // not yet earned
        $this->po(3, 'received', 700.0, '2026-07-20 10:00:00');     // previous period

        $res = $this->build();

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(1000.0, $res['gross']);
    }

    public function testAnotherManufacturersOrdersAreNeverIncluded(): void
    {
        $this->po(1, 'received', 1000.0, '2026-08-05 10:00:00');
        $this->po(2, 'received', 9999.0, '2026-08-05 10:00:00', self::THEIRS);

        $this->assertSame(1000.0, $this->build()['gross']);
    }

    /** A period with nothing in it produces NO settlement, not a zero one. */
    public function testAnEmptyPeriodCreatesNothing(): void
    {
        $res = $this->build();

        $this->assertFalse($res['ok']);
        $this->assertSame(0, $this->schemaConn()->table('settlements')->countAllResults());
    }

    // ------------------------------------------------------------------ the money

    public function testCommissionIsDeductedAtTheConfiguredRate(): void
    {
        $this->settings['b2b.commission_percent'] = 5;
        $this->po(1, 'received', 1000.0);

        $res = $this->build();

        $this->assertSame(1000.0, $res['gross']);
        $this->assertSame(50.0, $res['commission']);
        $this->assertSame(950.0, $res['net']);
    }

    /** With nothing configured the manufacturer is paid gross — not a made-up cut. */
    public function testWithoutAConfiguredRateNothingIsDeducted(): void
    {
        $this->po(1, 'received', 1000.0);

        $res = $this->build();

        $this->assertSame(0.0, $res['commission']);
        $this->assertSame(1000.0, $res['net']);
    }

    /**
     * A 100% commission leaves nothing payable — and cannot leave less than nothing.
     *
     * Being precise about what this proves: with the rate clamped to 0-100 by B2bPolicy,
     * gross minus commission cannot arithmetically go below zero, so the max(0.0, …) in
     * the service is UNREACHABLE today and this test cannot distinguish its presence. A
     * mutation run confirmed that rather than my assuming it.
     *
     * The clamp stays as defence for the columns already in the settlements table and
     * not yet wired — refund_total, fees, adjustments — any of which can exceed gross
     * once a period has more refunds than sales. At that point it becomes reachable and
     * wants its own test.
     */
    public function testAFullCommissionLeavesNothingPayable(): void
    {
        $this->settings['b2b.commission_percent'] = 100;
        $this->po(1, 'received', 1000.0);

        $this->assertSame(0.0, $this->build()['net']);
    }

    // ---------------------------------------------------------------- idempotency

    /**
     * A payout run is triggered by a scheduler, and a scheduler retries.
     *
     * Running the same period twice must return the SAME settlement rather than creating
     * a second one — the second is how a manufacturer gets paid twice and nobody notices
     * until the bank reconciliation.
     */
    public function testRunningTheSamePeriodTwiceIsOneSettlement(): void
    {
        $this->po(1, 'received', 1000.0);

        $a = $this->build();
        $b = $this->build();

        $this->assertTrue($b['ok']);
        $this->assertSame((int) $a['settlement_id'], (int) $b['settlement_id']);
        $this->assertSame(1, $this->schemaConn()->table('settlements')->countAllResults());
    }

    /** A different period is a different run, and both stand. */
    public function testADifferentPeriodIsADifferentSettlement(): void
    {
        $this->po(1, 'received', 1000.0, '2026-08-05 10:00:00');
        $this->po(2, 'received', 400.0, '2026-09-05 10:00:00');

        $this->build();
        $this->svc->buildForPeriod(self::MINE, '2026-09-01', '2026-09-30', 1);

        $this->assertSame(2, $this->schemaConn()->table('settlements')->countAllResults());
    }

    // ---------------------------------------------------------------------- lines

    /**
     * Every order and the commission are itemised, so a settlement can be explained.
     *
     * ref_type is 'mfg_sale', not 'sale': for a vendor 'sale' means a sub_order, and
     * leaving both sharing one member makes ref_id resolvable only by looking up the
     * settlement's owner. Ambiguity resolved by a join is ambiguity somebody eventually
     * resolves wrongly.
     */
    public function testEveryOrderAndTheCommissionAreItemised(): void
    {
        $this->settings['b2b.commission_percent'] = 10;
        $this->po(1, 'received', 1000.0);
        $this->po(2, 'received', 500.0);

        $id    = $this->build()['settlement_id'];
        $lines = $this->schemaConn()->table('settlement_lines')->where('settlement_id', $id)->get()->getResultArray();

        $sales = array_values(array_filter($lines, static fn ($l) => $l['ref_type'] === 'mfg_sale'));
        $comm  = array_values(array_filter($lines, static fn ($l) => $l['ref_type'] === 'commission'));

        $this->assertCount(2, $sales, 'one credit line per order');
        $this->assertCount(1, $comm, 'one debit line for commission');
        $this->assertSame('credit', $sales[0]['direction']);
        $this->assertSame('debit', $comm[0]['direction']);
        $this->assertSame(150.0, (float) $comm[0]['amount'], '10% of 1500');
        $this->assertNull($sales[0]['sub_order_id'], 'a manufacturer order is not a sub_order');
    }

    // --------------------------------------------------------------------- status

    /** A fresh run is 'calculated' — approving and paying it are separate decisions. */
    public function testAFreshRunIsCalculatedNotPaid(): void
    {
        $this->po(1, 'received', 1000.0);

        $row = $this->schemaConn()->table('settlements')->where('id', $this->build()['settlement_id'])->get()->getRowArray();

        $this->assertSame('calculated', $row['status']);
    }

    public function testTheListIsTenantScoped(): void
    {
        $this->po(1, 'received', 1000.0);
        $this->build();

        $this->assertCount(1, $this->svc->list(self::MINE));
        $this->assertSame([], $this->svc->list(self::THEIRS));
    }
}
