<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Phase 6 — Admin Shop management: list (RBAC-guarded), activate (CSRF),
 * permission-denied. Repositories mocked; webAuth session simulated.
 */
final class AdminShopTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grant(['shop.view', 'shop.update']);

        Services::injectMock('shopRepository', new class {
            public function list(?string $status = null): array
            {
                return [
                    ['id' => 1, 'name' => 'Andheri Outlet', 'code' => 'AND-1', 'pincode' => '400058', 'state_code' => '27', 'gstin_status' => 'verified', 'status' => 'inactive', 'vendor' => 'Acme Foods'],
                    ['id' => 2, 'name' => 'Bandra Outlet', 'code' => 'BAN-1', 'pincode' => '400050', 'state_code' => '27', 'gstin_status' => 'pending', 'status' => 'active', 'vendor' => 'Acme Foods'],
                ];
            }
            public function findById(int $id): ?array { return $id === 1 ? ['id' => 1, 'status' => 'inactive'] : null; }
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

    public function testListRequiresLogin(): void
    {
        $this->get('admin/shops')->assertRedirect();
    }

    public function testListRenders(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/shops');
        $result->assertStatus(200);
        $this->assertStringContainsString('Andheri Outlet', (string) $result->getBody());
        $this->assertStringContainsString('shopsTable', (string) $result->getBody());
    }

    public function testActivateRedirectsBackToList(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/shops/1/activate', $data);
        $result->assertRedirect();
        $this->assertStringContainsString('admin/shops', $result->getRedirectUrl());
    }

    public function testPermissionDeniedRedirectsToDashboard(): void
    {
        $this->grant(['order.view']); // lacks shop.view
        $result = $this->withSession($this->adminSession())->get('admin/shops');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $result->getRedirectUrl());
    }
}
