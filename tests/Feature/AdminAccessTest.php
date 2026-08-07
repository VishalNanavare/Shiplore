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

    /**
     * Every admin/... route now only registers when the request's Host header's
     * subdomain is 'admin' (app/Config/Routes.php 'subdomain' option — see
     * PanelSubdomainIsolationTest), so FeatureTestTrait's simulated requests need an
     * explicit admin host or they 404 exactly like a real request to the apex would.
     *
     * $_SERVER['HTTP_HOST'] alone does NOT work here: IncomingRequest reads server
     * vars through the Superglobals service (RequestTrait::getServer() ->
     * fetchGlobal('server', ...)), which snapshots its own copy rather than reading
     * $_SERVER live, and RouteCollection reads the host from service('request') at
     * ITS OWN construction time. Both services must be forced fresh after the
     * Superglobals value is set, or they keep whatever host was current when they
     * were first built for this process.
     *
     * tearDown() must undo this with unsetServer(), not just Services::reset():
     * Superglobals::setServer() writes straight into the real $_SERVER array (see
     * system/Superglobals.php), which Services::reset() does not touch — it only
     * drops cached service INSTANCES. Left unset, 'admin.shiplore.in' would leak into
     * every test that runs afterward in the same PHPUnit process, silently deciding
     * which panel's routes THEY see regardless of what they actually test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.in');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
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
            public function platformRoles(bool $includeSuperAdmin = false): array { return [['id' => 3, 'name' => 'Ops', 'code' => 'ops_manager']]; }
            public function hasSuperAdmin(int $userId): bool { return false; }
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
