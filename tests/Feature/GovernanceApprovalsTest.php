<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * X3 — governance surfaces: deny→request conversion on a real page, the
 * vendor approval inbox (L1), and the admin unified queue (L2).
 */
final class GovernanceApprovalsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private object $engine;
    private object $requests;

    protected function setUp(): void
    {
        parent::setUp();

        // capture engine calls instead of hitting DB
        $this->engine = new class () {
            public array $submitted = [];
            public array $decided   = [];

            public function submit(array $in, array $actor, $now = null): array
            {
                $this->submitted[] = [$in, $actor];

                return ['ok' => true, 'id' => 1, 'status' => 'pending_l1'];
            }

            public function decide(int $id, array $actor, string $decision, ?string $reason = null, $now = null): array
            {
                $this->decided[] = [$id, $actor, $decision, $reason];

                return ['ok' => true, 'status' => 'applied'];
            }
        };
        Services::injectMock('changeRequestEngine', $this->engine);

        $this->requests = new class () {
            public function pendingForVendor(int $vendorId, ?string $status = null): array
            {
                return [[
                    'id' => 5, 'request_no' => 'CR-260612-AB12C', 'entity_type' => 'shop', 'action' => 'hours',
                    'entity_id' => 3, 'field_group' => 'hours', 'payload_new' => ['rows' => []],
                    'requested_by' => 99, 'requester_name' => 'Priya Cashier', 'requester_role' => 'staff',
                    'vendor_id' => 1, 'status' => 'pending_l1', 'current_level' => 1,
                    'required_levels' => ['vendor'], 'sla_due_at' => '2026-06-14 10:00:00',
                    'apply_error' => null, 'created_at' => '2026-06-12 10:00:00',
                ]];
            }

            public function pendingForAdmin(?string $status = null): array
            {
                return [[
                    'id' => 9, 'request_no' => 'CR-260612-ZZ99X', 'entity_type' => 'product', 'action' => 'price_change',
                    'entity_id' => 42, 'field_group' => 'default', 'payload_new' => ['price' => 120],
                    'requested_by' => 99, 'requester_name' => 'Priya Cashier', 'requester_role' => 'staff',
                    'vendor_id' => 1, 'vendor_name' => 'Fresh Foods', 'status' => 'pending_l2', 'current_level' => 2,
                    'required_levels' => ['vendor', 'admin'], 'sla_due_at' => '2026-06-14 10:00:00',
                    'apply_error' => null, 'created_at' => '2026-06-12 10:00:00',
                ]];
            }

            public function listForRequester(int $userId): array
            {
                return [];
            }
        };
        Services::injectMock('changeRequestRepository', $this->requests);
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset(true);
        parent::tearDown();
    }

    /**
     * This file hits BOTH admin/... and vendor/... routes, so the host can't be fixed
     * once for the whole class — each test that makes a real request calls this with
     * its OWN panel. See PanelSubdomainIsolationTest / AdminAccessTest for why plain
     * $_SERVER assignment doesn't work and why tearDown() must unsetServer().
     */
    private function withHost(string $host): void
    {
        service('superglobals')->setServer('HTTP_HOST', $host);
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
    }

    // ---- helpers ---------------------------------------------------------

    private function asVendorOwner(): void
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array
            {
                return [['permissions' => ['request.approve.vendor'], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []]];
            }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1, 'display_name' => 'Fresh Foods']; }
            public function findStaffVendor(int $u): ?array { return null; }
            public function shopIdsForVendor(int $v): array { return [3]; }
            public function shopIdsForStaff(int $vs): array { return []; }
        });
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 3, 'name' => 'Andheri']]; }
        });
    }

    private function asShopStaff(): void
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array
            {
                return [['permissions' => ['request.create'], 'scope_type' => 'shop', 'scope_id' => 3, 'attributes' => []]];
            }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return null; }
            public function findStaffVendor(int $u): ?array { return ['id' => 1, 'display_name' => 'Fresh Foods', 'vendor_staff_id' => 7, 'staff_type' => 'cashier']; }
            public function shopIdsForVendor(int $v): array { return [3]; }
            public function shopIdsForStaff(int $vs): array { return [3]; }
        });
        Services::injectMock('vendorShopRepository', new class {
            public function findById(int $id, int $v): ?array { return $id === 3 ? ['id' => 3, 'name' => 'Andheri'] : null; }
            public function list(int $v): array { return [['id' => 3, 'name' => 'Andheri']]; }
        });
    }

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 50, 'user_name' => 'Owner', 'principal_type' => 'vendor'];
    }

    private function staffSess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 99, 'user_name' => 'Priya', 'principal_type' => 'vendor'];
    }

    // ---- deny→request conversion ----------------------------------------

    public function testStaffHoursChangeBecomesChangeRequest(): void
    {
        $this->withHost('vendor.shiplore.test');
        $this->asShopStaff();

        $r = $this->withSession(service('session')->get() + $this->staffSess())
            ->post('vendor/shops/3/hours', [csrf_token() => csrf_hash(), 'open_0' => '09:00', 'close_0' => '21:00']);

        $r->assertRedirect();
        $this->assertCount(1, $this->engine->submitted, 'staff write must convert into a change request');
        [$in, $actor] = $this->engine->submitted[0];
        $this->assertSame(['shop', 'hours', 3], [$in['entity_type'], $in['action'], $in['entity_id']]);
        $this->assertSame(['staff', 99, 1], [$actor['role'], $actor['user_id'], $actor['vendor_id']]);
    }

    // ---- vendor inbox (L1) ------------------------------------------------

    public function testVendorInboxListsPendingRequests(): void
    {
        $this->withHost('vendor.shiplore.test');
        $this->asVendorOwner();

        $r = $this->withSession($this->sess())->get('vendor/approvals');

        $r->assertStatus(200);
        $r->assertSee('CR-260612-AB12C');
        $r->assertSee('shop.hours');
        $r->assertSee('Priya Cashier');
    }

    public function testVendorDecideRoutesToEngineAsVendorRole(): void
    {
        $this->withHost('vendor.shiplore.test');
        $this->asVendorOwner();

        $r = $this->withSession(service('session')->get() + $this->sess())
            ->post('vendor/approvals/5/decide', [csrf_token() => csrf_hash(), 'decision' => 'approved']);

        $r->assertRedirect();
        $this->assertCount(1, $this->engine->decided);
        [$id, $actor, $decision] = $this->engine->decided[0];
        $this->assertSame([5, 'vendor', 1, 'approved'], [$id, $actor['role'], $actor['vendor_id'], $decision]);
    }

    public function testStaffWithoutApprovePermCannotOpenInbox(): void
    {
        $this->withHost('vendor.shiplore.test');
        $this->asShopStaff();

        $this->withSession($this->staffSess())->get('vendor/approvals')->assertRedirect();
    }

    // ---- admin queue (L2) --------------------------------------------------

    private function adminSess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Admin', 'principal_type' => 'platform'];
    }

    private function grantAdmin(array $perms): void
    {
        Services::injectMock('capabilityRepository', new class ($perms) {
            public function __construct(private array $perms) {}

            public function loadAssignments(int $u): array
            {
                return [['permissions' => $this->perms, 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
    }

    public function testAdminQueueListsAndDecides(): void
    {
        $this->withHost('admin.shiplore.test');
        $this->grantAdmin(['request.approve.admin']);

        $page = $this->withSession($this->adminSess())->get('admin/approvals');
        $page->assertStatus(200);
        $page->assertSee('CR-260612-ZZ99X');
        $page->assertSee('Fresh Foods');

        $r = $this->withSession(service('session')->get() + $this->adminSess())
            ->post('admin/approvals/9/decide', [csrf_token() => csrf_hash(), 'decision' => 'approved']);
        $r->assertRedirect();
        $this->assertSame('admin', $this->engine->decided[0][1]['role']);
    }

    public function testAdminQueueBlockedWithoutPermission(): void
    {
        $this->withHost('admin.shiplore.test');
        $this->grantAdmin([]);

        $this->withSession($this->adminSess())->get('admin/approvals')->assertRedirect();
        $this->assertCount(0, $this->engine->decided);
    }
}
