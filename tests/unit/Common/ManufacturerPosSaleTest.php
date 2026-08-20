<?php

declare(strict_types=1);

use App\Libraries\Inventory\ManufacturerInventoryService;
use App\Models\ManufacturerPosSaleRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * The factory-outlet sale, end to end against real tables.
 *
 * Money, stock and invoice numbering are all enforced by SQL and by arithmetic here,
 * none of which a mocked repository can check. The rules this pins are the ones the
 * vendor POS gets wrong and this one deliberately does not:
 *
 *   - idempotency is scoped by tenant (the vendor lookup has no vendor predicate);
 *   - the invoice sequence is a locked counter per unit per financial year, not
 *     COUNT(*) + 1;
 *   - a failed stock decrement rolls the whole sale back rather than leaving a
 *     committed sale with no inventory movement.
 */
final class ManufacturerPosSaleTest extends CIUnitTestCase
{
    use MinimalSchema;

    private const MINE   = 1;
    private const THEIRS = 2;
    private const UNIT   = 11;

    private ManufacturerPosSaleRepository $repo;
    private ManufacturerInventoryService $inv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMfgPosTables();
        $this->ensureMfgInventoryTables();
        $this->ensureMshopsTable();
        // The receipt joins users for the cashier name. Dropped again in tearDown —
        // a leaked db_users flips other files' fail-open auth checks to fail-closed.
        $this->ensureUsersTable();

        $db = $this->schemaConn();
        // Shared fixture, not a bespoke CREATE TABLE — this file's own narrower version
        // used to win the CREATE TABLE IF NOT EXISTS race under --order-by=random
        // against a different file's WIDER products table, locking this file's process
        // into a schema missing columns the other file needed. See MinimalSchema's own
        // comment on ensureProductsTable().
        $this->ensureProductsTable();
        $this->ensureProductVariantsTable();
        $db->query('CREATE TABLE IF NOT EXISTS db_product_mshops (
            id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, mshop_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "active", UNIQUE(product_id, mshop_id)
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_tax_rates (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tax_class_id INTEGER, igst REAL,
            status TEXT NOT NULL DEFAULT "active"
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_hsn_sac_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT
        )');

        foreach (['mfg_pos_sales', 'mfg_pos_sale_items', 'mfg_pos_sale_payments', 'mfg_pos_sequence',
            'mfg_inventory', 'mfg_inventory_ledger', 'mshops',
            'products', 'product_variants', 'product_mshops', 'tax_rates', 'hsn_sac_codes'] as $t) {
            $db->table($t)->truncate();
        }

        $db->query('INSERT INTO db_mshops (id, vendor_id, name, code, status) VALUES (?, ?, ?, ?, ?)', [self::UNIT, self::MINE, 'Bhiwandi Plant', 'BHW', 'active']);
        $db->query('INSERT INTO db_hsn_sac_codes (id, code) VALUES (1, ?)', ['7318']);
        $db->query('INSERT INTO db_tax_rates (id, tax_class_id, igst, status) VALUES (1, 4, 18.0, ?)', ['active']);
        // 77 = ours at unit 11.  88 = another manufacturer's, also "at unit 11".
        $db->query('INSERT INTO db_products (id, vendor_id, title, tax_class_id, hsn_id, status) VALUES (77, ?, ?, 4, 1, ?)', [self::MINE, 'M8 Bolt', 'published']);
        $db->query('INSERT INTO db_products (id, vendor_id, title, tax_class_id, hsn_id, status) VALUES (88, ?, ?, 4, 1, ?)', [self::THEIRS, 'Their Bolt', 'published']);
        $db->query('INSERT INTO db_product_variants (id, product_id, sku, is_default, making_price, base_price) VALUES (5, 77, ?, 1, 40.0, 118.0)', ['B-1']);
        $db->query('INSERT INTO db_product_variants (id, product_id, sku, is_default, making_price, base_price) VALUES (9, 88, ?, 1, 40.0, 118.0)', ['T-1']);
        $db->query('INSERT INTO db_product_mshops (product_id, mshop_id, status) VALUES (77, ?, ?)', [self::UNIT, 'active']);
        $db->query('INSERT INTO db_product_mshops (product_id, mshop_id, status) VALUES (88, ?, ?)', [self::UNIT, 'active']);

        $this->inv = new ManufacturerInventoryService();
        Services::injectMock('manufacturerInventoryService', $this->inv);
        $this->inv->produce(5, self::UNIT, 100.0, 40.0, [], 1);

        $this->repo = new ManufacturerPosSaleRepository();
    }

