<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** Phase 6 — Admin Categories master: list (RBAC), activate (CSRF), denied. */
final class AdminCategoryTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grant(['category.view', 'category.update']);

        Services::injectMock('categoryRepository', new class {
            public function list(?string $status = null): array
            {
                return [
                    ['id' => 1, 'name' => 'Footwear', 'slug' => 'footwear', 'level' => 0, 'parent_id' => null, 'default_commission_rate' => '12.00', 'status' => 'active', 'parent' => null, 'business_types' => 'Footwear'],
                    ['id' => 2, 'name' => "Men's Shoes", 'slug' => 'mens-shoes', 'level' => 1, 'parent_id' => 1, 'default_commission_rate' => '12.00', 'status' => 'inactive', 'parent' => 'Footwear', 'business_types' => 'Footwear'],
                ];
            }
            public function findById(int $id): ?array { return $id === 2 ? ['id' => 2, 'status' => 'inactive'] : null; }
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
        $result = $this->withSession($this->adminSession())->get('admin/categories');
        $result->assertStatus(200);
        $this->assertStringContainsString('categoriesTable', (string) $result->getBody());
        $this->assertStringContainsString("Men's Shoes", (string) $result->getBody());
    }

    public function testActivateRedirectsBackToList(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/categories/2/activate', $data);
        $result->assertRedirect();
        $this->assertStringContainsString('admin/categories', $result->getRedirectUrl());
    }

    public function testPermissionDeniedRedirectsToDashboard(): void
    {
        $this->grant(['shop.view']);
        $result = $this->withSession($this->adminSession())->get('admin/categories');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $result->getRedirectUrl());
    }
}
