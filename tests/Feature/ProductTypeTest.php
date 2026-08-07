<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** P6 — admin specialized-type config HTTP flow: render per type, bundle/gift wiring, RBAC. */
final class ProductTypeTest extends CIUnitTestCase
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

    private function product(string $type): void
    {
        Services::injectMock('adminProductRepository', new class ($type) {
            public function __construct(private string $type) {}
            public function findById(int $id): ?array { return ['id' => $id, 'vendor_id' => 4, 'title' => 'X', 'product_type' => $this->type, 'base_price' => '399']; }
        });
    }

    public function testComboPageShowsComponents(): void
    {
        $this->grant(['product.update']);
        $this->product('combo');
        Services::injectMock('productTypeRepository', new class {
            public function bundleItems(int $p): array { return []; }
            public function bundleEffective(int $p, string $m, float $f): array { return ['price' => 399.0, 'components_total' => 0.0, 'savings' => 0.0]; }
            public function vendorVariants(int $v, int $x = 0): array { return [['id' => 5, 'sku' => 'A', 'base_price' => '200', 'title' => 'Comp A']]; }
        });
        $r = $this->withSession($this->sess())->get('admin/products/7/type');
        $r->assertStatus(200);
        $this->assertStringContainsString('Components', (string) $r->getBody());
    }

    public function testGiftcardPageShowsSettings(): void
    {
        $this->grant(['product.update']);
        $this->product('giftcard');
        Services::injectMock('productTypeRepository', new class {
            public function giftConfig(int $p): array { return ['denominations' => [100, 500]]; }
        });
        $r = $this->withSession($this->sess())->get('admin/products/7/type');
        $r->assertStatus(200);
        $this->assertStringContainsString('Gift-card settings', (string) $r->getBody());
    }

    public function testBundleAddWiresToRepoForSameVendorComponent(): void
    {
        $this->grant(['product.update']);
        $this->product('combo');
        $spy = new class {
            public bool $added = false;
            public function addBundleItem(int $b, int $v, float $q, ?float $c, bool $o, ?int $a = null): bool { $this->added = true; return true; }
        };
        Services::injectMock('productTypeRepository', $spy);
        Services::injectMock('productVariantRepository', new class {
            public function findVariant(int $id): ?array { return ['id' => $id, 'product_id' => 9, 'vendor_id' => 4]; }
        });
        $r = $this->withSession($this->postSess())->post('admin/products/7/type/bundle-add', [csrf_token() => csrf_hash(), 'item_variant_id' => 5, 'qty' => '1']);
        $r->assertRedirect();
        $this->assertTrue($spy->added);
    }

    public function testBundleAddRejectsForeignVendorComponent(): void
    {
        $this->grant(['product.update']);
        $this->product('combo');
        $spy = new class {
            public bool $added = false;
            public function addBundleItem(int $b, int $v, float $q, ?float $c, bool $o, ?int $a = null): bool { $this->added = true; return true; }
        };
        Services::injectMock('productTypeRepository', $spy);
        Services::injectMock('productVariantRepository', new class {
            public function findVariant(int $id): ?array { return ['id' => $id, 'product_id' => 9, 'vendor_id' => 99]; } // different vendor
        });
        $r = $this->withSession($this->postSess())->post('admin/products/7/type/bundle-add', [csrf_token() => csrf_hash(), 'item_variant_id' => 5, 'qty' => '1']);
        $r->assertRedirect();
        $this->assertFalse($spy->added);
    }

    public function testDeniedWithoutPermission(): void
    {
        $this->grant(['product.view']);
        $this->product('combo');
        $r = $this->withSession($this->sess())->get('admin/products/7/type');
        $r->assertRedirect();
        $this->assertStringContainsString('dashboard', (string) $r->getRedirectUrl());
    }
}