    protected function tearDown(): void
    {
        $this->dropMfgPosTables();
        $this->dropMfgInventoryTables();
        $this->dropMshopsTable();
        $this->dropUsersTable();
        foreach (['db_products', 'db_product_variants', 'db_product_mshops', 'db_tax_rates', 'db_hsn_sac_codes'] as $t) {
            $this->schemaConn()->query('DROP TABLE IF EXISTS ' . $t);
        }
        Services::reset();
        parent::tearDown();
    }

    /** @param list<array<string,mixed>> $cart */
    private function sell(array $cart, array $payments = [['tender_type' => 'cash', 'amount' => 1000]], array $opts = []): array
    {
        $lines = $this->repo->resolveLines(self::MINE, $cart, self::UNIT);

        return $this->repo->createSale(
            ['mshop_id' => self::UNIT, 'vendor_id' => self::MINE, 'cashier_user_id' => 1],
            $lines,
            $payments,
            $opts,
        );
    }

    // ------------------------------------------------------------------- resolve

    /** Prices come from the DATABASE — a cart cannot set its own. */
    public function testResolveLinesTakesPricesFromTheDatabase(): void
    {
        $lines = $this->repo->resolveLines(self::MINE, [
            ['variant_id' => 5, 'qty' => 2, 'unit_price' => 1],   // 1 rupee, ignored
        ], self::UNIT);

        $this->assertCount(1, $lines);
        $this->assertSame(118.0, $lines[0]['unit_price'], 'the posted price must be ignored');
        $this->assertSame(18.0, $lines[0]['tax_rate']);
        $this->assertSame('7318', $lines[0]['hsn']);
    }

    /** Another manufacturer's variant must not resolve, even at "the same" unit. */
    public function testResolveLinesRefusesAnotherTenantsVariant(): void
    {
        $this->assertSame([], $this->repo->resolveLines(self::MINE, [['variant_id' => 9, 'qty' => 1]], self::UNIT));
    }

    /** A variant not listed at this unit must not resolve. */
    public function testResolveLinesRefusesAnUnlistedVariant(): void
    {
        $this->schemaConn()->table('product_mshops')->where('product_id', 77)->delete();

        $this->assertSame([], $this->repo->resolveLines(self::MINE, [['variant_id' => 5, 'qty' => 1]], self::UNIT));
    }

    // ---------------------------------------------------------------------- sale

    public function testASaleRecordsHeaderLinesAndPayment(): void
    {
        $res = $this->sell([['variant_id' => 5, 'qty' => 2]]);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(236.0, $res['grand_total'], '2 × 118 inclusive');

        $db   = $this->schemaConn();
        $sale = $db->table('mfg_pos_sales')->where('id', $res['sale_id'])->get()->getRowArray();
        $this->assertSame(self::MINE, (int) $sale['vendor_id']);
        $this->assertSame(self::UNIT, (int) $sale['mshop_id']);
        $this->assertSame(1, $db->table('mfg_pos_sale_items')->where('mfg_pos_sale_id', $res['sale_id'])->countAllResults());
        $this->assertSame(1, $db->table('mfg_pos_sale_payments')->where('mfg_pos_sale_id', $res['sale_id'])->countAllResults());
    }

