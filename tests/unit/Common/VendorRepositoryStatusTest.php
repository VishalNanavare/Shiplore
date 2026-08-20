<?php

declare(strict_types=1);

use App\Models\VendorRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * VendorRepository::updateStatus() only accepts vendors.status's legal enum values.
 *
 * This is the SINGLE write path for vendors.status — VendorController::transition()
 * (approve/reject, and now activate/deactivate) is its only caller. Before this,
 * updateStatus() passed any string straight through with no validation, and
 * VendorController::reject() had been writing 'rejected' for as long as it existed —
 * a value the schema's ENUM never legalised. MySQL's non-strict mode truncated the
 * out-of-range write to '' rather than erroring, so every reject click silently wrote
 * an empty status: invisible in the UI (renders as a blank badge) and unfindable by any
 * status filter. database/sql/84_vendor_status_lifecycle.sql fixes the schema; this
 * whitelist is the defense-in-depth half — the repository must not trust a caller (this
 * one or a future one) to only ever pass a legal value.
 */
final class VendorRepositoryStatusTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();

        // The shared fixture, not a bespoke CREATE TABLE. A narrower one here would
        // "win" the CREATE TABLE IF NOT EXISTS race if this file happened to run first
        // under --order-by=random, locking every later file into a schema missing
        // whatever columns THEY needed — exactly what happened the first time this test
        // was written: a private four-column vendors table left PosSyncTest failing with
        // "no such column: owner_user_id" only in randomised order, never in file order.
        $this->ensureVendorsTable();
        Database::connect()->table('vendors')->insert(['id' => 90001, 'status' => 'submitted', 'legal_name' => 'x', 'display_name' => 'x']);
    }

    protected function tearDown(): void
    {
        Database::connect()->table('vendors')->where('id', 90001)->delete();
        Services::reset();
        parent::tearDown();
    }

    private function currentStatus(int $id): string
    {
        return (string) Database::connect()->table('vendors')->where('id', $id)->get()->getRowArray()['status'];
    }

    /** @return list<string> every value the vendors.status ENUM legally holds, per 84_vendor_status_lifecycle.sql */
    private function legalValues(): array
    {
        return ['draft', 'submitted', 'under_review', 'approved', 'active', 'suspended', 'terminated', 'rejected'];
    }

    public function testEveryLegalStatusValueIsAccepted(): void
    {
        $repo = new VendorRepository();

        foreach ($this->legalValues() as $status) {
            $ok = $repo->updateStatus(90001, $status);

            $this->assertTrue($ok, "'{$status}' must be accepted — it is in the ENUM");
            $this->assertSame($status, $this->currentStatus(90001));
        }
    }

    /**
     * THE BUG THIS FIXES. Before the whitelist, 'rejected' passed straight through and
     * (on real MySQL) got silently truncated at the database layer — a failure this
     * SQLite-backed test cannot reproduce, which is exactly why the guard has to live in
     * PHP: it must refuse the value itself, not rely on catching what the database does
     * with an illegal one.
     */
    public function testRejectedIsAccepted(): void
    {
        $repo = new VendorRepository();

        $this->assertTrue($repo->updateStatus(90001, 'rejected'));
        $this->assertSame('rejected', $this->currentStatus(90001));
    }

    public function testAnUnknownStatusValueIsRefusedAndTheRowIsUntouched(): void
    {
        $repo = new VendorRepository();

        $ok = $repo->updateStatus(90001, 'banned');

        $this->assertFalse($ok, 'an out-of-whitelist value must be refused, not silently truncated');
        $this->assertSame('submitted', $this->currentStatus(90001), 'the previous status must survive intact');
    }

    public function testAnEmptyStatusValueIsRefused(): void
    {
        $repo = new VendorRepository();

        $this->assertFalse($repo->updateStatus(90001, ''));
        $this->assertSame('submitted', $this->currentStatus(90001));
    }
}
