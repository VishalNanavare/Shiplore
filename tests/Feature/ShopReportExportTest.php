<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * S5 — a branch manager exports their store's POS sales report (no approval),
 * gated by report.export and scoped to their shop.
 */
final class ShopReportExportTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'vendor.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        Services::injectMock('posReportRepository', new class {
            public ?int $scopedShop = -99;
            public function byDay(int $v, ?int $shopId, string $from, string $to): array
            {
                $this->scopedShop = $shopId;
                return [['day' => '2026-06-10', 'bills' => 4, 'sales' => '5200.00'], ['day' => '2026-06-11', 'bills' => 2, 'sales' => '1800.00']];
            }
        });
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset(true);
        parent::tearDown();
    }

    private function asManager(array $perms = ['report.export']): void
    {
        Services::injectMock('capabilityRepository', new class ($perms) {
            public function __construct(private array $perms) {}
            public function loadAssignments(int $u): array { return [['permissions' => $this->perms, 'scope_type' => 'shop', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return null; }
            public function findStaffVendor(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate', 'vendor_staff_id' => 7, 'staff_type' => 'branch_manager']; }
            public function shopIdsForStaff(int $vs): array { return [1]; }
            public function shopIdsForVendor(int $v): array { return [1]; }
        });
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 1, 'name' => 'Andheri']]; }
        });
    }

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 110, 'user_name' => 'Sunil', 'principal_type' => 'vendor'];
    }

    public function testManagerExportsOwnStoreCsv(): void
    {
        $this->asManager();

        $r    = $this->withSession($this->sess())->get('vendor/pos/reports/export?from=2026-06-01&to=2026-06-12');
        $body = (string) $r->getBody();

        $this->assertStringContainsString('date,bills,sales', $body);
        $this->assertStringContainsString('2026-06-10,4,5200.00', $body);
        $this->assertSame(1, service('posReportRepository')->scopedShop, 'export is scoped to the manager\'s shop');
    }

    public function testExportDeniedWithoutPermission(): void
    {
        $this->asManager([]); // no report.export

        $this->withSession($this->sess())->get('vendor/pos/reports/export')->assertRedirect();
    }
}
