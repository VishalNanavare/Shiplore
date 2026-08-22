<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Phase A1.3 — attribute-value management screen: list/create/edit values scoped to
 * one parent attribute, same in-use guard as A1.2 but per-value.
 */
final class AdminAttributeValueTest extends CIUnitTestCase
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
            public function findById(int $id): ?array
            {
                return match ($id) {
                    1 => ['id' => 1, 'code' => 'color', 'name' => 'Color', 'type' => 'select', 'status' => 'active'],
                    default => null,
                };
            }
        });

        Services::injectMock('attributeValueRepository', new class {
            public function listForAttribute(int $attributeId): array
            {
                return [
                    ['id' => 10, 'attribute_id' => 1, 'value' => 'Red', 'sort_order' => 0, 'status' => 'active'],
                    ['id' => 20, 'attribute_id' => 1, 'value' => 'Blue', 'sort_order' => 1, 'status' => 'inactive'],
                ];
            }
            public function findById(int $id): ?array
            {
                return match ($id) {
                    10 => ['id' => 10, 'attribute_id' => 1, 'value' => 'Red', 'status' => 'active'],
                    20 => ['id' => 20, 'attribute_id' => 1, 'value' => 'Blue', 'status' => 'inactive'],
                    default => null,
                };
            }
            public function create(array $data, ?int $actorId): int { return 30; }
            public function update(int $id, array $data, ?int $actorId): bool { return true; }
            public function updateStatus(int $id, string $status, ?int $actorId = null): bool { return true; }
            public function delete(int $id, ?int $actorId = null): bool { return true; }
            public function inUseBy(int $attributeValueId): array
            {
                return $attributeValueId === 20 ? ['Some Live Product'] : [];
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

    public function testListRendersValuesForAttribute(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/attributes/1/values');
        $result->assertStatus(200);
        $body = (string) $result->getBody();
        $this->assertStringContainsString('Red', $body);
        $this->assertStringContainsString('Blue', $body);
        $this->assertStringContainsString('Color', $body);
    }

    public function testListReturns404ForUnknownAttribute(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/attributes/999/values');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/attributes', $result->getRedirectUrl());
    }

    public function testStoreCreatesValueAndRedirectsToList(): void
    {
        $data    = [csrf_token() => csrf_hash(), 'value' => 'Green', 'sort_order' => '2'];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/attributes/1/values/store', $data);
        $result->assertRedirect();
        $this->assertStringContainsString('admin/attributes/1/values', $result->getRedirectUrl());
    }

    public function testDeactivateBlockedWhenValueInUse(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/attributes/1/values/20/deactivate', $data);
        $result->assertRedirect();
        $result->assertSessionHas('error');
        $this->assertStringContainsString('Some Live Product', (string) session('error'));
    }

    public function testDeactivateNotBlockedWhenValueUnused(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/attributes/1/values/10/deactivate', $data);
        $result->assertRedirect();
        $result->assertSessionHas('success');
    }

    public function testDeleteBlockedWhenValueInUse(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/attributes/1/values/20/delete', $data);
        $result->assertRedirect();
        $result->assertSessionHas('error');
        $this->assertStringContainsString('Some Live Product', (string) session('error'));
    }

    public function testDeleteRedirectsToListWhenNotInUse(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/attributes/1/values/10/delete', $data);
        $result->assertRedirect();
        $result->assertSessionHas('success');
    }

    public function testPermissionDeniedRedirectsToDashboard(): void
    {
        $this->grant(['shop.view']);
        $result = $this->withSession($this->adminSession())->get('admin/attributes/1/values');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $result->getRedirectUrl());
    }
}
