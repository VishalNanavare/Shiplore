<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * S4 — inter-shop stock trade. A branch manager pulls stock from a sibling
 * store into their own; the request is held for the vendor's approval. The
 * owner moves stock directly. The destination must be the user's own store.
 */
final class ShopTransferTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private object $engine;
    private object $transfers;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'vendor.shiplore.in');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        $this->engine = new class {
            public array $submitted = [];
            public function submit(array $in, array $actor, $now = null): array { $this->submitted[] = [$in, $actor]; return ['ok' => true, 'id' => 1, 'status' => 'pending_l1']; }
        };
        Services::injectMock('changeRequestEngine', $this->engine);

        $this->transfers = new class {
            public array $requested = [];
            public function request(int $v, int $f, int $t, int $var, float $q, string $r, ?int $a = null): array { $this->requested[] = [$f, $t, $var, $q]; return ['ok' => true, 'message' => 'ok']; }
        };
        Services::injectMock('transferService', $this->transfers);
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset(true);
        parent::tearDown();
    }

    private function asManager(): void
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array { return [['permissions' => ['transfer.view', 'inventory.transfer'], 'scope_type' => 'shop', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return null; }
            public function findStaffVendor(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate', 'vendor_staff_id' => 7, 'staff_type' => 'branch_manager']; }
            public function shopIdsForStaff(int $vs): array { return [1]; }       // assigned to shop 1 only
            public function shopIdsForVendor(int $v): array { return [1, 2, 3]; }
        });
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 1, 'name' => 'Andheri']]; }
        });
    }

    private function asOwner(): void
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array { return [['permissions' => [], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate']; }
            public function findStaffVendor(int $u): ?array { return null; }
            public function shopIdsForVendor(int $v): array { return [1, 2, 3]; }
            public function shopIdsForStaff(int $vs): array { return []; }
        });
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']]; }
        });
    }

    private function sess(): array
    {
        return service('session')->get() + ['isLoggedIn' => true, 'user_id' => 110, 'user_name' => 'Sunil', 'principal_type' => 'vendor'];
    }

    public function testStaffPullFromSiblingBecomesVendorRequest(): void
    {
        $this->asManager();

        // pull variant 9 from shop 2 (sibling) into shop 1 (mine)
        $r = $this->withSession($this->sess())->post('vendor/transfers', [csrf_token() => csrf_hash(), 'from_shop_id' => 2, 'to_shop_id' => 1, 'variant_id' => 9, 'qty' => '5', 'reason' => 'stock-out']);

        $r->assertRedirect();
        $this->assertCount(1, $this->engine->submitted);
        $this->assertSame(['transfer', 'request'], [$this->engine->submitted[0][0]['entity_type'], $this->engine->submitted[0][0]['action']]);
        $this->assertSame(2, $this->engine->submitted[0][0]['payload_new']['from_shop_id']);
        $this->assertSame([], $this->transfers->requested, 'no stock moves until the vendor approves');
    }

    public function testStaffDestinationMustBeOwnStore(): void
    {
        $this->asManager();

        // destination shop 2 is NOT the manager's store → rejected
        $r = $this->withSession($this->sess())->post('vendor/transfers', [csrf_token() => csrf_hash(), 'from_shop_id' => 3, 'to_shop_id' => 2, 'variant_id' => 9, 'qty' => '5']);

        $r->assertRedirect();
        $this->assertCount(0, $this->engine->submitted);
        $this->assertSame([], $this->transfers->requested);
    }

    public function testOwnerTransfersDirectly(): void
    {
        $this->asOwner();

        $r = $this->withSession($this->sess())->post('vendor/transfers', [csrf_token() => csrf_hash(), 'from_shop_id' => 2, 'to_shop_id' => 1, 'variant_id' => 9, 'qty' => '5']);

        $r->assertRedirect();
        $this->assertSame([[2, 1, 9, 5.0]], $this->transfers->requested);
        $this->assertCount(0, $this->engine->submitted);
    }
}
