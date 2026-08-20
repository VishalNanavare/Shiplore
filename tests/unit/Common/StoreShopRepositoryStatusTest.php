<?php

declare(strict_types=1);

use App\Models\StoreShopRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * StoreShopRepository, wired to VendorStatusGate — Phase 4 of the vendor status/
 * lifecycle build (phases 1-3 shipped: schema fix, admin Activate/Deactivate,
 * the shared gate — nothing reading vendors.status for visibility until now).
 *
 * find() is the highest-risk gap this phase closes: it checked NEITHER the shop's own
 * status NOR its vendor's, so a direct/deep-linked URL to a deactivated shop rendered
 * fully — the customer-facing counterpart of the admin panel's own approve/reject
 * pattern of only hiding actions, never data. Fixed here, staged behind the SAME
 * log-only flag as the vendor check, because it is equally a new blocking behaviour.
 *
 * nearby() already checked s.status='active'; only the vendor half is new.
 *
 * Both stay log-only by default — see VendorStatusGateTest for the flag mechanics.
 */
final class StoreShopRepositoryStatusTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('vendor.enforceStatusGate');
        $this->ensureVendorsTable();
        $this->ensureShopsTable();
        $this->ensureBusinessTypesTable();

        $db = Database::connect();
        $db->table('vendors')->insert(['id' => 8001, 'legal_name' => 'x', 'display_name' => 'Active Vendor', 'status' => 'active']);
        $db->table('vendors')->insert(['id' => 8002, 'legal_name' => 'x', 'display_name' => 'Suspended Vendor', 'status' => 'suspended']);
        $db->table('shops')->insert(['id' => 9001, 'vendor_id' => 8001, 'name' => 'Shop A', 'status' => 'active']);
        $db->table('shops')->insert(['id' => 9002, 'vendor_id' => 8002, 'name' => 'Shop B (suspended vendor)', 'status' => 'active']);
        $db->table('shops')->insert(['id' => 9003, 'vendor_id' => 8001, 'name' => 'Shop C (shop itself inactive)', 'status' => 'inactive']);
    }

    protected function tearDown(): void
    {
        $db = Database::connect();
        $db->table('shops')->whereIn('id', [9001, 9002, 9003])->delete();
        $db->table('vendors')->whereIn('id', [8001, 8002])->delete();
        putenv('vendor.enforceStatusGate');
        Services::reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ find()

    public function testAShopWithAnActiveVendorIsFound(): void
    {
        $row = (new StoreShopRepository())->find(9001);

        $this->assertNotNull($row);
        $this->assertSame('Shop A', $row['name']);
    }

    /**
     * THE GAP THIS CLOSES. Before this, find() checked neither status at all — this
     * assertion would have failed against the unmodified method (it returned the row).
     */
    public function testByDefaultAShopWithASuspendedVendorStillReturnsARow(): void
    {
        // Log-only: the row still renders today. The check now runs and logs, but
        // nothing blocks until the operator opts in — see the enforcing test below.
        $row = (new StoreShopRepository())->find(9002);

        $this->assertNotNull($row, 'log-only must not change behaviour yet');
    }

    public function testWithTheFlagSetAShopWithASuspendedVendorIsHidden(): void
    {
        putenv('vendor.enforceStatusGate=true');

        $this->assertNull((new StoreShopRepository())->find(9002));
    }

    public function testWithTheFlagSetAnInactiveShopIsHiddenEvenWithAnActiveVendor(): void
    {
        putenv('vendor.enforceStatusGate=true');

        $this->assertNull((new StoreShopRepository())->find(9003), "the shop's own status must be checked too — this was the pre-existing gap");
    }

    public function testAMissingShopIsStillNull(): void
    {
        $this->assertNull((new StoreShopRepository())->find(999999));
    }

    // ------------------------------------------------------------------ nearby()

    public function testNearbyWithoutALocationExcludesASuspendedVendorsShopWhenEnforcing(): void
    {
        putenv('vendor.enforceStatusGate=true');

        $names = array_column((new StoreShopRepository())->nearby(null, null, 30), 'name');

        $this->assertNotContains('Shop B (suspended vendor)', $names);
        $this->assertContains('Shop A', $names);
    }

    public function testNearbyWithoutALocationStillIncludesItByDefault(): void
    {
        $names = array_column((new StoreShopRepository())->nearby(null, null, 30), 'name');

        $this->assertContains('Shop B (suspended vendor)', $names, 'log-only must not change behaviour yet');
    }

    /**
     * The LOCATION-scoped path cannot be executed against this test's SQLite database
     * at all — its bounding-box ORDER BY calls POW(), a function SQLite does not ship
     * (confirmed: "no such function: POW" against a real query). Source assertion,
     * matching the same untestable-in-SQLite class as StoreCatalogRepository's
     * MySQL-only USE INDEX hint.
     */
    public function testNearbyAppliesTheVendorFilterOnTheLocationScopedBranchToo(): void
    {
        $src   = (string) file_get_contents(APPPATH . 'Models/StoreShopRepository.php');
        $start = strpos($src, 'function nearby(');
        $end   = strpos($src, 'function nearbyShopIds(', $start);
        // Comments stripped before counting — this file's own doc comment mentions
        // "filterInactiveVendors() below", which would otherwise inflate the count and
        // let this test pass even with one of the two real calls deleted.
        $body = preg_replace('#//.*#', '', substr($src, $start, $end - $start));

        $this->assertSame(
            2,
            substr_count($body, 'filterInactiveVendors('),
            'nearby() must filter BOTH return paths — the null-location branch and the location-scoped bounding-box branch',
        );
    }
}
