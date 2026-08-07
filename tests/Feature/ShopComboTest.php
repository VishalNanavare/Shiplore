<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * S3 — combo offers route by channel: a branch manager's POS-only combo needs
 * the vendor (AR-13); an online combo needs vendor → admin (AR-14). The owner
 * creates directly.
 */
final class ShopComboTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private object $engine;
    private object $combos;

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

        $this->combos = new class {
            public array $created = [];
            public function create(int $v, array $in, ?int $a = null): ?int { $this->created[] = $in; return 50; }
            public function list(int $v): array { return []; }
        };
        Services::injectMock('comboRepository', $this->combos);
        Services::injectMock('adminProductRepository', new class {
            public function allowedCategories(int $v): array { return [['id' => 10, 'name' => 'Snacks']]; }
            public function formMasters(): array { return ['tax' => [['id' => 4, 'name' => 'GST 12%']], 'units' => [['id' => 1, 'name' => 'Piece']], 'brands' => []]; }
        });
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
            public function loadAssignments(int $u): array { return [['permissions' => ['combo.manage'], 'scope_type' => 'shop', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return null; }
            public function findStaffVendor(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate', 'vendor_staff_id' => 7, 'staff_type' => 'branch_manager']; }
            public function shopIdsForStaff(int $vs): array { return [1]; }
            public function shopIdsForVendor(int $v): array { return [1]; }
        });
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 1, 'name' => 'Andheri']]; }
        });
    }

    private function asOwner(): void
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array { return [['permissions' => ['combo.manage'], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []]]; }
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
    }

    private function sess(): array
    {
        return service('session')->get() + ['isLoggedIn' => true, 'user_id' => 110, 'user_name' => 'Sunil', 'principal_type' => 'vendor'];
    }

    private function comboPost(string $channel): array
    {
        return [
            csrf_token() => csrf_hash(), 'name' => 'Coke + Chips', 'category_id' => 10, 'tax_class_id' => 4, 'unit_id' => 1,
            'mrp' => '60', 'price' => '50', 'channel' => $channel, 'inventory_mode' => 'virtual',
            'component_variant_id' => [11, 12], 'component_qty' => [1, 1],
        ];
    }

    public function testStaffPosComboNeedsVendorOnly(): void
    {
        $this->asManager();

        $r = $this->withSession($this->sess())->post('vendor/combos', $this->comboPost('pos'));

        $r->assertRedirect();
        $this->assertCount(1, $this->engine->submitted);
        $this->assertSame(['combo', 'create_pos'], [$this->engine->submitted[0][0]['entity_type'], $this->engine->submitted[0][0]['action']]);
        $this->assertSame([], $this->combos->created, 'not created until approved');
    }

    public function testStaffOnlineComboNeedsVendorThenAdmin(): void
    {
        $this->asManager();

        $r = $this->withSession($this->sess())->post('vendor/combos', $this->comboPost('online'));

        $r->assertRedirect();
        $this->assertSame('create_online', $this->engine->submitted[0][0]['action']);
    }

    public function testOwnerComboCreatedDirectly(): void
    {
        $this->asOwner();

        $r = $this->withSession($this->sess())->post('vendor/combos', $this->comboPost('pos'));

        $r->assertRedirect();
        $this->assertCount(1, $this->combos->created, 'owner creates the combo directly');
        $this->assertCount(0, $this->engine->submitted);
        $this->assertSame('Coke + Chips', $this->combos->created[0]['name']);
    }

    public function testComboNeedsAtLeastTwoComponents(): void
    {
        $this->asOwner();
        $post                          = $this->comboPost('pos');
        $post['component_variant_id']  = [11];
        $post['component_qty']         = [1];

        $r = $this->withSession($this->sess())->post('vendor/combos', $post);

        $r->assertRedirect();
        $this->assertCount(0, $this->combos->created);
    }
}