    /** GST is split out of an inclusive price, not added on top. */
    public function testTaxIsExtractedFromTheInclusivePrice(): void
    {
        $res  = $this->sell([['variant_id' => 5, 'qty' => 1]]);
        $sale = $this->schemaConn()->table('mfg_pos_sales')->where('id', $res['sale_id'])->get()->getRowArray();

        // 118 inclusive at 18% => 100 taxable, 9 CGST, 9 SGST.
        $this->assertSame(100.0, round((float) $sale['taxable_value'], 2));
        $this->assertSame(9.0, round((float) $sale['cgst'], 2));
        $this->assertSame(9.0, round((float) $sale['sgst'], 2));
        $this->assertSame(0.0, round((float) $sale['igst'], 2), 'a counter sale is intra-state');
    }

    /** The making price is snapshotted so outlet margin stays reportable. */
    public function testTheLineSnapshotsTheMakingPrice(): void
    {
        $res  = $this->sell([['variant_id' => 5, 'qty' => 1]]);
        $item = $this->schemaConn()->table('mfg_pos_sale_items')->where('mfg_pos_sale_id', $res['sale_id'])->get()->getRowArray();

        $this->assertSame(40.0, (float) $item['making_price_snapshot']);
    }

    // --------------------------------------------------------------------- stock

    public function testASaleDecrementsUnitStockAndLedgersIt(): void
    {
        $res = $this->sell([['variant_id' => 5, 'qty' => 3]]);

        $this->assertSame(97.0, (float) $this->inv->levels(5, self::UNIT)['on_hand']);

        $led = $this->inv->ledger(5, self::UNIT);
        $this->assertSame('sale', $led[0]['movement_type'], "'pos_sale' is not a member of this ledger's enum");
        $this->assertSame(-3.0, (float) $led[0]['qty']);
        $this->assertSame('mfg_pos_sale', $led[0]['ref_type']);
        $this->assertSame((int) $res['sale_id'], (int) $led[0]['ref_id'], 'the movement must name the sale');
    }

    /**
     * Selling more than is on hand is refused BEFORE anything is written.
     *
     * The payment deliberately COVERS the total, so short stock is the only thing left
     * that can refuse this sale. Tender it short and the underpayment guard rejects it
     * first, and the test passes whether or not the stock check exists at all.
     *
     * Nothing downstream would catch it either: bump() floors on_hand at max(0, …) and
     * never fails, so without this check the outlet prints a receipt for 500 bolts,
     * zeroes the balance and ledgers a -500 movement it cannot honour.
     */
    public function testASaleBeyondAvailableStockIsRefused(): void
    {
        $res = $this->sell([['variant_id' => 5, 'qty' => 500]], [['tender_type' => 'cash', 'amount' => 59000]]);

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('M8 Bolt', (string) $res['error'], 'the cashier needs to know which item is short');
        $this->assertStringContainsString('100', (string) $res['error'], '…and how many are on hand');
        $this->assertSame(0, $this->schemaConn()->table('mfg_pos_sales')->countAllResults(), 'nothing may be written');
        $this->assertSame(100.0, (float) $this->inv->levels(5, self::UNIT)['on_hand'], 'stock must be untouched');
        $this->assertCount(1, $this->inv->ledger(5, self::UNIT), 'only setUp’s production run may be on the ledger');
    }

    // ------------------------------------------------------------------ payment

    public function testUnderpaymentIsRefused(): void
    {
        $res = $this->sell([['variant_id' => 5, 'qty' => 2]], [['tender_type' => 'cash', 'amount' => 10]]);

        $this->assertFalse($res['ok']);
        $this->assertSame(0, $this->schemaConn()->table('mfg_pos_sales')->countAllResults());
    }

    public function testChangeIsReturned(): void
    {
        $res = $this->sell([['variant_id' => 5, 'qty' => 1]], [['tender_type' => 'cash', 'amount' => 200]]);

        $this->assertTrue($res['ok']);
        $this->assertSame(82.0, round($res['change'], 2), '200 - 118');
    }

    // --------------------------------------------------------------- numbering

    public function testInvoiceNumbersIncrementPerUnitAndYear(): void
    {
        $a = $this->sell([['variant_id' => 5, 'qty' => 1]]);
        $b = $this->sell([['variant_id' => 5, 'qty' => 1]]);

        $this->assertStringContainsString('BHW/', (string) $a['invoice_no'], "the unit's code prefixes the series");
        $this->assertStringEndsWith('000001', (string) $a['invoice_no']);
        $this->assertStringEndsWith('000002', (string) $b['invoice_no']);
    }

