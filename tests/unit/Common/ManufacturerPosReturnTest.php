<?php

declare(strict_types=1);

use App\Libraries\Inventory\ManufacturerInventoryService;
use App\Models\ManufacturerPosReturnRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Counter returns at a factory outlet, and the credit notes they produce.
 *
 * The outlet could take money and never give it back — a counter that cannot refund is
 * one a cashier works around with cash out of the drawer, which is exactly the hole an
 * audit trail exists to close.
 *
 * Run against real tables because the invariant is CUMULATIVE and a mocked builder
 * cannot express it: two partial returns of 3 against a line of 5 must succeed, and a
 * third of 1 must fail. Checking each return in isolation passes every one of them and
 * refunds 7 units of a 5-unit sale.
 */
final class ManufacturerPosReturnTest extends CIUnitTestCase
{
    use MinimalSchema;

    private const MINE   = 1;
    private const THEIRS = 2;
    private const UNIT   = 11;

    private ManufacturerPosReturnRepository $repo;
    private ManufacturerInventoryService $inv;
    private int $saleId;
    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureMfgReturnTables();

        $db = $this->schemaConn();
        foreach (['mfg_pos_returns', 'mfg_pos_return_items', 'mfg_pos_cn_sequence',
            'mfg_pos_sales', 'mfg_pos_sale_items', 'mfg_inventory', 'mfg_inventory_ledger', 'mshops'] as $t) {
            $db->table($t)->truncate();
        }
        $db->query('INSERT INTO db_mshops (id, vendor_id, name, code, status) VALUES (?,?,?,?,?)', [self::UNIT, self::MINE, 'Plant A', 'PA', 'active']);

