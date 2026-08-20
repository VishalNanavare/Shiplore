<?php

declare(strict_types=1);

use App\Models\VendorRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * VendorRepository::list()/countList() accept an ARRAY of statuses, not just a single
 * string — needed by the new admin "Pending Approval → Vendor Approval" queue
 * (Admin\VendorApprovalController), which lists vendors in EITHER 'submitted' OR
 * 'under_review' in one query, mirroring how ProductApprovalRepository already
 * defines its own pending set. Every existing caller passes a plain string
 * (admin/vendors' own status filter dropdown) and must keep working unchanged.
 */
final class VendorRepositoryPendingFilterTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureVendorsTable();
        $this->ensureBusinessTypesTable();
        $db = Database::connect();
        $db->table('vendors')->insert(['id' => 91001, 'legal_name' => 'x', 'display_name' => 'Submitted Co', 'status' => 'submitted', 'party_type' => 'vendor']);
        $db->table('vendors')->insert(['id' => 91002, 'legal_name' => 'x', 'display_name' => 'Review Co', 'status' => 'under_review', 'party_type' => 'vendor']);
        $db->table('vendors')->insert(['id' => 91003, 'legal_name' => 'x', 'display_name' => 'Active Co', 'status' => 'active', 'party_type' => 'vendor']);
    }

    protected function tearDown(): void
    {
        Database::connect()->table('vendors')->whereIn('id', [91001, 91002, 91003])->delete();
        Services::reset();
        parent::tearDown();
    }

    public function testAnArrayOfStatusesMatchesAnyOfThem(): void
    {
        $repo = new VendorRepository();

        $rows = $repo->list(['status' => ['submitted', 'under_review']]);
        $names = array_column($rows, 'display_name');

        $this->assertContains('Submitted Co', $names);
        $this->assertContains('Review Co', $names);
        $this->assertNotContains('Active Co', $names, 'active must not appear in a submitted/under_review filter');
    }

    /**
     * Asserted as a DELTA, not an absolute count — the shared SQLite :memory: DB can
     * carry submitted/under_review rows left by unrelated files, so an exact count
     * would be exactly as flaky as the array-equality check above was.
     */
    public function testCountListAlsoAcceptsAnArray(): void
    {
        $repo   = new VendorRepository();
        $filter = ['status' => ['submitted', 'under_review']];
        $before = $repo->countList($filter);

        Database::connect()->table('vendors')->insert(['id' => 91004, 'legal_name' => 'x', 'display_name' => 'Extra Under Review', 'status' => 'under_review', 'party_type' => 'vendor']);

        $this->assertSame($before + 1, $repo->countList($filter));

        Database::connect()->table('vendors')->where('id', 91004)->delete();
    }

    /**
     * Existing single-string callers (admin/vendors' own status dropdown) must be
     * unaffected. Asserted by containment, not exact-array equality — the shared
     * SQLite :memory: DB can carry active vendor rows left by unrelated test files
     * (this file's own fixtures are scoped to ids 91001-91003 and cleaned in
     * tearDown; a leaked row from elsewhere is not this test's concern), and a strict
     * equality check flaked under --order-by=random for exactly that reason.
     */
    public function testAPlainStringStatusStillWorks(): void
    {
        $repo = new VendorRepository();

        $names = array_column($repo->list(['status' => 'active']), 'display_name');

        $this->assertContains('Active Co', $names);
        $this->assertNotContains('Submitted Co', $names);
        $this->assertNotContains('Review Co', $names);
    }
}
