<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

final class AdminVendorCommissionOverrideTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        $this->grant(['vendor_commission_override.view', 'vendor_commission_override.manage']);

        Services::injectMock('commissionRuleRepository', new class {
            public function listOverrides(): array
            {
                return [['id' => 1, 'vendor_id' => 9, 'category_id' => null, 'rate' => '3.00', 'valid_from' => '2026-01-01', 'valid_to' => null, 'status' => 'active']];
            }
            public function findOverride(int $id): ?array
            {
                return $id === 1 ? ['id' => 1, 'vendor_id' => 9, 'rate' => '3.00', 'valid_from' => '2026-01-01', 'status' => 'active'] : null;
            }
            public function createOverride(array $data, ?int $actorId): int { return 2; }
            public function updateOverride(int $id, array $data, ?int $actorId): bool { return true; }
            public function deleteOverride(int $id, ?int $actorId = null): bool { return true; }
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
        $result = $this->withSession($this->adminSession())->get('admin/vendor-commission-overrides');
        $result->assertStatus(200);
        $this->assertStringContainsString('3.00', (string) $result->getBody());
    }

    public function testStoreCreatesOverride(): void
    {
        $data    = [csrf_token() => csrf_hash(), 'vendor_id' => '9', 'rate' => '3.00', 'valid_from' => '2026-01-01'];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/vendor-commission-overrides/store', $data);
        $result->assertRedirect();
        $result->assertSessionHas('success');
    }

    public function testDeleteRedirectsBackToList(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/vendor-commission-overrides/1/delete', $data);
        $result->assertRedirect();
        $result->assertSessionHas('success');
    }

    public function testPermissionDeniedRedirectsToDashboard(): void
    {
        $this->grant(['shop.view']);
        $result = $this->withSession($this->adminSession())->get('admin/vendor-commission-overrides');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $result->getRedirectUrl());
    }
}
