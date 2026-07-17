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
        Services::reset(true);
        parent::tearDown();
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
        $this->grantAdmin(['invoice.view']);

        $r = $this->withSession($this->adminSess())->get('admin/invoices');

        $r->assertStatus(200);
        $r->assertSee('INV-S3/2026-27/00001');
        $r->assertSee('Fresh Foods');
    }

    public function testAdminCommissionHoldQueue(): void
    {
        $this->grantAdmin(['commission.hold.view']);

        $r = $this->withSession($this->adminSess())->get('admin/commission-holds');

        $r->assertStatus(200);
        $r->assertSee('SO-9');
        $r->assertSee('100.00');
    }

    public function testVendorInvoiceRegisterIsTenantScoped(): void
    {
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
