<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Admin\ShopApprovalController — the "Pending Approval → Shop Approval" queue.
 * Mirrors AdminVendorApprovalTest's shape. The write logic itself
 * (ShopRepository::approve()/reject()) is covered by ShopRepositoryApprovalTest; this
 * file proves the controller wires permission, filtering and the right routes.
 */
final class AdminShopApprovalTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');

        Services::injectMock('shopRepository', new class {
            public ?array $lastFilters = null;
            public array $decisions   = [];

            public function list(array $f = [], int $limit = 50, int $offset = 0): array
            {
                $this->lastFilters = $f;

                return [['id' => 1, 'name' => 'Bandra Outlet', 'code' => 'BAN-01', 'pincode' => '400050', 'vendor' => 'Sole Mate', 'vendor_id' => 1, 'created_at' => '2026-08-01 00:00:00']];
            }

            public function countList(array $f = []): int
            {
                $this->lastFilters = $f;

                return 1;
            }

            public function approve(int $id, ?int $actorId = null): bool
            {
                $this->decisions[] = ['action' => 'approve', 'id' => $id, 'actorId' => $actorId];

                return true;
            }

            public function reject(int $id, ?int $actorId = null, ?string $reason = null): bool
            {
                $this->decisions[] = ['action' => 'reject', 'id' => $id, 'actorId' => $actorId, 'reason' => $reason];

                return true;
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

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 7, 'user_name' => 'Admin', 'principal_type' => 'platform'];
    }

    public function testTheQueueRendersForAPermittedAdmin(): void
    {
        $this->grant(['shop.approve']);

        $r = $this->withSession($this->sess())->get('admin/shop-approvals');

        $r->assertStatus(200);
        $this->assertStringContainsString('Bandra Outlet', (string) $r->getBody());
    }

    public function testTheQueueIsPermissionGated(): void
    {
        $this->grant(['some.other.permission']);

        $r = $this->withSession($this->sess())->get('admin/shop-approvals');

        $this->assertNotSame(200, $r->response()->getStatusCode());
    }

    public function testTheQueueFiltersToPendingApprovalStatusOnly(): void
    {
        $this->grant(['shop.approve']);
        $repo = service('shopRepository');

        $this->withSession($this->sess())->get('admin/shop-approvals');

        $this->assertSame('pending', $repo->lastFilters['approval_status'] ?? null);
    }

    public function testApprovePassesTheActingAdminsIdThrough(): void
    {
        $this->grant(['shop.approve']);
        $repo = service('shopRepository');

        $this->withSession($this->sess())->post('admin/shop-approvals/1/approve', [csrf_token() => csrf_hash()]);

        $this->assertSame([['action' => 'approve', 'id' => 1, 'actorId' => 7]], $repo->decisions);
    }

    public function testRejectIsPermissionGatedSeparatelyFromApprove(): void
    {
        $this->grant(['shop.approve']); // approve only, NOT shop.reject
        $repo = service('shopRepository');

        $this->withSession($this->sess())->post('admin/shop-approvals/1/reject', [csrf_token() => csrf_hash(), 'reason' => 'x']);

        $this->assertSame([], $repo->decisions, 'shop.approve must not also authorise reject');
    }

    public function testRejectPassesTheReasonThrough(): void
    {
        $this->grant(['shop.reject']);
        $repo = service('shopRepository');

        $this->withSession($this->sess())->post('admin/shop-approvals/1/reject', [csrf_token() => csrf_hash(), 'reason' => 'Address unverifiable']);

        $this->assertSame('Address unverifiable', $repo->decisions[0]['reason'] ?? null);
    }

    public function testApproveAndRejectFormsUseAjaxRefreshNotAFullRedirect(): void
    {
        $this->grant(['shop.approve']);

        $html = html_entity_decode((string) $this->withSession($this->sess())->get('admin/shop-approvals')->getBody(), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('admin/shop-approvals/1/approve', $html);
        $this->assertStringContainsString('admin/shop-approvals/1/reject', $html);
        $this->assertStringContainsString('data-ajax-refresh="#shopApprovalRegion"', $html);
    }
}
