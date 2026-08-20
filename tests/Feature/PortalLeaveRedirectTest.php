<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;

require_once __DIR__ . '/../_support/MinimalSchema.php';

/**
 * PortalController::leave() — sub-project C of the vendor/shop panel UX overhaul.
 * Before this, "Exit" always landed on admin/dashboard no matter which portal was
 * entered, so returning from a shop reached by drilling into a vendor meant losing
 * that vendor's context entirely. impersonation_kind + impersonation_entity_id are
 * already stashed in session (see PortalController::startStaffImpersonation()) and
 * are enough to compute a specific landing page — no new session key needed.
 *
 * Rider is deliberately unchanged: it is a separate session branch with no vendor
 * detail-page analog, and was never part of this ask.
 */
final class PortalLeaveRedirectTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        $this->ensureShopsTable();
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => [], 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    /** @param array<string,mixed> $extra */
    private function impersonating(string $host, string $kind, int $entityId, array $extra = []): array
    {
        service('superglobals')->setServer('HTTP_HOST', $host);

        return [
            'isLoggedIn' => true, 'user_id' => 42, 'user_name' => 'Vendor Owner', 'principal_type' => 'vendor',
            'is_impersonating' => true, 'impersonator_id' => 7, 'impersonator_name' => 'Admin',
            'impersonation_kind' => $kind, 'impersonation_label' => 'x', 'impersonation_entity_id' => $entityId,
        ] + $extra;
    }

    private function leave(array $session): \CodeIgniter\Test\TestResponse
    {
        return $this->withSession($session)->post('admin/portal/leave', [csrf_token() => csrf_hash()]);
    }

    public function testLeavingAVendorImpersonationReturnsToThatVendorsDetailPage(): void
    {
        $r = $this->leave($this->impersonating('vendor.shiplore.test', 'vendor', 501));

        $this->assertStringContainsString('admin/vendors/501', (string) $r->getRedirectUrl());
    }

    public function testLeavingAManufacturerImpersonationReturnsToThatManufacturersDetailPage(): void
    {
        $r = $this->leave($this->impersonating('manufacturer.shiplore.test', 'manufacturer', 9));

        $this->assertStringContainsString('admin/manufacturers/9', (string) $r->getRedirectUrl());
    }

    public function testLeavingAShopImpersonationReturnsToTheOwningVendorsDetailPage(): void
    {
        Database::connect()->table('shops')->insert(['id' => 8801, 'vendor_id' => 601, 'name' => 'Bandra Outlet', 'status' => 'active']);

        $r = $this->leave($this->impersonating('vendor.shiplore.test', 'shop', 8801));

        $this->assertStringContainsString('admin/vendors/601', (string) $r->getRedirectUrl());
        Database::connect()->table('shops')->where('id', 8801)->delete();
    }

    public function testLeavingAShopWhoseVendorCannotBeResolvedFallsBackToDashboard(): void
    {
        $r = $this->leave($this->impersonating('vendor.shiplore.test', 'shop', 999999));

        $this->assertStringContainsString('admin/dashboard', (string) $r->getRedirectUrl());
    }

    public function testLeavingARiderImpersonationStillGoesToTheDashboard(): void
    {
        $session = $this->impersonating('rider.shiplore.test', 'rider', 55, ['rider_id' => 42, 'rider_name' => 'Raju']);

        $r = $this->leave($session);

        $this->assertStringContainsString('admin/dashboard', (string) $r->getRedirectUrl());
    }
}
