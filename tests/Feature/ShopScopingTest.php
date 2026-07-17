<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * S0 — shop-level isolation for vendor staff. A branch manager assigned to ONE
 * shop must see and act on ONLY that shop; the owner still sees every shop.
 * This is the regression guard for the "Sunil sees both shops" bug.
 */
final class ShopScopingTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    /** Two shops on the vendor; the manager is assigned to shop 1 (Andheri) only. */
    private function shopRow(int $id, string $name): array
    {
        return ['id' => $id, 'name' => $name, 'code' => 'SM-' . $id, 'pincode' => '40005' . $id,
                'status' => 'active', 'delivery_radius_km' => '5.00', 'prep_time_min' => 20, 'pickup_enabled' => 1];
    }

    private function mockShops(): void
    {
        Services::injectMock('vendorShopRepository', new class ($this->shopRow(...)) {
            public function __construct(private \Closure $row) {}
            public function list(int $v): array { return [($this->row)(1, 'Andheri'), ($this->row)(2, 'Bandra')]; }
            public function findById(int $id, int $v): ?array { return in_array($id, [1, 2], true) ? ($this->row)($id, $id === 1 ? 'Andheri' : 'Bandra') : null; }
            public function hours(int $id): array { return []; }
            public function holidays(int $id): array { return []; }
        });
    }

    /** Log in as the Andheri branch manager (staff, assigned to shop 1 only). */
    private function asManager(array $assignedShops = [1]): void
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array
            {
                return [['permissions' => ['shop.update', 'shop.hours.manage', 'pos.sell'], 'scope_type' => 'shop', 'scope_id' => 1, 'attributes' => []]];
            }
        });
        Services::injectMock('vendorAccountRepository', new class ($assignedShops) {
            public function __construct(private array $shops) {}
            public function findByOwnerUserId(int $u): ?array { return null; }
            public function findStaffVendor(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate Footwear', 'vendor_staff_id' => 7, 'staff_type' => 'branch_manager']; }
            public function shopIdsForStaff(int $vs): array { return $this->shops; }
            public function shopIdsForVendor(int $v): array { return [1, 2]; }
        });
        $this->mockShops();
    }

    private function asOwner(): void
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array { return [['permissions' => ['shop.update'], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate Footwear']; }
            public function findStaffVendor(int $u): ?array { return null; }
            public function shopIdsForVendor(int $v): array { return [1, 2]; }
            public function shopIdsForStaff(int $vs): array { return []; }
        });
        $this->mockShops();
    }

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 110, 'user_name' => 'Sunil More', 'principal_type' => 'vendor'];
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        parent::tearDown();
    }

    // ---- the reported bug -------------------------------------------------

    public function testManagerSeesOnlyAssignedShop(): void
    {
        $this->asManager([1]);

        $body = (string) $this->withSession($this->sess())->get('vendor/shops')->getBody();

        $this->assertStringContainsString('Andheri', $body);
        $this->assertStringNotContainsString('Bandra', $body); // the leak: must NOT appear
    }

    public function testOwnerStillSeesAllShops(): void
    {
        $this->asOwner();

        $body = (string) $this->withSession($this->sess())->get('vendor/shops')->getBody();

        $this->assertStringContainsString('Andheri', $body);
        $this->assertStringContainsString('Bandra', $body);
    }

    public function testZeroShopStaffSeesNoShops(): void
    {
        $this->asManager([]); // assigned to no shop

        $body = (string) $this->withSession($this->sess())->get('vendor/shops')->getBody();

        $this->assertStringNotContainsString('Andheri', $body);
        $this->assertStringNotContainsString('Bandra', $body);
        $this->assertStringContainsString('no shops', strtolower($body));
    }

    // ---- URL-tamper guards on shop-id actions -----------------------------

    public function testManagerBlockedFromForeignShopManage(): void
    {
        $this->asManager([1]);

        $this->withSession($this->sess())->get('vendor/shops/2')->assertRedirectTo('vendor/shops');
    }

    public function testManagerBlockedFromForeignShopEdit(): void
    {
        $this->asManager([1]);

        $this->withSession($this->sess())->get('vendor/shops/2/edit')->assertRedirectTo('vendor/shops');
    }

    // ---- staff section: manager may initiate (-> vendor approval) ----------

    public function testManagerWithStaffRequestCanOpenStaffForm(): void
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array
            {
                return [['permissions' => ['shop.update', 'staff.request'], 'scope_type' => 'shop', 'scope_id' => 1, 'attributes' => []]];
            }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return null; }
            public function findStaffVendor(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate', 'vendor_staff_id' => 7, 'staff_type' => 'branch_manager']; }
            public function shopIdsForStaff(int $vs): array { return [1]; }
            public function shopIdsForVendor(int $v): array { return [1, 2]; }
        });
        $this->mockShops();

        // A branch manager can open the form (the POST will become a request).
        $this->withSession($this->sess())->get('vendor/staff/new')->assertStatus(200);
    }

    public function testManagerWithoutStaffRequestIsBlockedFromStaffForm(): void
    {
        $this->asManager([1]); // perms: shop.update / shop.hours.manage / pos.sell — no staff.request

        $this->withSession($this->sess())->get('vendor/staff/new')->assertRedirectTo('vendor/staff');
    }

    public function testManagerBlockedFromForeignShopClose(): void
    {
        $this->asManager([1]);

        $r = $this->withSession(service('session')->get() + $this->sess())
            ->post('vendor/shops/2/close', [csrf_token() => csrf_hash()]);
        $r->assertRedirectTo('vendor/shops');
    }

    public function testManagerCanOpenOwnShopPages(): void
    {
        $this->asManager([1]);

        // own shop (1) is not redirected away by the access guard
        $this->withSession($this->sess())->get('vendor/shops/1/edit')->assertStatus(200);
    }

    // ---- S1 shop-context chrome -------------------------------------------

    public function testSingleShopManagerSeesShopChip(): void
    {
        $this->asManager([1]);

        $body = (string) $this->withSession($this->sess())->get('vendor/shops')->getBody();

        // a single-shop manager gets an active-shop chip (text-bg-primary badge in
        // the topbar — unique to the chip on this page), not a switcher
        $this->assertStringContainsString('text-bg-primary', $body);
        $this->assertStringContainsString('Andheri', $body);
        $this->assertStringNotContainsString('name="shop_id"', $body);
    }

    public function testMultiShopManagerGetsSwitcher(): void
    {
        $this->asManager([1, 2]); // assigned to both shops

        $body = (string) $this->withSession($this->sess())->get('vendor/shops')->getBody();

        // both assigned shops are visible, and a store switcher appears
        $this->assertStringContainsString('Andheri', $body);
        $this->assertStringContainsString('Bandra', $body);
        $this->assertStringContainsString('name="shop_id"', $body);
    }

    public function testOwnerHasNoShopChip(): void
    {
        $this->asOwner();

        $body = (string) $this->withSession($this->sess())->get('vendor/shops')->getBody();

        // the owner operates vendor-wide — no active-shop chip/switcher
        $this->assertStringNotContainsString('text-bg-primary', $body);
        $this->assertStringNotContainsString('name="shop_id"', $body);
    }

    // ---- POS billing leak --------------------------------------------------

    public function testManagerPosSaleAtForeignShopRejected(): void
    {
        $this->asManager([1]);

        $r = $this->withSession(service('session')->get() + $this->sess())
            ->post('vendor/pos/sale', [csrf_token() => csrf_hash(), 'shop_id' => 2, 'cart' => [], 'payments' => []]);

        $this->assertSame(422, $r->response()->getStatusCode());
        $this->assertStringContainsString('valid store', (string) $r->getBody());
    }
}
