<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

require_once __DIR__ . '/../_support/MinimalSchema.php';

/**
 * Phase 6 — Admin Product Approvals: review queue (RBAC-guarded), approve
 * (CSRF), permission-denied. Repository mocked; webAuth session simulated.
 */
final class AdminProductApprovalTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.in');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        $this->grant(['product.review', 'product.approve', 'product.reject']);
        // index() also calls shopRepository->vendorsForSelect() and
        // categoryRepository->options() directly — real, unmocked, would hit
        // "no such table: db_vendors"/"db_categories".
        $this->ensureVendorsTable();
        $this->ensureCategoriesTable();

        Services::injectMock('productApprovalRepository', new class {
            public function list(array $f = []): array
            {
                return [
                    ['id' => 1, 'title' => 'Organic Almonds 500g', 'slug' => 'organic-almonds-500g', 'status' => 'submitted', 'approval_id' => 11, 'created_at' => '2026-06-01 10:00:00', 'vendor' => 'Fresh Foods', 'category' => 'Grocery'],
                    ['id' => 2, 'title' => 'Running Shoes', 'slug' => 'running-shoes', 'status' => 'under_review', 'approval_id' => 12, 'created_at' => '2026-06-02 09:00:00', 'vendor' => 'Style Hub', 'category' => 'Footwear'],
                ];
            }
            public function countList(array $f = []): int { return 2; }
            public function findById(int $id): ?array { return $id === 1 ? ['id' => 1, 'status' => 'submitted', 'approval_id' => 11] : null; }
            public function approve(int $id, int $actorId): bool { return true; }
            public function reject(int $id, int $actorId, ?string $reason = null): bool { return true; }
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
        $this->get('admin/product-approvals')->assertRedirect();
    }

    public function testListRenders(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/product-approvals');
        $result->assertStatus(200);
        $this->assertStringContainsString('Organic Almonds 500g', (string) $result->getBody());
        $this->assertStringContainsString('approvalsTable', (string) $result->getBody());
    }

    public function testApproveRedirectsBackToList(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/product-approvals/1/approve', $data);
        $result->assertRedirect();
        $this->assertStringContainsString('admin/product-approvals', $result->getRedirectUrl());
    }

    public function testRejectUnknownProductShowsError(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/product-approvals/999/reject', $data);
        $result->assertRedirect();
        $this->assertStringContainsString('admin/product-approvals', $result->getRedirectUrl());
    }

    public function testPermissionDeniedRedirectsToDashboard(): void
    {
        $this->grant(['order.view']); // lacks product.review
        $result = $this->withSession($this->adminSession())->get('admin/product-approvals');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $result->getRedirectUrl());
    }
}
