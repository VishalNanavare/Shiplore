<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** P3 — admin inventory HTTP flow: render, receive/adjust wiring, RBAC. */
final class ProductInventoryTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.in');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function grant(array $perms): void
    {
        Services::injectMock('capabilityRepository', new class ($perms) {
            public function __construct(private array $perms) {}
            public function loadAssignments(int $u): array
            {
                return [['permissions' => $this->perms, 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
    }

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Admin', 'principal_type' => 'platform'];
    }

    private function postSess(): array
    {
        return service('session')->get() + $this->sess();
    }

    private function mocks(object $invSpy): void
    {
        Services::injectMock('adminProductRepository', new class {
            public function findById(int $id): ?array { return ['id' => $id, 'vendor_id' => 4, 'title' => 'Tee']; }
        });
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 2, 'name' => 'Main Shop']]; }
        });
        Services::injectMock('productVariantRepository', new class {
            public function listWithValues(int $p): array { return [['id' => 9, 'sku' => 'TEE-M', 'attributes' => 'Size: M']]; }
            public function findVariant(int $id): ?array { return ['id' => $id, 'product_id' => 7, 'vendor_id' => 4]; }
        });
        Services::injectMock('inventoryService', $invSpy);
    }

    public function testInventoryPageRenders(): void
    {
        $this->grant(['inventory.manage']);
        $this->mocks(new class {
            public function snapshotRows(array $v, array $s, string $m = 'fifo'): array { return [['v' => $v[0], 'levels' => [], 'val' => ['method' => 'fifo', 'on_hand_value' => 0, 'on_hand_qty' => 0, 'wavg_cost' => 0]]]; }
        });
        $r = $this->withSession($this->sess())->get('admin/products/7/inventory');
        $r->assertStatus(200);
        $this->assertStringContainsString('Receive stock', (string) $r->getBody());
    }

    public function testReceiveWiresToService(): void
    {
        $this->grant(['inventory.manage']);
        $spy = new class {
            public array $got = [];
            public function snapshotRows(array $v, array $s, string $m = 'fifo'): array { return []; }
            public function receive(int $variant, int $shop, float $qty, float $cost, array $o = [], ?int $a = null): bool { $this->got = compact('variant', 'shop', 'qty', 'cost'); return true; }
        };
        $this->mocks($spy);
        $r = $this->withSession($this->postSess())->post('admin/products/7/inventory/receive', [
            csrf_token() => csrf_hash(), 'variant_id' => 9, 'shop_id' => 2, 'qty' => '10', 'cost' => '100',
        ]);
        $r->assertRedirect();
        $this->assertSame(['variant' => 9, 'shop' => 2, 'qty' => 10.0, 'cost' => 100.0], $spy->got);
    }

    public function testAdjustWiresToService(): void
    {
        $this->grant(['inventory.manage']);
        $spy = new class {
            public bool $adjusted = false;
            public function snapshotRows(array $v, array $s, string $m = 'fifo'): array { return []; }
            public function adjust(int $variant, int $shop, float $d, string $reason, string $notes = '', ?int $a = null): bool { $this->adjusted = true; return true; }
        };
        $this->mocks($spy);
        $r = $this->withSession($this->postSess())->post('admin/products/7/inventory/adjust', [
            csrf_token() => csrf_hash(), 'variant_id' => 9, 'shop_id' => 2, 'qty_delta' => '-3', 'reason' => 'damage',
        ]);
        $r->assertRedirect();
        $this->assertTrue($spy->adjusted);
    }

    public function testDeniedWithoutPermission(): void
    {
        $this->grant(['product.view']);
        $this->mocks(new class {
            public function snapshotRows(array $v, array $s, string $m = 'fifo'): array { return []; }
        });
        $r = $this->withSession($this->sess())->get('admin/products/7/inventory');
        $r->assertRedirect();
        $this->assertStringContainsString('dashboard', (string) $r->getRedirectUrl());
    }
}
