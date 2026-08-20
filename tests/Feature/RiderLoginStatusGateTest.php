<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

require_once __DIR__ . '/../_support/MinimalSchema.php';

/**
 * Vendor status lifecycle, phase 6: RiderAuthFilter.
 *
 * Two checks land together here, sharing one query (RiderRepository::profile()):
 *
 * 1. A PRE-EXISTING gap, unrelated to vendor status but fixed alongside it since it's
 *    the same code and the same query: this filter never re-checked ANYTHING about
 *    the rider's account per request — unlike WebAuthFilter's equivalent staff
 *    re-check. A suspended rider's web session simply kept working indefinitely.
 * 2. The vendor-status gate itself, same as phases 4-5.
 *
 * Both stay log-only until vendor.enforceStatusGate is set; see VendorStatusGateTest.
 */
final class RiderLoginStatusGateTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('vendor.enforceStatusGate');
        service('superglobals')->setServer('HTTP_HOST', 'rider.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');

        $this->ensureUsersTable();
        $this->ensureVendorsTable();
        $this->ensureDeliveryTables();
        $db = $this->schemaConn();
        $db->table('vendors')->where('id', 9001)->delete();
        $db->table('vendors')->insert(['id' => 9001, 'legal_name' => 'x', 'display_name' => 'Speedy Co', 'status' => 'active']);
        $db->table('delivery_boys')->where('id', 501)->delete();
        $db->table('delivery_boys')->insert(['id' => 501, 'user_id' => 77, 'vendor_id' => 9001, 'status' => 'active']);

        // The DOWNSTREAM controller (Rider\DocumentController::index()) is mocked out
        // rather than fixtured — this test is about the FILTER, and DashboardController
        // (the more obvious route) pulls in orders/sub_orders/deliveries just to render
        // its stats, none of which this test needs an opinion on.
        Services::injectMock('riderDocumentRepository', new class {
            public function forRider(int $id): array { return []; }
        });
    }

    protected function tearDown(): void
    {
        $db = $this->schemaConn();
        $db->table('delivery_boys')->where('id', 501)->delete();
        $db->table('vendors')->where('id', 9001)->delete();
        // A leaked (even empty) db_users flips OTHER files' fail-open WebAuthFilter
        // check to fail-closed: once the table exists, apiAuthRepository->isActive()
        // succeeds with "no row" instead of THROWING "no such table", which is what
        // made it fail open for files that never expected db_users to exist yet. Same
        // trap this project's own test suite has already been bitten by once — see
        // ManufacturerPosSaleTest's identical comment.
        $this->dropUsersTable();
        service('superglobals')->unsetServer('HTTP_HOST');
        putenv('vendor.enforceStatusGate');
        Services::reset();
        parent::tearDown();
    }

    private function sess(): array
    {
        return ['rider_id' => 77];
    }

    // ------------------------------------------------------------------ rider's own status

    public function testByDefaultASuspendedRiderStillReachesTheDashboard(): void
    {
        $this->schemaConn()->table('delivery_boys')->where('id', 501)->update(['status' => 'suspended']);

        $this->withSession($this->sess())->get('rider/documents')->assertStatus(200);
    }

    public function testWithTheFlagSetASuspendedRiderIsSignedOut(): void
    {
        $this->schemaConn()->table('delivery_boys')->where('id', 501)->update(['status' => 'suspended']);
        putenv('vendor.enforceStatusGate=true');

        $r = $this->withSession($this->sess())->get('rider/documents');

        $r->assertRedirectTo(site_url('rider/login'));
        $this->assertStringContainsString('no longer active', (string) session()->getFlashdata('error'));
    }

    public function testWithTheFlagSetAnActiveRiderStillReachesTheDashboard(): void
    {
        putenv('vendor.enforceStatusGate=true');

        $this->withSession($this->sess())->get('rider/documents')->assertStatus(200);
    }

    // ------------------------------------------------------------------ the rider's vendor status

    public function testByDefaultARiderOfASuspendedVendorStillReachesTheDashboard(): void
    {
        $this->schemaConn()->table('vendors')->where('id', 9001)->update(['status' => 'suspended']);

        $this->withSession($this->sess())->get('rider/documents')->assertStatus(200);
    }

    public function testWithTheFlagSetARiderOfASuspendedVendorIsSignedOut(): void
    {
        $this->schemaConn()->table('vendors')->where('id', 9001)->update(['status' => 'suspended']);
        putenv('vendor.enforceStatusGate=true');

        $this->withSession($this->sess())->get('rider/documents')->assertRedirectTo(site_url('rider/login'));
    }

    // ------------------------------------------------------------------ safety

    /**
     * A rider with no delivery_boys row (session present but nothing to check against)
     * must not be blocked — mirrors "fail open on the indeterminate case", the same
     * contract WebAuthFilter's own re-check uses for a DB fault.
     */
    public function testARiderSessionWithNoProfileRowIsNotBlocked(): void
    {
        putenv('vendor.enforceStatusGate=true');

        $this->withSession(['rider_id' => 999999])->get('rider/documents')->assertStatus(200);
    }

    public function testNoSessionAtAllStillRedirectsToLogin(): void
    {
        $this->get('rider/documents')->assertRedirectTo(site_url('rider/login'));
    }

    /**
     * The filter's own "no rider_id at all" check (pre-existing, untouched by this
     * phase) is redundant with BaseRiderController::requireRider(), which every rider
     * controller action ALSO calls independently — so a request with no session
     * redirects either way, and this feature test cannot tell which layer caught it.
     * Not chased further: it predates this phase and the redundancy is itself a
     * reasonable defense-in-depth, not a gap this work introduced.
     */
    public function testTheBlockedResponseDestroysTheSession(): void
    {
        $src   = (string) file_get_contents(APPPATH . 'Filters/RiderAuthFilter.php');
        $start = strpos($src, 'if (($enforcing && ! $riderOk) || $vendorBlocked) {');
        $this->assertNotFalse($start, 'the combined block condition was not found');
        $end = strpos($src, 'return null;', $start);

        $this->assertStringContainsString(
            'session()->destroy()',
            substr($src, $start, $end - $start),
            'a bare redirect leaves rider_id sitting in session for the next request to inherit',
        );
    }
}
