<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

require_once __DIR__ . '/../_support/MinimalSchema.php';

/**
 * Batch 1 — admin CRUD HTTP flows: vendor/shop/product create, category-gating
 * rejection, bulk import upload, media serve guard, RBAC denial. Repos/services
 * mocked.
 */
final class AdminCrudTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        $this->ensureUsersTable();
        $this->seedActiveUser(1, 'platform', 'Admin');
        // Admin\ProductController::form()/store() reach shopRepository->vendorsForSelect()
        // and productShopRepository->shopsForVendor() directly (real, unmocked) whenever
        // a vendor_id is present — real tables, not mocks, since this shop id also has to
        // survive resolveShopIds()'s allow-list check.
        $this->ensureVendorsTable();
        $this->ensureShopsTable();
        $this->schemaConn()->table('shops')->where('id', 1)->delete();
        $this->schemaConn()->query(
            'INSERT INTO db_shops (id, vendor_id, name, status) VALUES (1, 1, ?, ?)',
            ['Andheri Outlet', 'active'],
        );
    }

    protected function tearDown(): void
    {
        $this->dropUsersTable();
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

    private function csrf(): array
    {
        return [csrf_token() => csrf_hash()];
    }

    private function postSess(): array
    {
        return service('session')->get() + $this->sess();
    }

    // ---- Vendor ----
    public function testVendorNewRenders(): void
    {
        $this->grant(['vendor.create']);
        Services::injectMock('businessTypeRepository', new class {
            public function list(?string $s = null): array { return [['id' => 1, 'name' => 'Footwear']]; }
        });
        $r = $this->withSession($this->sess())->get('admin/vendors/new');
        $r->assertStatus(200);
        $this->assertStringContainsString('Owner login', (string) $r->getBody());
    }

    public function testVendorStoreCreates(): void
    {
        $this->grant(['vendor.create']);
        $created = false;
        Services::injectMock('registrationRepository', new class ($created) {
            public bool $c;
            public function __construct(bool &$c) { $this->c = &$c; }
            public function emailExists(string $e): bool { return false; }
            public function mobileExists(string $m): bool { return false; }
            public function gstinExists(string $g): bool { return false; }
            public function createVendorWithShop(array $d): ?int { $this->c = true; return 7; }
        });
        $data = $this->csrf() + ['business_type_id' => '1', 'legal_name' => 'New Pvt Ltd', 'display_name' => 'New', 'email' => 'n@v.in', 'mobile' => '9812345678', 'password' => 'secret123', 'address' => '1 St', 'city' => 'Mumbai', 'state_code' => '27', 'pincode' => '400001', 'delivery_radius' => '5'];
        $r = $this->withSession($this->postSess())->post('admin/vendors/store', $data);
        $r->assertRedirect();
        $this->assertStringContainsString('admin/vendors', $r->getRedirectUrl());
    }

    public function testVendorStoreDeniedWithoutPermission(): void
    {
        $this->grant(['vendor.view']);
        $this->withSession($this->postSess())->post('admin/vendors/store', $this->csrf())->assertRedirect();
    }

    // ---- Shop ----
    public function testShopStoreCreates(): void
    {
        $this->grant(['shop.create']);
        Services::injectMock('shopRepository', new class {
            public function vendorsForSelect(): array { return [['id' => 1, 'display_name' => 'Sole Mate']]; }
            public function create(array $d, ?int $a = null): ?int { return 3; }
        });
        $data = $this->csrf() + ['vendor_id' => '1', 'name' => 'Branch', 'code' => 'BR-1', 'address' => '1 St', 'city' => 'Mumbai', 'state_code' => '27', 'pincode' => '400001'];
        $r = $this->withSession($this->postSess())->post('admin/shops/store', $data);
        $r->assertRedirect();
    }

    // ---- Product (category gating) ----
    private function productRepoMock(array $allowed): void
    {
        Services::injectMock('adminProductRepository', new class ($allowed) {
            public array $allowed;
            public function __construct(array $a) { $this->allowed = $a; }
            public function allowedCategories(int $v): array { return array_map(fn ($id) => ['id' => $id, 'name' => 'C' . $id, 'slug' => 'c' . $id], $this->allowed); }
            public function formMasters(?int $categoryId = null): array { return ['tax' => [['id' => 4, 'name' => 'GST 18%']], 'units' => [['id' => 1, 'name' => 'Piece']], 'brands' => []]; }
            public function skuExists(string $s): bool { return false; }
            public function createWithVariant(array $d, ?int $a = null): ?int { return 5; }
            public function findById(int $id): ?array { return null; }
        });
        Services::injectMock('productBarcodeRepository', new class {
            public function forProduct(int $id): array { return []; }
            public function saveFromForm(int $p, ?int $v, array $post, ?int $a = null): array { return ['added' => 0, 'errors' => []]; }
        });
    }

    public function testProductNewShowsGatedCategories(): void
    {
        $this->grant(['product.create']);
        $this->productRepoMock([10, 11]);
        $r = $this->withSession($this->sess())->get('admin/products/new?vendor_id=1');
        $r->assertStatus(200);
        $body = (string) $r->getBody();
        $this->assertStringContainsString('business-type gated', $body);
        // redesigned shell (RB4): accordion + completeness meter + autosave hook
        $this->assertStringContainsString('pf-acc', $body);
        $this->assertStringContainsString('Completeness', $body);
        $this->assertStringContainsString('data-autosave-base', $body);
        // Barcodes (RB5) live on the Variants page now, not the product create form —
        // a brand-new product has no variants yet to attach a barcode to. The old
        // assertion here checked for that markup on the wrong page.
    }

    public function testDuplicateClonesAndRedirectsToEdit(): void
    {
        $this->grant(['product.create']);
        Services::injectMock('adminProductRepository', new class {
            public function findById(int $id): ?array { return ['id' => $id, 'vendor_id' => 1, 'title' => 'X']; }
            public function duplicate(int $id, ?int $a = null): ?int { return 88; }
        });
        $r = $this->withSession($this->postSess())->post('admin/products/7/duplicate', $this->csrf());
        $r->assertRedirect();
        $this->assertStringContainsString('admin/products/88/edit', (string) $r->getRedirectUrl());
    }

    public function testDuplicateToAllowedTargetVendor(): void
    {
        $this->grant(['product.create']);
        Services::injectMock('adminProductRepository', new class {
            public ?int $target = null;
            public function findById(int $id): ?array { return ['id' => $id, 'vendor_id' => 1, 'category_id' => 3, 'title' => 'X']; }
            public function allowedCategories(int $v): array { return [['id' => 3]]; } // category 3 allowed for target
            public function duplicate(int $id, ?int $a = null, ?int $t = null): ?int { $this->target = $t; return 88; }
        });
        $r = $this->withSession($this->postSess())->post('admin/products/7/duplicate', $this->csrf() + ['target_vendor_id' => 2]);
        $r->assertRedirect();
        $this->assertStringContainsString('admin/products/88/edit', (string) $r->getRedirectUrl());
    }

    public function testDuplicateToVendorRejectsDisallowedCategory(): void
    {
        $this->grant(['product.create']);
        Services::injectMock('adminProductRepository', new class {
            public function findById(int $id): ?array { return ['id' => $id, 'vendor_id' => 1, 'category_id' => 3, 'title' => 'X']; }
            public function allowedCategories(int $v): array { return [['id' => 99]]; } // 3 NOT allowed for target
            public function duplicate(int $id, ?int $a = null, ?int $t = null): ?int { return 88; }
        });
        $r = $this->withSession($this->postSess())->post('admin/products/7/duplicate', $this->csrf() + ['target_vendor_id' => 2]);
        $r->assertRedirect();
        $this->assertStringNotContainsString('/edit', (string) $r->getRedirectUrl());
    }

    public function testProductStoreRejectsCategoryOutsideBusinessType(): void
    {
        $this->grant(['product.create']);
        $this->productRepoMock([10, 11]); // allowed
        $data = $this->csrf() + ['vendor_id' => '1', 'shop_id' => '1', 'title' => 'X', 'category_id' => '99', 'tax_class_id' => '4', 'unit_id' => '1', 'sku' => 'S1', 'mrp' => '100', 'base_price' => '90'];
        $r = $this->withSession($this->postSess())->post('admin/products/store', $data);
        $r->assertRedirect();
        // not created — redirect back (to the form), not to the list
        $this->assertStringNotContainsString('admin/products"', (string) $r->getRedirectUrl());
    }

    public function testProductStoreSucceedsForAllowedCategory(): void
    {
        $this->grant(['product.create']);
        $this->productRepoMock([10, 11]);
        Services::injectMock('mediaRepository', new class {
            public function hasPrimary(int $p): bool { return true; }
            public function forProduct(int $p): array { return []; }
        });
        $data = $this->csrf() + ['vendor_id' => '1', 'shop_id' => '1', 'title' => 'Loafers', 'category_id' => '10', 'tax_class_id' => '4', 'unit_id' => '1', 'sku' => 'LF-1', 'mrp' => '2999', 'base_price' => '2499'];
        $r = $this->withSession($this->postSess())->post('admin/products/store', $data);
        $r->assertRedirect();
        $this->assertStringContainsString('admin/products', $r->getRedirectUrl());
    }

    // ---- Import ----
    public function testImportUploadRunsService(): void
    {
        $this->grant(['import.run']);
        Services::injectMock('importService', new class {
            public function run($file, string $type, ?int $a): array { return ['ok' => true, 'job_id' => 12, 'total' => 3, 'imported' => 2, 'errors' => 1]; }
        });
        // no real file -> getFile returns null -> "choose a file"; we assert the guard+route work
        $r = $this->withSession($this->postSess())->post('admin/imports/upload', $this->csrf() + ['type' => 'product']);
        $r->assertRedirect();
    }

    public function testImportTemplateDownloads(): void
    {
        $this->grant(['import.run']);
        $r = $this->withSession($this->sess())->get('admin/imports/template/product');
        $r->assertStatus(200);
        // now a real Excel workbook: the response carries the OOXML zip parts
        // (the unit test verifies the bytes open as a valid .xlsx)
        $body = (string) $r->getBody();
        $this->assertStringContainsString('[Content_Types].xml', $body);
        $this->assertStringContainsString('xl/workbook.xml', $body);
    }

    // ---- Media serve guard ----
    public function testMediaServeRejectsNonUuid(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->get('media/..%2f..%2fetc');
    }

    public function testMediaServe404ForUnknown(): void
    {
        Services::injectMock('mediaRepository', new class {
            public function findByUuid(string $u): ?array { return null; }
        });
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->get('media/00000000-0000-4000-8000-000000000000');
    }
}
