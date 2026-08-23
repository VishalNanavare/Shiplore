<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** Batch 8 finish — vendor adds its own delivery rider (tenant-scoped). */
final class VendorStaffTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private object $repo;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'vendor.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array { return [['permissions' => [], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate']; }
            public function findStaffVendor(int $u): ?array { return null; }
            public function shopIdsForVendor(int $v): array { return [1, 2]; }
            public function shopIdsForStaff(int $vs): array { return [1]; }
        });
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 1, 'name' => 'Andheri'], ['id' => 2, 'name' => 'Bandra']]; }
        });
        $this->repo = new class {
            public bool $added = false;
            public bool $phoneTaken = false;
            public array $created = [];
            public function staff(int $v): array { return []; }
            public function staffWithShops(int $v): array { return []; }
            public function riders(int $v): array { return []; }
            public function phoneExists(string $p): bool { return $this->phoneTaken; }
            public function emailExists(string $e, ?int $x = null): bool { return false; }
            public function findStaff(int $id, int $v): ?array { return $id === 7 && $v === 1 ? ['id' => 7, 'user_id' => 70, 'name' => 'S', 'staff_type' => 'cashier', 'status' => 'active'] : null; }
            public function staffShops(int $id): array { return [1]; }
            public function addRider(int $v, array $d, ?int $a = null): ?int { $this->added = true; return 9; }
            public function createStaff(int $v, array $d, ?int $a = null): ?int { $this->created = $d; return 11; }
            public function updateStaff(int $id, int $v, array $d, ?int $a = null): bool { $this->created = $d; return true; }
            public function setStatus(int $id, int $v, string $s, ?int $a = null): bool { return true; }
        };
        Services::injectMock('vendorStaffRepository', $this->repo);
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
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

    public function testAddRiderSucceeds(): void
    {
        $r = $this->withSession($this->postSess())->post('vendor/staff/riders', [csrf_token() => csrf_hash(), 'name' => 'Ravi', 'phone' => '9812345678', 'vehicle_type' => 'bike']);
        $r->assertRedirect();
        $this->assertTrue($this->repo->added);
    }

    public function testAddRiderRejectsBadPhone(): void
    {
        $r = $this->withSession($this->postSess())->post('vendor/staff/riders', [csrf_token() => csrf_hash(), 'name' => 'Ravi', 'phone' => 'abc']);
        $r->assertRedirect();
        $this->assertFalse($this->repo->added);
    }

    public function testAddRiderRejectsDuplicatePhone(): void
    {
        $this->repo->phoneTaken = true;
        $r = $this->withSession($this->postSess())->post('vendor/staff/riders', [csrf_token() => csrf_hash(), 'name' => 'Ravi', 'phone' => '9812345678']);
        $r->assertRedirect();
        $this->assertFalse($this->repo->added);
    }

    public function testStaffListAndNewFormRenderForOwner(): void
    {
        $this->withSession($this->sess())->get('vendor/staff')->assertStatus(200);
        $r = $this->withSession($this->sess())->get('vendor/staff/new');
        $r->assertStatus(200);
        $this->assertStringContainsString('Login email', (string) $r->getBody());
    }

    /**
     * A vendor with exactly one shop has no real assignment DECISION to make, but the
     * "Assign" checkbox rendered unchecked by default — a form.php:49 `checked`
     * attribute driven entirely by $assigned, which StaffController::new() only ever
     * populated from a ?shop= query param. Reported live: filling in every other *
     * required field and clicking "Create staff" without noticing the lone checkbox
     * (no asterisk, no visual cue it's required) fails validated() at
     * "Assign the staff member to at least one of your shops." — read as "Add staff
     * isn't working" because nothing about the form suggested that click was needed.
     */
    public function testNewFormPreselectsTheOnlyShopWhenTheVendorHasExactlyOne(): void
    {
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 42, 'name' => 'Little Feet Kidswear']]; }
        });

        $r = $this->withSession($this->sess())->get('vendor/staff/new');

        $r->assertStatus(200);
        $this->assertMatchesRegularExpression(
            '/name="shop_ids\[\]" value="42"[^>]*checked/',
            (string) $r->getBody(),
            'the only shop a single-shop vendor could possibly assign to should be preselected',
        );
    }

    /** A vendor with more than one shop keeps the existing behaviour: no default pick. */
    public function testNewFormLeavesShopsUnselectedWhenTheVendorHasMoreThanOne(): void
    {
        $r = $this->withSession($this->sess())->get('vendor/staff/new');

        $r->assertStatus(200);
        $this->assertDoesNotMatchRegularExpression(
            '/name="shop_ids\[\]" value="1"[^>]*checked/',
            (string) $r->getBody(),
        );
        $this->assertDoesNotMatchRegularExpression(
            '/name="shop_ids\[\]" value="2"[^>]*checked/',
            (string) $r->getBody(),
        );
    }

    public function testCreateConstrainsShopsToOwnVendor(): void
    {
        // shop 99 is not the vendor's; it must be filtered out, only 1 + 2 survive
        $r = $this->withSession($this->postSess())->post('vendor/staff', [
            csrf_token() => csrf_hash(), 'name' => 'Cashier', 'email' => 'c@x.test', 'password' => 'secret12',
            'staff_type' => 'cashier', 'shop_ids' => [1, 2, 99], 'primary_shop' => 1,
        ]);
        $r->assertRedirect();
        $this->assertSame([1, 2], $this->repo->created['shop_ids']);
    }

    public function testCreateRejectsRoleWithNoShop(): void
    {
        $r = $this->withSession($this->postSess())->post('vendor/staff', [
            csrf_token() => csrf_hash(), 'name' => 'Cashier', 'email' => 'c@x.test', 'password' => 'secret12',
            'staff_type' => 'cashier', 'shop_ids' => [99], 'primary_shop' => 99,  // only a foreign shop -> empty after filter
        ]);
        $r->assertRedirect();
        $this->assertSame([], $this->repo->created);   // createStaff not called
    }

    public function testStaffManagementDeniedForNonOwnerWithoutPermission(): void
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array { return [['permissions' => ['pos.sell'], 'scope_type' => 'shop', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return null; }                 // not an owner
            public function findStaffVendor(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate', 'vendor_staff_id' => 5]; }
            public function shopIdsForStaff(int $vs): array { return [1]; }
            public function shopIdsForVendor(int $v): array { return [1, 2]; }
        });
        $r = $this->withSession($this->sess())->get('vendor/staff/new');
        $r->assertRedirect();
        $this->assertStringContainsString('vendor/staff', (string) $r->getRedirectUrl());
        $this->assertSame([], $this->repo->created);
    }
}
