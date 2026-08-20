<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;

require_once __DIR__ . '/../_support/MinimalSchema.php';

/**
 * Vendor/shop panel UX, sub-project C — the vendor detail page's Shops card now
 * links straight to "View all shops" (scoped via ShopRepository's new vendor_id
 * filter) and gives each row a "Go to Shop Portal" action, closing the two-hop
 * path (vendor detail -> click a shop -> shop detail -> "Go to Shop Portal") the
 * operator reported.
 */
final class AdminVendorShopNavigationTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use MinimalSchema;

    private int $vendorId;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');

        $this->ensureVendorsTable();
        $this->ensureUsersTable();
        $this->ensureBusinessTypesTable();
        $this->ensureMediaAssetsTable();
        $this->ensureVendorDocumentsTable();
        $db = Database::connect();
        $db->table('vendors')->insert(['legal_name' => 'Acme Pvt Ltd', 'display_name' => 'Acme', 'business_type_id' => 0]);
        $this->vendorId = (int) $db->insertID();
        // WebAuthFilter re-checks the acting user is still active; the users table only
        // exists here because show()'s raw owner-name join needs it, so it must have a
        // row for the admin session or the fail-open re-check flips to fail-closed.
        $db->table('users')->insert(['id' => 1, 'name' => 'Admin', 'principal_type' => 'platform', 'status' => 'active']);

        $this->grant(['vendor.view']);
        Services::injectMock('vendorSettlementRepository', new class { public function list(int $id, int $limit): array { return []; } });
        Services::injectMock('vendorDashboardRepository', new class {
            public function metrics(int $id): array { return []; }
            public function recentOrders(int $id, int $limit): array { return []; }
        });
        Services::injectMock('vendorGstRepository', new class { public function summary(int $id): ?array { return null; } });
        Services::injectMock('vendorBankAccountRepository', new class { public function defaultForVendor(int $id): ?array { return null; } });
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $vendorId): array
            {
                return [['id' => 8901, 'name' => 'Bandra Outlet', 'code' => 'BAN-1', 'pincode' => '400050', 'delivery_radius_km' => 5, 'status' => 'active']];
            }
        });
    }

    protected function tearDown(): void
    {
        Database::connect()->table('vendors')->where('id', $this->vendorId)->delete();
        // ensureUsersTable() creates db_users for real in the process-wide :memory: DB —
        // left behind, it flips every other file's unmocked fail-open re-check
        // (WebAuthFilter -> apiAuthRepository->isActive()) to fail-closed. Must drop.
        $this->dropUsersTable();
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function grant(array $permissions): void
    {
        Services::injectMock('capabilityRepository', new class ($permissions) {
            public function __construct(private array $perms) {}
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => $this->perms, 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
    }

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Admin', 'principal_type' => 'platform'];
    }

    public function testShowLinksToAllShopsForThisVendor(): void
    {
        $r = $this->withSession($this->sess())->get('admin/vendors/' . $this->vendorId);

        $r->assertStatus(200);
        $this->assertStringContainsString('admin/shops?vendor_id=' . $this->vendorId, (string) $r->getBody());
    }

    public function testShowOffersGoToShopPortalForEachShopRow(): void
    {
        $r = $this->withSession($this->sess())->get('admin/vendors/' . $this->vendorId);

        $this->assertStringContainsString('admin/portal/enter/shop/8901', (string) $r->getBody());
    }
}
