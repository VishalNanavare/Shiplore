<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Batch 8 — vendor self-service product create: tenant-scoped (vendor_id forced),
 * category-gated, ownership-checked on edit. Repos mocked.
 */
final class VendorProductTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array { return [['permissions' => [], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate']; }
            public function findStaffVendor(int $u): ?array { return null; }
            public function shopIdsForVendor(int $v): array { return [1]; }
            public function shopIdsForStaff(int $vs): array { return []; }
        });
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 1, 'name' => 'Andheri']]; }
        });
        Services::injectMock('adminProductRepository', new class {
            public bool $created = false;
            public function allowedCategories(int $v): array { return [['id' => 10, 'name' => 'Men\'s Shoes', 'slug' => 'mens']]; }
            public function formMasters(): array { return ['tax' => [['id' => 4, 'name' => 'GST 18%']], 'units' => [['id' => 1, 'name' => 'Piece']], 'brands' => []]; }
            public function skuExists(string $s): bool { return false; }
            public function createWithVariant(array $d, ?int $a = null): ?int { $this->created = true; return 9; }
            public function findById(int $id): ?array { return $id === 9 ? ['id' => 9, 'vendor_id' => 1, 'uuid' => 'u', 'title' => 'X', 'category_id' => 10, 'tax_class_id' => 4, 'base_unit_id' => 1, 'sku' => 'S', 'barcode' => null, 'mrp' => '1', 'base_price' => '1', 'description' => ''] : ($id === 99 ? ['id' => 99, 'vendor_id' => 2] : null); }
        });
        Services::injectMock('mediaRepository', new class {
            public function forProduct(int $id): array { return []; }
            public function hasPrimary(int $id): bool { return true; }
        });
        Services::injectMock('productBarcodeRepository', new class {
            public function forProduct(int $id): array { return []; }
            public function saveFromForm(int $p, ?int $v, array $post, ?int $a = null): array { return ['added' => 0, 'errors' => []]; }
        });
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 5, 'user_name' => 'Vendor', 'principal_type' => 'vendor'];
    }

    private function postSess(): array
    {
        return service('session')->get() + $this->sess();
    }

    public function testNewFormRenders(): void
    {
        $r = $this->withSession($this->sess())->get('vendor/products/new');
        $r->assertStatus(200);
        $this->assertStringContainsString('Create product', (string) $r->getBody());
    }

    public function testStoreRejectsForeignCategory(): void
    {
        $data = service('session')->get() + $this->sess();
        $r = $this->withSession($data)->post('vendor/products/store', [csrf_token() => csrf_hash(), 'title' => 'X', 'category_id' => '999', 'tax_class_id' => '4', 'unit_id' => '1', 'sku' => 'S1', 'mrp' => '100', 'base_price' => '90']);
        $r->assertRedirect();
        $this->assertStringContainsString('not allowed', (string) (session('error') ?? ''));
    }

    public function testStoreSucceeds(): void
    {
        $r = $this->withSession($this->postSess())->post('vendor/products/store', [csrf_token() => csrf_hash(), 'title' => 'Loafers', 'category_id' => '10', 'tax_class_id' => '4', 'unit_id' => '1', 'sku' => 'LF-1', 'mrp' => '2999', 'base_price' => '2499']);
        $r->assertRedirect();
        $this->assertStringContainsString('vendor/products', $r->getRedirectUrl());
    }

    public function testCannotEditForeignVendorProduct(): void
    {
        // product 99 belongs to vendor 2; logged-in vendor is 1
        $r = $this->withSession($this->sess())->get('vendor/products/99/edit');
        $r->assertRedirect();
        $this->assertStringContainsString('vendor/products', $r->getRedirectUrl());
    }
}
