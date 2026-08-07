<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** Phase 6 — Admin Payments: list + detail (RBAC-guarded), permission-denied. */
final class AdminPaymentTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.in');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        $this->grant(['payment.view']);

        Services::injectMock('paymentRepository', new class {
            public function list(?string $status = null): array
            {
                return [
                    ['id' => 1, 'gateway' => 'razorpay', 'method' => 'upi', 'amount' => '2450.0000', 'currency' => 'INR', 'status' => 'captured', 'gateway_ref' => 'pay_ABC123', 'captured_at' => '2026-06-08 10:03:00', 'created_at' => '2026-06-08 10:03:00', 'order_no' => 'ORD-1001'],
                ];
            }
            public function findById(int $id): ?array
            {
                return $id === 1 ? ['id' => 1, 'gateway' => 'razorpay', 'method' => 'upi', 'amount' => '2450.0000', 'currency' => 'INR', 'status' => 'captured', 'gateway_ref' => 'pay_ABC123', 'idempotency_key' => 'idem_1', 'captured_at' => '2026-06-08 10:03:00', 'order_no' => 'ORD-1001'] : null;
            }
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

    public function testListRequiresLogin(): void
    {
        $this->get('admin/payments')->assertRedirect();
    }

    public function testListRenders(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/payments');
        $result->assertStatus(200);
        $this->assertStringContainsString('pay_ABC123', (string) $result->getBody());
        $this->assertStringContainsString('paymentsTable', (string) $result->getBody());
    }

    public function testDetailRenders(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/payments/1');
        $result->assertStatus(200);
        $this->assertStringContainsString('ORD-1001', (string) $result->getBody());
    }

    public function testPermissionDeniedRedirectsToDashboard(): void
    {
        $this->grant(['shop.view']);
        $result = $this->withSession($this->adminSession())->get('admin/payments');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $result->getRedirectUrl());
    }
}
