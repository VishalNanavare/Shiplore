<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** T1 — inter-store transfer lifecycle actions wire to TransferService (vendor-scoped). */
final class VendorTransferTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private object $spy;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'vendor.shiplore.in');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array { return [['permissions' => [], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate']; }
            public function findStaffVendor(int $u): ?array { return null; }
            public function shopIdsForVendor(int $v): array { return [1, 2]; }   // owner: all vendor shops
            public function shopIdsForStaff(int $vs): array { return []; }
        });
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 1, 'name' => 'Andheri'], ['id' => 2, 'name' => 'Bandra Store']]; }
            public function findById(int $id, int $v): ?array { return in_array($id, [1, 2], true) ? ['id' => $id, 'name' => 'Shop ' . $id] : null; }
        });
        $this->spy = new class {
            public array $calls = [];
            public function approve(int $id, int $v, ?int $a = null): array { $this->calls[] = ['approve', $id, $v]; return ['ok' => true, 'message' => 'ok']; }
            public function dispatch(int $id, int $v, ?int $a = null): array { $this->calls[] = ['dispatch', $id, $v]; return ['ok' => true, 'message' => 'ok']; }
            public function receive(int $id, int $v, float $q, ?int $a = null): array { $this->calls[] = ['receive', $id, $v, $q]; return ['ok' => true, 'message' => 'ok']; }
            public function cancel(int $id, int $v, ?int $a = null): array { $this->calls[] = ['cancel', $id, $v]; return ['ok' => true, 'message' => 'ok']; }
        };
        Services::injectMock('transferService', $this->spy);
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function sess(): array
    {
        return service('session')->get() + ['isLoggedIn' => true, 'user_id' => 5, 'user_name' => 'V', 'principal_type' => 'vendor'];
    }

    public function testApproveWiresWithVendorScope(): void
    {
        $r = $this->withSession($this->sess())->post('vendor/transfers/9/approve', [csrf_token() => csrf_hash()]);
        $r->assertRedirect();
        $this->assertSame(['approve', 9, 1], $this->spy->calls[0]);   // vendor id forced from session (1)
    }

    public function testDispatchWires(): void
    {
        $r = $this->withSession($this->sess())->post('vendor/transfers/9/dispatch', [csrf_token() => csrf_hash()]);
        $r->assertRedirect();
        $this->assertSame(['dispatch', 9, 1], $this->spy->calls[0]);
    }

    public function testReceivePassesQuantity(): void
    {
        $r = $this->withSession($this->sess())->post('vendor/transfers/9/receive', [csrf_token() => csrf_hash(), 'qty_received' => '4']);
        $r->assertRedirect();
        $this->assertSame(['receive', 9, 1, 4.0], $this->spy->calls[0]);
    }

    public function testFindStockPageShowsOtherStores(): void
    {
        Services::injectMock('vendorTransferRepository', new class {
            public function searchStock(int $v, string $q, int $l = 100): array { return [['shop_id' => 2, 'shop' => 'Bandra Store', 'available' => '7.000', 'variant_id' => 9, 'sku' => 'SK1', 'title' => 'Widget']]; }
            public function shops(int $v): array { return [['id' => 1, 'name' => 'Andheri'], ['id' => 2, 'name' => 'Bandra Store']]; }
        });
        $r = $this->withSession($this->sess())->get('vendor/transfers/find?q=widget');
        $r->assertStatus(200);
        $this->assertStringContainsString('Bandra Store', (string) $r->getBody());
        $this->assertStringContainsString('Request', (string) $r->getBody());
    }

    public function testTransferActionDeniedWhenNotAVendor(): void
    {
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return null; }   // not a vendor owner
            public function findStaffVendor(int $u): ?array { return null; }
        });
        $r = $this->withSession($this->sess())->post('vendor/transfers/9/approve', [csrf_token() => csrf_hash()]);
        $r->assertRedirect();
        $this->assertSame([], $this->spy->calls);   // service never called
    }
}
