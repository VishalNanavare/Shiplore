<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** Phase 6 — Admin Refunds: list (RBAC), process (CSRF), permission-denied. */
final class AdminRefundTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grant(['payment.view', 'payment.refund']);

        Services::injectMock('refundRepository', new class {
            public function list(?string $status = null): array
            {
                return [['id' => 1, 'amount' => '430.0000', 'destination' => 'source', 'status' => 'initiated', 'gateway_ref' => 'rfnd_1', 'created_at' => '2026-06-08 12:00:00', 'order_no' => 'ORD-1003', 'method' => 'upi']];
            }
            public function findById(int $id): ?array { return $id === 1 ? ['id' => 1, 'status' => 'initiated'] : null; }
            public function updateStatus(int $id, string $status, ?int $actorId = null): bool { return true; }
        });

        // process() now runs the full RefundService (ledger + GST credit note + notify)
        Services::injectMock('refundService', new class {
            public function complete(int $id, ?int $actorId = null): array { return ['ok' => true, 'credit_note_no' => 'CN/2026/00001']; }
        });
    }

    protected function tearDown(): void
    {
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
        $result = $this->withSession($this->adminSession())->get('admin/refunds');
        $result->assertStatus(200);
        $this->assertStringContainsString('refundsTable', (string) $result->getBody());
        $this->assertStringContainsString('ORD-1003', (string) $result->getBody());
    }

    public function testProcessRedirectsBackToList(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/refunds/1/process', $data);
        $result->assertRedirect();
        $this->assertStringContainsString('admin/refunds', $result->getRedirectUrl());
    }

    public function testPermissionDeniedRedirectsToDashboard(): void
    {
        $this->grant(['shop.view']);
        $result = $this->withSession($this->adminSession())->get('admin/refunds');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $result->getRedirectUrl());
    }
}
