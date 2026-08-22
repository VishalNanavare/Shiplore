<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

final class AdminCommissionRuleTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        $this->grant(['commission_rule.view', 'commission_rule.manage']);

        Services::injectMock('commissionRuleRepository', new class {
            public function listRules(): array
            {
                return [['id' => 1, 'category_id' => 5, 'product_id' => null, 'business_type_id' => null, 'rate' => '8.00', 'commission_type' => 'percentage', 'priority' => 0, 'min_gmv' => null, 'max_gmv' => null, 'fixed_amount' => null]];
            }
            public function findRule(int $id): ?array
            {
                return $id === 1 ? ['id' => 1, 'category_id' => 5, 'rate' => '8.00', 'commission_type' => 'percentage'] : null;
            }
            public function createRule(array $data, ?int $actorId): int { return 2; }
            public function updateRule(int $id, array $data, ?int $actorId): bool { return true; }
            public function deleteRule(int $id, ?int $actorId = null): bool { return true; }
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
        $result = $this->withSession($this->adminSession())->get('admin/commission-rules');
        $result->assertStatus(200);
        $this->assertStringContainsString('8.00', (string) $result->getBody());
    }

    public function testStoreCreatesRule(): void
    {
        $data    = [csrf_token() => csrf_hash(), 'category_id' => '5', 'rate' => '8.00', 'commission_type' => 'percentage', 'commission_plan_id' => '1', 'priority' => '0'];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/commission-rules/store', $data);
        $result->assertRedirect();
        $result->assertSessionHas('success');
    }

    public function testDeleteRedirectsBackToList(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/commission-rules/1/delete', $data);
        $result->assertRedirect();
        $result->assertSessionHas('success');
    }

    public function testPermissionDeniedRedirectsToDashboard(): void
    {
        $this->grant(['shop.view']);
        $result = $this->withSession($this->adminSession())->get('admin/commission-rules');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $result->getRedirectUrl());
    }
}
