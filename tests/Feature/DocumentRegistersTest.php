<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** X4 — invoice/CN registers (admin + vendor) and the commission hold queue. */
final class DocumentRegistersTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('invoiceRepository', new class {
            public function listForAdmin(?string $t = null, ?string $f = null, ?string $to = null): array
            {
                return [['id' => 1, 'invoice_no' => 'INV-S3/2026-27/00001', 'invoice_date' => '2026-06-12', 'doc_type' => 'tax_invoice', 'status' => 'generated', 'grand_total' => '900.0000', 'media_id' => null, 'sub_order_no' => 'SO-1', 'vendor' => 'Fresh Foods', 'shop' => 'Andheri']];
            }

            public function listForVendor(int $v, ?int $s = null): array
            {
                return [['id' => 1, 'invoice_no' => 'INV-S3/2026-27/00001', 'invoice_date' => '2026-06-12', 'doc_type' => 'tax_invoice', 'status' => 'generated', 'grand_total' => '900.0000', 'media_id' => null, 'sub_order_no' => 'SO-1', 'shop' => 'Andheri']];
            }
        });
        Services::injectMock('commissionLedgerRepository', new class {
            public function holdQueue(?int $v = null, string $s = 'on_hold'): array
            {
                return [['sub_order_id' => 9, 'sub_order_no' => 'SO-9', 'vendor_id' => 1, 'vendor' => 'Fresh Foods', 'commission' => '100.00', 'window_ends_at' => '2026-06-19 14:00:00', 'line_count' => 3, 'status' => 'on_hold']];
            }

            public function totalsByStatus(?int $v = null): array
            {
                return ['on_hold' => '100.00'];
            }
        });
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset(true);
        parent::tearDown();
    }

    /**
     * This file hits BOTH admin/... and vendor/... routes, so the host can't be fixed
     * once for the whole class — each test that makes a real request calls this with
     * its OWN panel. See PanelSubdomainIsolationTest / AdminAccessTest for why plain
     * $_SERVER assignment doesn't work and why tearDown() must unsetServer() (a
     * leaked host here would affect every test that runs after this file).
     */
    private function withHost(string $host): void
    {
        service('superglobals')->setServer('HTTP_HOST', $host);
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
    }

    private function grantAdmin(array $perms): void
    {
        Services::injectMock('capabilityRepository', new class ($perms) {
            public function __construct(private array $perms) {}

            public function loadAssignments(int $u): array
            {
                return [['permissions' => $this->perms, 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
    }

    private function adminSess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Admin', 'principal_type' => 'platform'];
    }

    public function testAdminInvoiceRegister(): void
    {
        $this->withHost('admin.shiplore.test');
        // payment.view is platform-scoped. It replaced the vendor-scoped
        // invoice.view this register used to gate on — see the negative case below.
        $this->grantAdmin(['payment.view']);

        $r = $this->withSession($this->adminSess())->get('admin/invoices');

        $r->assertStatus(200);
        $r->assertSee('INV-S3/2026-27/00001');
        $r->assertSee('Fresh Foods');
    }

    public function testAdminCommissionHoldQueue(): void
    {
        $this->withHost('admin.shiplore.test');
        $this->grantAdmin(['commission.view']);

        $r = $this->withSession($this->adminSess())->get('admin/commission-holds');

        $r->assertStatus(200);
        $r->assertSee('SO-9');
        $r->assertSee('100.00');
    }

    /**
     * The platform-wide registers must NOT open to a vendor-scoped permission.
     *
     * invoice.view / creditnote.view / commission.hold.view are all scope_class
     * 'vendor' in database/sql/11_seed.sql and are granted to vendor_manager and
     * vendor_finance_viewer. Because the admin route group carries only `webAuth`
     * (which does not distinguish an admin from a vendor staffer) and
     * PolicyEngine::can() ignores scope, gating these registers on those codes let
     * any such vendor user read every vendor's invoices and commission holds.
     *
     * @dataProvider vendorScopedDocumentPermissions
     */
    public function testVendorScopedPermissionCannotOpenAdminRegisters(string $perm, string $url): void
    {
        $this->withHost('admin.shiplore.test');
        $this->grantAdmin([$perm]);

        $r = $this->withSession($this->adminSess())->get($url);

        $r->assertStatus(302);
        $r->assertRedirectTo(site_url('admin/dashboard'));
    }

    public static function vendorScopedDocumentPermissions(): array
    {
        return [
            'invoice register'    => ['invoice.view', 'admin/invoices'],
            'credit-note register' => ['creditnote.view', 'admin/credit-notes'],
            'commission holds'    => ['commission.hold.view', 'admin/commission-holds'],
        ];
    }

    public function testVendorInvoiceRegisterIsTenantScoped(): void
    {
        $this->withHost('vendor.shiplore.test');
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array
            {
                return [['permissions' => [], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []]];
            }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1, 'display_name' => 'Fresh Foods']; }
            public function findStaffVendor(int $u): ?array { return null; }
            public function shopIdsForVendor(int $v): array { return [3]; }
            public function shopIdsForStaff(int $vs): array { return []; }
        });
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 3, 'name' => 'Andheri']]; }
        });

        $r = $this->withSession(['isLoggedIn' => true, 'user_id' => 50, 'user_name' => 'Owner', 'principal_type' => 'vendor'])
            ->get('vendor/invoices');

        $r->assertStatus(200);
        $r->assertSee('INV-S3/2026-27/00001');
    }
}
