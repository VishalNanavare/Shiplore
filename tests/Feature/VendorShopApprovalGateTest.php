<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Shop approval, phase 3, controller-level: Vendor\ShopController.
 *
 * create(): the vendor is told plainly when their new shop is NOT live yet ("Shop
 * added." reads as done, which is wrong for a 2nd+ shop awaiting review).
 *
 * open()/close(): a shop with approval_status pending/rejected cannot be toggled by
 * the vendor at all — opening would bypass the admin gate this feature exists to
 * enforce; closing a shop that was never live is meaningless. The actual
 * pending-vs-not_required DECISION is VendorShopRepository::create()'s job
 * (VendorShopRepositoryApprovalTest); this file is about what the CONTROLLER does
 * with that decision.
 */
final class VendorShopApprovalGateTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'vendor.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');

        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array
            {
                return [['permissions' => [], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []]];
            }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array
            {
                return $u === 101 ? ['id' => 1, 'display_name' => 'Sole Mate', 'status' => 'active', 'is_owner' => true] : null;
            }
            public function findStaffVendor(int $u): ?array { return null; }
            public function shopIdsForVendor(int $v): array { return [1]; }
            public function shopIdsForStaff(int $vs): array { return []; }
        });

        $this->shop = ['id' => 1, 'name' => 'Andheri', 'approval_status' => 'not_required', 'status' => 'active'];
        Services::injectMock('vendorShopRepository', new class ($this) {
            public function __construct(private VendorShopApprovalGateTest $t) {}
            /** @var list<array{id:int,vendorId:int,status:string,actorId:?int}> */
            public array $statusUpdates = [];
            public ?string $lastCreateResultStatus = null;

            public function findById(int $id, int $v): ?array { return $id === 1 ? $this->t->shop : null; }

            public function create(int $v, array $d, ?int $actorId = null): ?int
            {
                $this->t->shop['approval_status'] = $this->lastCreateResultStatus ?? $this->t->shop['approval_status'];

                return 1;
            }

            public function updateStatus(int $id, int $v, string $status, ?int $actorId = null): bool
            {
                $this->statusUpdates[] = ['id' => $id, 'vendorId' => $v, 'status' => $status, 'actorId' => $actorId];

                return true;
            }
        });
    }

    /** @var array<string,mixed> mutated mid-test to simulate different shop states */
    public array $shop;

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 101, 'user_name' => 'Owner', 'principal_type' => 'vendor'];
    }

    private function createPost(): array
    {
        return ['name' => 'New Shop', 'address' => 'x', 'area' => 'x', 'city' => 'x', 'pincode' => '400001', csrf_token() => csrf_hash()];
    }

    // ------------------------------------------------------------------ create() messaging

    public function testCreatingTheFirstShopReportsItAsAddedNotPending(): void
    {
        $repo = service('vendorShopRepository');
        $repo->lastCreateResultStatus = 'not_required';

        $this->withSession($this->sess())->post('vendor/shops', $this->createPost());

        $this->assertSame('Shop added.', (string) session()->getFlashdata('success'));
    }

    public function testCreatingA2ndShopReportsItAsPendingApproval(): void
    {
        $repo = service('vendorShopRepository');
        $repo->lastCreateResultStatus = 'pending';

        $this->withSession($this->sess())->post('vendor/shops', $this->createPost());

        $msg = (string) session()->getFlashdata('success');
        $this->assertStringContainsString('admin approval', $msg);
        $this->assertStringNotContainsString('Shop added.', $msg, 'the pending message must not be the same as the live-shop one');
    }

    // ------------------------------------------------------------------ open()/close() refusal

    public function testOpeningAPendingShopIsRefused(): void
    {
        $this->shop['approval_status'] = 'pending';
        $repo = service('vendorShopRepository');

        $this->withSession($this->sess())->post('vendor/shops/1/open', [csrf_token() => csrf_hash()]);

        $this->assertSame([], $repo->statusUpdates, 'a pending shop must not be openable by the vendor');
        $this->assertStringContainsString('awaiting admin approval', (string) session()->getFlashdata('error'));
    }

    public function testClosingAPendingShopIsAlsoRefused(): void
    {
        $this->shop['approval_status'] = 'pending';
        $repo = service('vendorShopRepository');

        $this->withSession($this->sess())->post('vendor/shops/1/close', [csrf_token() => csrf_hash()]);

        $this->assertSame([], $repo->statusUpdates);
    }

    public function testARejectedShopIsAlsoRefused(): void
    {
        $this->shop['approval_status'] = 'rejected';
        $repo = service('vendorShopRepository');

        $this->withSession($this->sess())->post('vendor/shops/1/open', [csrf_token() => csrf_hash()]);

        $this->assertSame([], $repo->statusUpdates);
    }

    /** The baseline case — approved (or grandfathered not_required) shops still toggle normally. */
    public function testAnApprovedShopCanStillBeOpened(): void
    {
        $this->shop['approval_status'] = 'approved';
        $repo = service('vendorShopRepository');

        $this->withSession($this->sess())->post('vendor/shops/1/open', [csrf_token() => csrf_hash()]);

        $this->assertCount(1, $repo->statusUpdates);
        $this->assertSame('active', $repo->statusUpdates[0]['status']);
    }

    public function testANotRequiredShopCanStillBeOpened(): void
    {
        // Default fixture state is already 'not_required' — the grandfathered case.
        $repo = service('vendorShopRepository');

        $this->withSession($this->sess())->post('vendor/shops/1/open', [csrf_token() => csrf_hash()]);

        $this->assertCount(1, $repo->statusUpdates);
    }
}
