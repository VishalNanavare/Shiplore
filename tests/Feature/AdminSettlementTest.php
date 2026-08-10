<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** Phase 6 — Admin Settlements: list + detail (RBAC), approve (CSRF), denied. */
final class AdminSettlementTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.in');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        $this->grant(['settlement.view', 'settlement.run']);

        Services::injectMock('settlementRepository', new class {
            public function list(?string $status = null): array
            {
                return [['id' => 1, 'vendor' => 'Fresh Foods', 'period_start' => '2026-05-01', 'period_end' => '2026-05-31', 'gross' => '420000.0000', 'commission_total' => '42000.0000', 'refund_total' => '1500.0000', 'net_payable' => '376500.0000', 'status' => 'calculated']];
            }
            public function findById(int $id): ?array
            {
                return $id === 1 ? ['id' => 1, 'vendor' => 'Fresh Foods', 'period_start' => '2026-05-01', 'period_end' => '2026-05-31', 'gross' => '420000.0000', 'commission_total' => '42000.0000', 'refund_total' => '1500.0000', 'tcs' => '0.0000', 'tds' => '0.0000', 'fees' => '0.0000', 'adjustments' => '0.0000', 'net_payable' => '376500.0000', 'status' => 'calculated'] : null;
            }
            public function lines(int $settlementId): array
            {
                return [['id' => 1, 'ref_type' => 'sale', 'ref_id' => 5, 'sub_order_id' => 5, 'amount' => '420000.0000', 'direction' => 'credit']];
            }
            public function updateStatus(int $id, string $status, ?int $actorId = null): bool { return true; }
        });
        Services::injectMock('shopRepository', new class {
            public function vendorsForSelect(): array { return [['id' => 1, 'display_name' => 'Fresh Foods']]; }
        });
        // show() also calls settlementAdjustmentRepository->forSettlement() directly —
        // real, unmocked, would hit "no such table: settlement_adjustments".
        Services::injectMock('settlementAdjustmentRepository', new class {
            public function forSettlement(int $settlementId): array { return []; }
        });
    }

    protected function tearDown(): void
    {
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

    private function adminSession(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Super Admin', 'principal_type' => 'platform'];
    }

    public function testListRenders(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/settlements');
        $result->assertStatus(200);
        $this->assertStringContainsString('settlementsTable', (string) $result->getBody());
        $this->assertStringContainsString('Fresh Foods', (string) $result->getBody());
    }

    public function testDetailRenders(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/settlements/1');
        $result->assertStatus(200);
        $this->assertStringContainsString('Net payable', (string) $result->getBody());
    }

    public function testApproveRedirectsToDetail(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/settlements/1/approve', $data);
        $result->assertRedirect();
        $this->assertStringContainsString('admin/settlements/1', $result->getRedirectUrl());
    }

    public function testPermissionDeniedRedirectsToDashboard(): void
    {
        $this->grant(['shop.view']);
        $result = $this->withSession($this->adminSession())->get('admin/settlements');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $result->getRedirectUrl());
    }
}