    /** A void sale must not free its number for reuse — the counter never goes back. */
    public function testAVoidedSaleDoesNotReleaseItsNumber(): void
    {
        $a = $this->sell([['variant_id' => 5, 'qty' => 1]]);
        $this->schemaConn()->table('mfg_pos_sales')->where('id', $a['sale_id'])->update(['status' => 'void']);

        $b = $this->sell([['variant_id' => 5, 'qty' => 1]]);
        $this->assertStringEndsWith('000002', (string) $b['invoice_no'], 'COUNT(*)+1 would have reissued 000001');
    }

    // -------------------------------------------------------------- idempotency

    public function testAResentSaleReturnsTheOriginal(): void
    {
        $a = $this->sell([['variant_id' => 5, 'qty' => 1]], [['tender_type' => 'cash', 'amount' => 200]], ['client_uuid' => 'abc-123']);
        $b = $this->sell([['variant_id' => 5, 'qty' => 1]], [['tender_type' => 'cash', 'amount' => 200]], ['client_uuid' => 'abc-123']);

        $this->assertTrue($b['ok']);
        $this->assertSame((int) $a['sale_id'], (int) $b['sale_id'], 'a double-tap must not record two sales');
        $this->assertSame(1, $this->schemaConn()->table('mfg_pos_sales')->countAllResults());
        $this->assertSame(99.0, (float) $this->inv->levels(5, self::UNIT)['on_hand'], 'stock must move once');
    }

    /**
     * The idempotency lookup must be TENANT-scoped. The vendor equivalent queries the
     * client id with no vendor predicate, so a colliding uuid returns another tenant's
     * sale — and its total.
     */
    public function testIdempotencyDoesNotLeakAcrossTenants(): void
    {
        $this->sell([['variant_id' => 5, 'qty' => 1]], [['tender_type' => 'cash', 'amount' => 200]], ['client_uuid' => 'shared-uuid']);

        // The same client uuid, arriving for a DIFFERENT manufacturer.
        $other = $this->repo->createSale(
            ['mshop_id' => self::UNIT, 'vendor_id' => self::THEIRS, 'cashier_user_id' => 1],
            [['variant_id' => 9, 'sku' => 'T-1', 'title' => 'Their Bolt', 'hsn' => '7318',
                'qty' => 1.0, 'unit_price' => 118.0, 'making_price' => 40.0, 'tax_rate' => 18.0, 'line_discount' => 0.0]],
            [['tender_type' => 'cash', 'amount' => 200]],
            ['client_uuid' => 'shared-uuid', 'allow_negative' => true],
        );

        $this->assertTrue($other['ok'], $other['error'] ?? '');
        $this->assertSame(2, $this->schemaConn()->table('mfg_pos_sales')->countAllResults(),
            "another tenant's identical client uuid must not resolve to this tenant's sale");
    }

    // ------------------------------------------------------------------ receipt

    public function testTheReceiptIsTenantScopedAndCarriesItsBuckets(): void
    {
        $res = $this->sell([['variant_id' => 5, 'qty' => 2]]);

        $this->assertNull($this->repo->findForReceipt((int) $res['sale_id'], self::THEIRS), 'another tenant must not read this receipt');

        $sale = $this->repo->findForReceipt((int) $res['sale_id'], self::MINE);
        $this->assertNotNull($sale);
        $this->assertSame('Bhiwandi Plant', $sale['unit_name']);
        $this->assertCount(1, $sale['items']);
        $this->assertCount(1, $sale['tax_buckets']);
        $this->assertSame(18.0, (float) $sale['tax_buckets'][0]['rate']);
    }

    public function testAnEmptyCartIsRefused(): void
    {
        $res = $this->repo->createSale(
            ['mshop_id' => self::UNIT, 'vendor_id' => self::MINE, 'cashier_user_id' => 1],
            [],
            [['tender_type' => 'cash', 'amount' => 100]],
        );

        $this->assertFalse($res['ok']);
    }
}
