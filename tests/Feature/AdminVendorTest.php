<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Phase 6 — Admin Vendor management: list (RBAC-guarded), approve (CSRF), and
 * permission-denied. Repositories mocked; webAuth session simulated.
 */
final class AdminVendorTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        $this->grant(['vendor.view', 'vendor.approve', 'vendor.reject']);

        Services::injectMock('vendorRepository', new class {
            public function list(array $f = []): array
            {
                return [
                    ['id' => 1, 'display_name' => 'Acme Foods', 'slug' => 'acme-foods', 'gstin' => '27AAAAA0000A1Z5', 'gstin_status' => 'verified', 'status' => 'submitted', 'created_at' => '2026-01-01 00:00:00'],
                    ['id' => 2, 'display_name' => 'Bright Retail', 'slug' => 'bright-retail', 'gstin' => '29BBBBB1111B2Z6', 'gstin_status' => 'pending', 'status' => 'active', 'created_at' => '2026-02-01 00:00:00'],
                ];
            }
            public function countList(array $f = []): int { return 2; }
            public function findById(int $id): ?array { return $id === 1 ? ['id' => 1, 'status' => 'submitted'] : null; }
            public function updateStatus(int $id, string $status, ?int $actorId = null): bool { return true; }
        });
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    /** Mock the capability loader used by WebAuthFilter to build ScopeContext. */
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
        $this->get('admin/vendors')->assertRedirect();
    }

    public function testListRendersForPermittedAdmin(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/vendors');
        $result->assertStatus(200);
        $body = (string) $result->getBody();
        $this->assertStringContainsString('Acme Foods', $body);
        $this->assertStringContainsString('Bright Retail', $body);
        $this->assertStringContainsString('vendorsTable', $body);
    }

    public function testApproveRedirectsBackToList(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/vendors/1/approve', $data);
        $result->assertRedirect();
        $this->assertStringContainsString('admin/vendors', $result->getRedirectUrl());
    }

    public function testPermissionDeniedRedirectsToDashboard(): void
    {
        $this->grant(['order.view']); // lacks vendor.view
        $result = $this->withSession($this->adminSession())->get('admin/vendors');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $result->getRedirectUrl());
    }
}
