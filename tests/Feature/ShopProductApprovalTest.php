<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * S2 — a branch manager's product changes route through the vendor → admin
 * approval chain; the owner (and harmless drafts) stay direct.
 */
final class ShopProductApprovalTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private object $engine;
    private object $approval;
    private object $products;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new class {
            public array $submitted = [];
            public function submit(array $in, array $actor, $now = null): array { $this->submitted[] = [$in, $actor]; return ['ok' => true, 'id' => 1, 'status' => 'pending_l1']; }
        };
        Services::injectMock('changeRequestEngine', $this->engine);

        $this->approval = new class {
            public array $submitted = [];
            public function submit(int $id, ?int $a = null): bool { $this->submitted[] = $id; return true; }
        };
        Services::injectMock('productApprovalRepository', $this->approval);

        $this->products = new class {
            public array $updated = [];
            public int $status = 0; // set per test: product status
            public string $st = 'published';
            public function findById(int $id): ?array { return ['id' => $id, 'vendor_id' => 1, 'uuid' => 'uuid-' . $id, 'title' => 'Sneaker', 'status' => $this->st, 'category_id' => 10, 'tax_class_id' => 4, 'base_unit_id' => 1, 'sku' => 'SK1', 'mrp' => '999', 'base_price' => '799']; }
            public function allowedCategories(int $v): array { return [['id' => 10, 'name' => 'Shoes']]; }
            public function update(int $id, array $in, ?int $a = null): bool { $this->updated[] = [$id, $in]; return true; }
        };
        Services::injectMock('adminProductRepository', $this->products);
        Services::injectMock('productBarcodeRepository', new class {
            public function saveFromForm(int $p, $v, array $d, ?int $a = null): void {}
        });
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        parent::tearDown();
    }

    private function asManager(): void
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array { return [['permissions' => ['product.create', 'product.update'], 'scope_type' => 'shop', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return null; }
            public function findStaffVendor(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate', 'vendor_staff_id' => 7, 'staff_type' => 'branch_manager']; }
            public function shopIdsForStaff(int $vs): array { return [1]; }
            public function shopIdsForVendor(int $v): array { return [1]; }
        });
    }

    private function asOwner(): void
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array { return [['permissions' => ['product.create', 'product.update'], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate']; }
            public function findStaffVendor(int $u): ?array { return null; }
            public function shopIdsForVendor(int $v): array { return [1]; }
            public function shopIdsForStaff(int $vs): array { return []; }
        });
    }

    private function sess(): array
    {
        return service('session')->get() + ['isLoggedIn' => true, 'user_id' => 110, 'user_name' => 'Sunil', 'principal_type' => 'vendor'];
    }

    private function productPost(): array
    {
        return [csrf_token() => csrf_hash(), 'title' => 'Sneaker', 'category_id' => 10, 'tax_class_id' => 4, 'unit_id' => 1, 'sku' => 'SK1', 'mrp' => '999', 'base_price' => '799'];
    }

    // ---- submit (go-live) -------------------------------------------------

    public function testStaffSubmitBecomesChangeRequest(): void
    {
        $this->asManager();

        $r = $this->withSession($this->sess())->post('vendor/products/5/submit', [csrf_token() => csrf_hash()]);

        $r->assertRedirect();
        $this->assertCount(1, $this->engine->submitted, 'staff submit must become a change request');
        $this->assertSame(['product', 'submit', 5], [$this->engine->submitted[0][0]['entity_type'], $this->engine->submitted[0][0]['action'], $this->engine->submitted[0][0]['entity_id']]);
        $this->assertSame([], $this->approval->submitted, 'must NOT submit directly to admin');
    }

    public function testOwnerSubmitGoesDirectToApprovalGate(): void
    {
        $this->asOwner();

        $r = $this->withSession($this->sess())->post('vendor/products/5/submit', [csrf_token() => csrf_hash()]);

        $r->assertRedirect();
        $this->assertSame([5], $this->approval->submitted, 'owner submits straight into the product-approval gate');
        $this->assertCount(0, $this->engine->submitted);
    }

    // ---- edit ------------------------------------------------------------

    public function testStaffEditingLiveProductBecomesChangeRequest(): void
    {
        $this->asManager();
        $this->products->st = 'published';

        $r = $this->withSession($this->sess())->post('vendor/products/5/update', $this->productPost());

        $r->assertRedirect();
        $this->assertCount(1, $this->engine->submitted);
        $this->assertSame('update', $this->engine->submitted[0][0]['action']);
        $this->assertSame([], $this->products->updated, 'a live product is not written directly by staff');
    }

    public function testStaffEditingDraftWritesDirect(): void
    {
        $this->asManager();
        $this->products->st = 'draft'; // a draft is not live → direct edit

        $r = $this->withSession($this->sess())->post('vendor/products/5/update', $this->productPost());

        $r->assertRedirect();
        $this->assertCount(1, $this->products->updated, 'draft edits are written directly');
        $this->assertCount(0, $this->engine->submitted);
    }

    // ---- inventory --------------------------------------------------------

    private object $inventory;

    private function mockInventory(): void
    {
        Services::injectMock('productVariantRepository', new class {
            public function findVariant(int $id): ?array { return ['id' => $id, 'product_id' => 5, 'vendor_id' => 1]; }
        });
        Services::injectMock('vendorShopRepository', new class {
            public function findById(int $id, int $v): ?array { return $id === 1 ? ['id' => 1, 'name' => 'Andheri'] : null; }
            public function list(int $v): array { return [['id' => 1, 'name' => 'Andheri']]; }
        });
        $this->inventory = new class {
            public array $received = [];
            public array $adjusted = [];
            public function receive(int $vid, int $sid, float $q, float $c, array $b, ?int $a = null): bool { $this->received[] = [$vid, $sid, $q]; return true; }
            public function adjust(int $vid, int $sid, float $d, string $r, string $n, ?int $a = null): bool { $this->adjusted[] = [$vid, $sid, $d]; return true; }
        };
        Services::injectMock('inventoryService', $this->inventory);
    }

    public function testStaffStockReceiveBecomesChangeRequest(): void
    {
        $this->asManager();
        $this->mockInventory();

        $r = $this->withSession($this->sess())->post('vendor/products/5/inventory/receive', [csrf_token() => csrf_hash(), 'variant_id' => 9, 'shop_id' => 1, 'qty' => '10', 'cost' => '50']);

        $r->assertRedirect();
        $this->assertCount(1, $this->engine->submitted);
        $this->assertSame(['inventory', 'receive'], [$this->engine->submitted[0][0]['entity_type'], $this->engine->submitted[0][0]['action']]);
        $this->assertSame([], $this->inventory->received, 'stock must not post to the ledger before approval');
    }

    public function testStaffStockAdjustBecomesChangeRequest(): void
    {
        $this->asManager();
        $this->mockInventory();

        $r = $this->withSession($this->sess())->post('vendor/products/5/inventory/adjust', [csrf_token() => csrf_hash(), 'variant_id' => 9, 'shop_id' => 1, 'qty_delta' => '-3', 'reason' => 'damage']);

        $r->assertRedirect();
        $this->assertCount(1, $this->engine->submitted);
        $this->assertSame('adjust', $this->engine->submitted[0][0]['action']);
        $this->assertSame([], $this->inventory->adjusted);
    }

    public function testOwnerStockReceiveWritesDirect(): void
    {
        $this->asOwner();
        $this->mockInventory();

        $r = $this->withSession($this->sess())->post('vendor/products/5/inventory/receive', [csrf_token() => csrf_hash(), 'variant_id' => 9, 'shop_id' => 1, 'qty' => '10', 'cost' => '50']);

        $r->assertRedirect();
        $this->assertSame([[9, 1, 10.0]], $this->inventory->received, 'owner receives stock directly');
        $this->assertCount(0, $this->engine->submitted);
    }
}
