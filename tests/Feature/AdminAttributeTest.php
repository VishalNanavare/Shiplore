<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** Phase 6 — Admin Attributes master: list (RBAC), activate (CSRF), denied. */
final class AdminAttributeTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        $this->grant(['attribute.view', 'attribute.manage']);

        Services::injectMock('attributeRepository', new class {
            public function list(?string $status = null): array
            {
                return [['id' => 1, 'code' => 'size', 'name' => 'Size', 'type' => 'select', 'is_variant_defining' => 1, 'status' => 'inactive']];
            }
            public function findById(int $id): ?array
            {
                return match ($id) {
                    1 => ['id' => 1, 'status' => 'inactive'],
                    2 => ['id' => 2, 'status' => 'active'],
                    default => null,
                };
            }
            public function updateStatus(int $id, string $status, ?int $actorId = null): bool { return true; }
            public function inUseBy(int $attributeId): array
            {
                return $attributeId === 2 ? ['Some Live Product'] : [];
            }
            public function delete(int $id, ?int $actorId = null): bool { return true; }
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
        $result = $this->withSession($this->adminSession())->get('admin/attributes');
        $result->assertStatus(200);
        $this->assertStringContainsString('attributesTable', (string) $result->getBody());
        $this->assertStringContainsString('Size', (string) $result->getBody());
    }

    public function testActivateRedirectsBackToList(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/attributes/1/activate', $data);
        $result->assertRedirect();
        $this->assertStringContainsString('admin/attributes', $result->getRedirectUrl());
    }

    public function testPermissionDeniedRedirectsToDashboard(): void
    {
        $this->grant(['shop.view']);
        $result = $this->withSession($this->adminSession())->get('admin/attributes');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $result->getRedirectUrl());
    }

    public function testDeleteRedirectsBackToListWhenNotInUse(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/attributes/1/delete', $data);
        $result->assertRedirect();
        $this->assertStringContainsString('admin/attributes', $result->getRedirectUrl());
    }

    public function testDeleteBlockedWhenAttributeInUse(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/attributes/2/delete', $data);
        $result->assertRedirect();
        $result->assertSessionHas('error');
        $error = (string) session('error');
        $this->assertStringContainsString('Some Live Product', $error);
    }

    public function testDeactivateBlockedWhenAttributeInUse(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/attributes/2/deactivate', $data);
        $result->assertRedirect();
        $result->assertSessionHas('error');
        $error = (string) session('error');
        $this->assertStringContainsString('Some Live Product', $error);
    }

    public function testDeactivateNotBlockedWhenAttributeUnused(): void
    {
        // id=1 is stubbed inUseBy() => [] (unused) and findById() => a real row, so this
        // exercises the success path through the same guard that blocks id=2 — proving
        // the guard actually discriminates, not just "every deactivate now errors."
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/attributes/1/deactivate', $data);
        $result->assertRedirect();
        $result->assertSessionHas('success');
    }
}
