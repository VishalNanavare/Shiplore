<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** Phase 6 — Admin Customers: list + detail (RBAC), block (CSRF), denied. */
final class AdminCustomerTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grant(['customer.view', 'customer.update']);

        Services::injectMock('customerRepository', new class {
            public function list(?string $status = null): array
            {
                return [['id' => 1, 'name' => 'Aarav Sharma', 'email' => 'aarav@example.com', 'phone' => '+919800000000', 'lifetime_value' => '12450.0000', 'status' => 'active', 'created_at' => '2026-01-10 09:00:00']];
            }
            public function findById(int $id): ?array
            {
                return $id === 1 ? ['id' => 1, 'name' => 'Aarav Sharma', 'email' => 'aarav@example.com', 'phone' => '+919800000000', 'lifetime_value' => '12450.0000', 'status' => 'active', 'created_at' => '2026-01-10 09:00:00'] : null;
            }
            public function recentOrders(int $customerId, int $limit = 10): array
            {
                return [['id' => 1, 'order_no' => 'ORD-1001', 'grand_total' => '2450.0000', 'payment_status' => 'paid', 'status' => 'completed', 'created_at' => '2026-06-08 10:00:00']];
            }
            public function updateStatus(int $id, string $status, ?int $actorId = null): bool { return true; }
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
        $result = $this->withSession($this->adminSession())->get('admin/customers');
        $result->assertStatus(200);
        $this->assertStringContainsString('customersTable', (string) $result->getBody());
        $this->assertStringContainsString('Aarav Sharma', (string) $result->getBody());
    }

    public function testDetailRenders(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/customers/1');
        $result->assertStatus(200);
        $this->assertStringContainsString('ORD-1001', (string) $result->getBody());
    }

    public function testBlockRedirectsToDetail(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/customers/1/block', $data);
        $result->assertRedirect();
        $this->assertStringContainsString('admin/customers/1', $result->getRedirectUrl());
    }

    public function testPermissionDeniedRedirectsToDashboard(): void
    {
        $this->grant(['shop.view']);
        $result = $this->withSession($this->adminSession())->get('admin/customers');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $result->getRedirectUrl());
    }
}
