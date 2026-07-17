<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Batch 6 — access control: roles list + permission matrix, super-admin lockout
 * protection, staff-user create form. Repos mocked.
 */
final class AdminAccessTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function grant(array $perms): void
    {
        Services::injectMock('capabilityRepository', new class ($perms) {
            public function __construct(private array $perms) {}
            public function loadAssignments(int $u): array
            {
                return [['permissions' => $this->perms, 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
    }

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Admin', 'principal_type' => 'platform'];
    }

    private function roleMock(string $code = 'ops_manager'): void
    {
        Services::injectMock('roleRepository', new class ($code) {
            public function __construct(private string $code) {}
            public function list(): array { return [['id' => 3, 'code' => 'ops_manager', 'name' => 'Ops', 'scope_class' => 'platform', 'is_system' => 1, 'perms' => 12]]; }
            public function find(int $id): ?array { return ['id' => $id, 'code' => $this->code, 'name' => 'Role']; }
            public function permissionsByModule(): array { return ['order' => [['id' => 1, 'code' => 'order.view', 'module' => 'order', 'description' => '']]]; }
            public function assignedPermissionIds(int $id): array { return [1]; }
            public function syncPermissions(int $id, array $ids): void {}
        });
    }

    public function testRolesIndexRenders(): void
    {
        $this->grant(['role.view']);
        $this->roleMock();
        $r = $this->withSession($this->sess())->get('admin/roles');
        $r->assertStatus(200);
        $this->assertStringContainsString('Roles &amp; Permissions', (string) $r->getBody());
    }

    public function testRoleEditRendersMatrix(): void
    {
        $this->grant(['role.update']);
        $this->roleMock();
        $r = $this->withSession($this->sess())->get('admin/roles/3/edit');
        $r->assertStatus(200);
        $this->assertStringContainsString('order.view', (string) $r->getBody());
    }

    public function testSuperAdminRoleProtectedFromEdit(): void
    {
        $this->grant(['role.update']);
        $this->roleMock('super_admin');
        $r = $this->withSession(service('session')->get() + $this->sess())
            ->post('admin/roles/1/update', [csrf_token() => csrf_hash(), 'perm_ids' => [1, 2]]);
        $r->assertRedirect();
        $this->assertStringContainsString('admin/roles', $r->getRedirectUrl());
    }

    public function testUserNewFormRenders(): void
    {
        $this->grant(['user.create']);
        Services::injectMock('adminUserRepository', new class {
            public function platformRoles(): array { return [['id' => 3, 'name' => 'Ops']]; }
        });
        $r = $this->withSession($this->sess())->get('admin/users/new');
        $r->assertStatus(200);
        $this->assertStringContainsString('Create user', (string) $r->getBody());
    }

    public function testUsersDeniedWithoutPermission(): void
    {
        $this->grant(['dashboard.view']);
        Services::injectMock('adminUserRepository', new class {
            public function list(): array { return []; }
        });
        $this->withSession($this->sess())->get('admin/users')->assertRedirect();
    }
}
