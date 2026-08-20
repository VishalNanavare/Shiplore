<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Admin\VendorApprovalController — the "Pending Approval → Vendor Approval" queue.
 *
 * A read-only filtered view: status IN submitted/under_review by default, permission
 * vendor.view. The actual approve/reject WRITES stay on VendorController's existing
 * routes (admin/vendors/{id}/approve, /reject) — this file only proves the queue lists
 * the right rows and gates on the right permission; approve()/reject() themselves are
 * already covered by AdminVendorLifecycleTest and are not retested here.
 *
 * Also pins the reason this screen exists: the admin/vendors LIST no longer renders
 * Approve/Reject at all (see AdminVendorApprovalListRemovalTest).
 */
final class AdminVendorApprovalTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');

        Services::injectMock('vendorRepository', new class {
            /** @var array<string,mixed>|null last filters passed to list()/countList() */
            public ?array $lastFilters = null;

            public function list(array $f = [], int $limit = 50, int $offset = 0): array
            {
                $this->lastFilters = $f;

                return [
                    ['id' => 1, 'display_name' => 'Pending Co', 'slug' => 'pending-co', 'gstin' => null, 'status' => 'submitted', 'business_type' => 'Grocery', 'created_at' => '2026-08-01 00:00:00'],
                ];
            }

            public function countList(array $f = []): int
            {
                $this->lastFilters = $f;

                return 1;
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
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Admin', 'principal_type' => 'platform'];
    }

    public function testTheQueueRendersForAPermittedAdmin(): void
    {
        $this->grant(['vendor.view']);

        $r = $this->withSession($this->sess())->get('admin/vendor-approvals');

        $r->assertStatus(200);
        $this->assertStringContainsString('Pending Co', (string) $r->getBody());
    }

    public function testTheQueueIsPermissionGated(): void
    {
        $this->grant(['some.other.permission']);

        $r = $this->withSession($this->sess())->get('admin/vendor-approvals');

        $this->assertNotSame(200, $r->response()->getStatusCode());
    }

    /**
     * THE POINT OF THIS SCREEN: it must query submitted+under_review, not the whole
     * vendor list — an approval queue that showed every vendor would be indistinguishable
     * from the general list this screen exists to replace.
     */
    public function testTheQueueFiltersToPendingStatusesOnly(): void
    {
        $this->grant(['vendor.view']);
        $repo = service('vendorRepository');

        $this->withSession($this->sess())->get('admin/vendor-approvals');

        $this->assertSame(['submitted', 'under_review'], $repo->lastFilters['status'] ?? null);
    }

    /** Manufacturers share the vendors table — this queue must not surface them. */
    public function testTheQueueIsScopedToVendorsNotManufacturers(): void
    {
        $this->grant(['vendor.view']);
        $repo = service('vendorRepository');

        $this->withSession($this->sess())->get('admin/vendor-approvals');

        $this->assertSame('vendor', $repo->lastFilters['party_type'] ?? null);
    }

    public function testSearchIsPassedThrough(): void
    {
        $this->grant(['vendor.view']);
        $repo = service('vendorRepository');

        $this->withSession($this->sess())->get('admin/vendor-approvals?q=pending');

        $this->assertSame('pending', $repo->lastFilters['q'] ?? null);
    }

    /** Each row's approve/reject targets VendorController's existing routes, refreshed in place. */
    public function testApproveAndRejectFormsTargetTheExistingVendorRoutesWithAjaxRefresh(): void
    {
        $this->grant(['vendor.view']);

        $html = html_entity_decode((string) $this->withSession($this->sess())->get('admin/vendor-approvals')->getBody(), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('admin/vendors/1/approve', $html);
        $this->assertStringContainsString('admin/vendors/1/reject', $html);
        $this->assertStringContainsString('data-ajax-refresh="#vendorApprovalRegion"', $html, 'acting from the queue must not bounce to the general vendors list');
    }
}
