<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * VendorController::activate()/deactivate() — Phase 2 of the vendor status/lifecycle
 * build (see the design discussed with the operator; phases: 1 schema fix, 2 this
 * screen, 3 shared status-gate helper, 4-6 enforcement, 7 operator flag flip).
 *
 * activate() writes status='active'; deactivate() writes status='suspended' — not
 * 'terminated', which already exists, already blocks admin impersonation
 * (PortalController::enterVendor/enterShop), and is deliberately left alone here as a
 * separate, more final action outside this simple reversible toggle.
 *
 * This phase writes ONLY vendors.status. Nothing yet reads it for storefront/API
 * visibility or login gating (that's phases 4-6) — so these actions are safe to ship
 * standalone with zero behaviour change anywhere else.
 */
final class AdminVendorLifecycleTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private object $repo;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');

        $this->repo = new class {
            public array $vendor = ['id' => 501, 'party_type' => 'vendor', 'status' => 'active', 'owner_user_id' => 9, 'display_name' => 'Acme'];
            /** @var list<array{id:int,status:string,actorId:?int}> */
            public array $updates = [];

            public function findById(int $id): ?array { return $id === $this->vendor['id'] ? $this->vendor : null; }

            public function updateStatus(int $id, string $status, ?int $actorId = null): bool
            {
                $this->updates[] = ['id' => $id, 'status' => $status, 'actorId' => $actorId];
                $this->vendor['status'] = $status;

                return true;
            }
        };
        Services::injectMock('vendorRepository', $this->repo);
        Services::injectMock('notificationService', new class {
            public function notify(int $userId, string $type, array $data = []): void {}
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

    private function submit(string $path): \CodeIgniter\Test\TestResponse
    {
        return $this->withSession(service('session')->get() + $this->sess())
            ->post($path, [csrf_token() => csrf_hash()]);
    }

    // ------------------------------------------------------------------ activate

    public function testActivateWritesTheActiveStatus(): void
    {
        $this->grant(['vendor.activate']);
        $this->repo->vendor['status'] = 'suspended';

        $this->submit('admin/vendors/501/activate');

        $this->assertSame('active', $this->repo->updates[0]['status']);
        $this->assertSame(501, $this->repo->updates[0]['id']);
    }

    public function testActivateIsPermissionGated(): void
    {
        $this->grant(['some.other.permission']);
        $this->repo->vendor['status'] = 'suspended';

        $this->submit('admin/vendors/501/activate');

        $this->assertSame([], $this->repo->updates, 'no write without vendor.activate');
    }

    // ------------------------------------------------------------------ deactivate

    public function testDeactivateWritesSuspendedNotTerminated(): void
    {
        $this->grant(['vendor.deactivate']);

        $this->submit('admin/vendors/501/deactivate');

        $this->assertSame('suspended', $this->repo->updates[0]['status'], "deactivate must write 'suspended', a reversible state — never 'terminated'");
    }

    public function testDeactivateIsPermissionGated(): void
    {
        $this->grant(['some.other.permission']);

        $this->submit('admin/vendors/501/deactivate');

        $this->assertSame([], $this->repo->updates);
    }

    // ------------------------------------------------------------------ terminated is out of scope

    /**
     * 'terminated' is deliberately outside this simple activate/deactivate pair — it
     * already means something else (blocks admin impersonation) and reinstating from it
     * is meant to be a separate, more deliberate action, not a side effect of this
     * reversible toggle. Both directions are refused server-side, not just hidden in the
     * UI — a hidden button is not a guard.
     */
    /**
     * Permission is checked BEFORE the terminated lookup, not after.
     *
     * transition() already denies an unpermitted caller on its own — so without the
     * outer guard() call, activate()/deactivate() would still end up refusing the
     * write, just one step later, after refuseIfTerminated() has already run and
     * revealed the vendor's terminated status in the flash message to someone who
     * isn't supposed to see it. Same end result (nothing written), different ordering
     * — the write-count assertions in the permission tests above cannot tell the two
     * apart, which is exactly what a mutation run found: deleting the outer guard call
     * left every other test green.
     */
    public function testAnUnauthorizedRequestIsDeniedBeforeTheTerminatedStatusLeaks(): void
    {
        $this->grant(['some.other.permission']);
        $this->repo->vendor['status'] = 'terminated';

        $this->submit('admin/vendors/501/activate');

        $this->assertStringNotContainsStringIgnoringCase('terminated', (string) session()->getFlashdata('error'), 'permission must be checked first');
    }

    /** Same ordering requirement, the other direction — mutation-found, see above. */
    public function testAnUnauthorizedDeactivateRequestIsDeniedBeforeTheTerminatedStatusLeaks(): void
    {
        $this->grant(['some.other.permission']);
        $this->repo->vendor['status'] = 'terminated';

        $this->submit('admin/vendors/501/deactivate');

        $this->assertStringNotContainsStringIgnoringCase('terminated', (string) session()->getFlashdata('error'), 'permission must be checked first');
    }

    public function testActivatingATerminatedVendorIsRefused(): void
    {
        $this->grant(['vendor.activate']);
        $this->repo->vendor['status'] = 'terminated';

        $r = $this->submit('admin/vendors/501/activate');

        $this->assertSame([], $this->repo->updates, 'terminated must not be silently reactivated via this toggle');
        $r->assertRedirect();
    }

    public function testDeactivatingATerminatedVendorIsRefused(): void
    {
        $this->grant(['vendor.deactivate']);
        $this->repo->vendor['status'] = 'terminated';

        $this->submit('admin/vendors/501/deactivate');

        $this->assertSame([], $this->repo->updates);
    }

    // ------------------------------------------------------------------ manufacturer guard

    /**
     * Vendors and manufacturers share this table under party_type. Without this guard,
     * vendor.activate would also let an admin toggle a manufacturer's status through the
     * wrong screen — the same class of bug a comment in this file already calls out for
     * approve/reject ("without it, vendor.approve would also approve manufacturers").
     */
    public function testActivatingAManufacturerRowIsRefused(): void
    {
        $this->grant(['vendor.activate']);
        $this->repo->vendor['party_type'] = 'manufacturer';
        $this->repo->vendor['status']     = 'suspended';

        $r = $this->submit('admin/vendors/501/activate');

        $this->assertSame([], $this->repo->updates);
        $r->assertRedirectTo(site_url('admin/manufacturers/501'));
    }

    public function testDeactivatingAManufacturerRowIsRefused(): void
    {
        $this->grant(['vendor.deactivate']);
        $this->repo->vendor['party_type'] = 'manufacturer';

        $this->submit('admin/vendors/501/deactivate');

        $this->assertSame([], $this->repo->updates);
    }

    public function testActivatingAMissingVendorIsHandled(): void
    {
        $this->grant(['vendor.activate']);

        $r = $this->submit('admin/vendors/999999/activate');

        $this->assertSame([], $this->repo->updates);
        $r->assertRedirect();
    }
}
