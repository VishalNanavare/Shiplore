<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** P2 — admin variant builder HTTP flow: generate parses selections, update wires through. */
final class ProductVariantTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
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

    private function mockProduct(): void
    {
        Services::injectMock('adminProductRepository', new class {
            public function findById(int $id): ?array { return ['id' => $id, 'uuid' => 'u', 'vendor_id' => 7, 'category_id' => 3, 'title' => 'Tee', 'mrp' => '999', 'base_price' => '799']; }
        });
    }

    public function testVariantsPageRenders(): void
    {
        $this->grant(['product.update']);
        $this->mockProduct();
        Services::injectMock('productVariantRepository', new class {
            public function definingAttributes(int $c): array { return [['id' => 1, 'code' => 'size', 'name' => 'Size', 'type' => 'select', 'values' => [['id' => 5, 'value' => 'S', 'sort_order' => 1]]]]; }
            public function listWithValues(int $p): array { return []; }
            public function cleanupEmptyDefault(int $productId): void {}
        });
        // ProductVariantController::index() also calls productShopRepository->forProduct()
        // directly — real, unmocked, would hit "no such table: product_shops".
        Services::injectMock('productShopRepository', new class {
            public function forProduct(int $productId): array { return []; }
        });
        $r = $this->withSession($this->sess())->get('admin/products/9/variants');
        $r->assertStatus(200);
        // The button reads "Generate" with a dynamic combo-count span, not the older
        // static "Generate combinations" copy this test named itself after.
        $this->assertStringContainsString('id="genBtn"', (string) $r->getBody());
    }

    public function testGenerateParsesSelectionsAndForcesVendor(): void
    {
        $this->grant(['product.update']);
        $this->mockProduct();
        $spy = new class {
            public array $got = [];
            public function generate(int $pid, int $vendorId, array $sel, array $base, ?int $a = null): int { $this->got = ['pid' => $pid, 'vendor' => $vendorId, 'sel' => $sel, 'base' => $base]; return 4; }
        };
        Services::injectMock('productVariantRepository', $spy);
        $r = $this->withSession($this->postSess())->post('admin/products/9/variants/generate', [
            csrf_token() => csrf_hash(), 'sku_prefix' => 'TEE', 'mrp' => '999', 'base_price' => '799',
            'sel' => [1 => [5, 6], 2 => [9]],
        ]);
        $r->assertRedirect();
        $this->assertSame(9, $spy->got['pid']);
        $this->assertSame(7, $spy->got['vendor']);                 // forced from product, not input
        $this->assertSame([1 => [5, 6], 2 => [9]], $spy->got['sel']);
        $this->assertSame('TEE', $spy->got['base']['skuPrefix']);
    }

    public function testUpdateVariantWiresThrough(): void
    {
        $this->grant(['product.update']);
        $spy = new class {
            public bool $updated = false;
            public function findVariant(int $id): ?array { return ['id' => $id, 'product_id' => 9, 'vendor_id' => 7]; }
            public function updateVariant(int $id, int $v, array $d, ?int $a = null): bool { $this->updated = true; return true; }
        };
        Services::injectMock('productVariantRepository', $spy);
        $r = $this->withSession($this->postSess())->post('admin/variants/15/update', [csrf_token() => csrf_hash(), 'sku' => 'X', 'mrp' => '10', 'base_price' => '8']);
        $r->assertRedirect();
        $this->assertTrue($spy->updated);
    }

    public function testVariantsDeniedWithoutPermission(): void
    {
        $this->grant(['product.view']);                            // not product.update
        $this->mockProduct();
        $r = $this->withSession($this->sess())->get('admin/products/9/variants');
        $r->assertRedirect();
        $this->assertStringContainsString('dashboard', (string) $r->getRedirectUrl());
    }

    public function testBulkUpdateWiresWithProductVendor(): void
    {
        $this->grant(['product.update']);
        $this->mockProduct();
        $spy = new class {
            public array $got = [];
            public function bulkUpdate(array $ids, int $vendorId, string $field, string $value, ?int $a = null): int { $this->got = compact('ids', 'vendorId', 'field', 'value'); return count($ids); }
        };
        Services::injectMock('productVariantRepository', $spy);
        $r = $this->withSession($this->postSess())->post('admin/products/7/variants/bulk', [csrf_token() => csrf_hash(), 'ids' => [5, 6], 'field' => 'base_price', 'value' => '350']);
        $r->assertRedirect();
        $this->assertSame(7, $spy->got['vendorId']);   // from the product (mockProduct vendor_id=7), not input
        $this->assertSame('base_price', $spy->got['field']);
        $this->assertEquals([5, 6], $spy->got['ids']); // raw POST (strings) -> repo casts to int
    }

    public function testSaveVariantBarcodesWires(): void
    {
        $this->grant(['product.update']);
        Services::injectMock('productVariantRepository', new class {
            public function findVariant(int $id): ?array { return ['id' => $id, 'product_id' => 9, 'vendor_id' => 4]; }
        });
        $spy = new class {
            public array $got = [];
            public function saveFromForm(int $p, ?int $v, array $post, ?int $a = null): array { $this->got = ['product' => $p, 'variant' => $v]; return ['added' => 1, 'errors' => []]; }
        };
        Services::injectMock('productBarcodeRepository', $spy);
        $r = $this->withSession($this->postSess())->post('admin/variants/15/barcodes', [csrf_token() => csrf_hash(), 'barcode_value' => ['4006381333931'], 'barcode_type' => ['EAN13'], 'barcode_pack' => ['unit'], 'barcode_primary' => '0']);
        $r->assertRedirect();
        $this->assertSame(['product' => 9, 'variant' => 15], $spy->got);
    }
}
