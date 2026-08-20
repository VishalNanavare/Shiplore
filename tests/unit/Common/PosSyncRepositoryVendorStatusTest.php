<?php

declare(strict_types=1);

use App\Models\PosSyncRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * PosSyncRepository::terminal() actually selects the owning vendor's status.
 *
 * Every feature test of PosController mocks posSyncRepository entirely, so none of
 * them exercise this method's real SQL — a mutation run confirmed it: deleting
 * "v.status AS vendor_status" from the SELECT survived every PosVendorStatusGateTest
 * unchanged, because that file never runs the real query. This is the direct test that
 * closes the gap.
 */
final class PosSyncRepositoryVendorStatusTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureVendorsTable();
        $this->ensurePosTerminalsTable();

        $db = Database::connect();
        $db->table('vendors')->insert(['id' => 8501, 'legal_name' => 'x', 'display_name' => 'Suspended Co', 'status' => 'suspended']);
        $db->table('pos_terminals')->insert(['id' => 601, 'shop_id' => 1, 'vendor_id' => 8501, 'name' => 'T1', 'invoice_prefix' => 'ABC', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        $db = Database::connect();
        $db->table('pos_terminals')->where('id', 601)->delete();
        $db->table('vendors')->where('id', 8501)->delete();
        Services::reset();
        parent::tearDown();
    }

    public function testTerminalCarriesTheOwningVendorsStatus(): void
    {
        $row = (new PosSyncRepository())->terminal(601);

        $this->assertNotNull($row);
        $this->assertSame('suspended', $row['vendor_status'] ?? null, 'the join must select v.status AS vendor_status');
    }

    public function testAMissingTerminalIsStillNull(): void
    {
        $this->assertNull((new PosSyncRepository())->terminal(999999));
    }
}