        // One completed sale: 5 units at 118 inclusive of 18% GST.
        $db->query('INSERT INTO db_mfg_pos_sales (id, uuid, mshop_id, vendor_id, invoice_no, financial_year, seq_no,
                    grand_total, status, sold_at) VALUES (7, ?, ?, ?, ?, ?, 1, 590, ?, ?)',
            ['u-7', self::UNIT, self::MINE, 'PA/2026-27/000001', '2026-27', 'completed', '2026-08-01 10:00:00']);
        $db->query('INSERT INTO db_mfg_pos_sale_items (id, uuid, mfg_pos_sale_id, variant_id, product_title_snapshot,
                    sku_snapshot, qty, unit_price, taxable_value, tax_rate, cgst, sgst, line_total, status)
                    VALUES (3, ?, 7, 5, ?, ?, 5, 118, 500, 18, 45, 45, 590, ?)',
            ['u-i3', 'M8 Bolt', 'B-1', 'active']);

        $this->saleId = 7;
        $this->itemId = 3;

        $this->inv = new ManufacturerInventoryService();
        Services::injectMock('manufacturerInventoryService', $this->inv);
        $this->repo = new ManufacturerPosReturnRepository();
    }

    protected function tearDown(): void
    {
        $this->dropMfgReturnTables();
        $this->dropMshopsTable();
        Services::reset();
        parent::tearDown();
    }

    private function ret(float $qty, int $vendor = self::MINE): array
    {
        return $this->repo->createReturn(
            $this->saleId,
            $vendor,
            [['sale_item_id' => $this->itemId, 'qty' => $qty]],
            'damaged',
            'cash',
            1,
        );
    }

    // ------------------------------------------------------------------ the money

    public function testAReturnRefundsWhatWasChargedForThatLine(): void
    {
        $res = $this->ret(2.0);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(236.0, round((float) $res['total'], 2), '2 x 118');

        $r = $this->schemaConn()->table('mfg_pos_returns')->where('id', $res['return_id'])->get()->getRowArray();
        $this->assertSame(200.0, round((float) $r['taxable_value'], 2), 'tax is extracted, not added');
        $this->assertSame(18.0, round((float) $r['cgst'], 2));
        $this->assertSame(18.0, round((float) $r['sgst'], 2));
    }

    /**
     * A DISCOUNTED line refunds what was charged, not the ticket price.
     *
     * This is the only case that separates the two formulas. On an undiscounted line
     * line_total equals unit_price × qty, so prorating the line total and multiplying the
     * unit price give the identical answer and every other test here passes under both — a
     * mutation run proved it. Here the customer paid 100 for 2 units listed at 118, so
     * refunding at the ticket price would hand back 118 for one unit they paid 50 for.
     */
    public function testADiscountedLineRefundsWhatWasActuallyPaid(): void
    {
        $this->schemaConn()->query('INSERT INTO db_mfg_pos_sale_items (id, uuid, mfg_pos_sale_id, variant_id,
            product_title_snapshot, sku_snapshot, qty, unit_price, discount_amount, taxable_value, tax_rate, cgst, sgst, line_total, status)
            VALUES (9, ?, 7, 5, ?, ?, 2, 118, 136, 84.75, 18, 7.63, 7.62, 100, ?)',
            ['u-i9', 'M8 Bolt', 'B-1', 'active']);

        $res = $this->repo->createReturn($this->saleId, self::MINE, [['sale_item_id' => 9, 'qty' => 1]], 'x', 'cash', 1);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(
            50.0,
            round((float) $res['total'], 2),
            'half of the 100 actually paid — not 118, the undiscounted ticket price',
        );
    }

    // ------------------------------------------------------------------- the rule

    public function testMoreThanWasSoldCannotBeReturned(): void
    {
        $this->assertFalse($this->ret(6.0)['ok']);
        $this->assertSame(0, $this->schemaConn()->table('mfg_pos_returns')->countAllResults());
    }

    /**
     * The invariant is CUMULATIVE across every prior return, not per return.
     *
     * Two returns of 3 against a line of 5 must not both succeed. Validating each in
     * isolation passes both and refunds 6 units of a 5-unit sale — the whole reason this
     * runs against real tables rather than a mock.
     */
    public function testReturnsAccumulateAgainstTheSameLine(): void
    {
        $this->assertTrue($this->ret(3.0)['ok']);
        $this->assertFalse($this->ret(3.0)['ok'], '3 + 3 exceeds the 5 that were sold');
        $this->assertTrue($this->ret(2.0)['ok'], 'but the remaining 2 are still returnable');
        $this->assertFalse($this->ret(1.0)['ok'], 'and nothing is left after that');
    }

    public function testZeroOrNegativeQuantityIsRefused(): void
    {
        $this->assertFalse($this->ret(0.0)['ok']);
        $this->assertFalse($this->ret(-2.0)['ok']);
    }

    // ------------------------------------------------------------------- the stock

    public function testReturnedGoodsComeBackIntoTheUnitsStock(): void
    {
        $this->inv->produce(5, self::UNIT, 10.0, 40.0, [], 1);

        $this->ret(2.0);

        $this->assertSame(12.0, (float) $this->inv->levels(5, self::UNIT)['on_hand'], '10 + 2 returned');

        $led = $this->inv->ledger(5, self::UNIT);
        $this->assertSame('return', $led[0]['movement_type']);
        $this->assertSame(2.0, (float) $led[0]['qty']);
        $this->assertSame('mfg_pos_return', $led[0]['ref_type']);
    }

    // ------------------------------------------------------------- credit note no.

    public function testCreditNotesGetTheirOwnNumberSeries(): void
    {
        $a = $this->ret(1.0);
        $b = $this->ret(1.0);

        $this->assertStringContainsString('CN/', (string) $a['credit_note_no'], 'a credit note is its own document, not a suffix on the invoice');
        $this->assertStringEndsWith('000001', (string) $a['credit_note_no']);
        $this->assertStringEndsWith('000002', (string) $b['credit_note_no']);
    }

    /** A voided credit note must not release its number for reuse. */
    public function testAVoidedCreditNoteDoesNotReleaseItsNumber(): void
    {
        $a = $this->ret(1.0);
        $this->schemaConn()->table('mfg_pos_returns')->where('id', $a['return_id'])->update(['status' => 'void']);

        $b = $this->ret(1.0);
        $this->assertStringEndsWith('000002', (string) $b['credit_note_no'], 'COUNT(*)+1 would have reissued 000001');
    }

    // ------------------------------------------------------------------- tenancy

    public function testAnotherManufacturerCannotReturnAgainstThisSale(): void
    {
        $res = $this->ret(2.0, self::THEIRS);

        $this->assertFalse($res['ok']);
        $this->assertSame(0, $this->schemaConn()->table('mfg_pos_returns')->countAllResults());
    }

    public function testTheSaleLookupIsTenantScoped(): void
    {
        $this->assertNotNull($this->repo->findSale('PA/2026-27/000001', self::MINE));
        $this->assertNull($this->repo->findSale('PA/2026-27/000001', self::THEIRS));
    }

    /** A line belonging to a DIFFERENT sale cannot be smuggled into this return. */
    public function testALineFromAnotherSaleIsRefused(): void
    {
        $db = $this->schemaConn();
        $db->query('INSERT INTO db_mfg_pos_sales (id, uuid, mshop_id, vendor_id, invoice_no, financial_year, seq_no, grand_total, status, sold_at)
                    VALUES (8, ?, ?, ?, ?, ?, 2, 118, ?, ?)', ['u-8', self::UNIT, self::MINE, 'PA/2026-27/000002', '2026-27', 'completed', '2026-08-02 10:00:00']);
        $db->query('INSERT INTO db_mfg_pos_sale_items (id, uuid, mfg_pos_sale_id, variant_id, product_title_snapshot, sku_snapshot,
                    qty, unit_price, taxable_value, tax_rate, cgst, sgst, line_total, status)
                    VALUES (4, ?, 8, 5, ?, ?, 1, 118, 100, 18, 9, 9, 118, ?)', ['u-i4', 'M8 Bolt', 'B-1', 'active']);

        $res = $this->repo->createReturn($this->saleId, self::MINE, [['sale_item_id' => 4, 'qty' => 1]], 'x', 'cash', 1);

        $this->assertFalse($res['ok'], 'line 4 belongs to sale 8, not sale 7');
    }

    public function testTheCreditNoteIsReadableOnlyByItsOwner(): void
    {
        $id = $this->ret(1.0)['return_id'];

        $this->assertNotNull($this->repo->findForCreditNote((int) $id, self::MINE));
        $this->assertNull($this->repo->findForCreditNote((int) $id, self::THEIRS));
    }
}
