<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

require_once __DIR__ . '/../_support/MinimalSchema.php';

/**
 * Phase 7 — Vendor panel. Verifies session auth, the not-a-vendor guard, that
 * pages render, and — critically — TENANT ISOLATION: the controller resolves
 * the vendor from the session user and scopes every repo call by that vendorId,
 * so a foreign record (wrong vendor) is never returned.
 */
final class VendorPanelTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'vendor.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        // WebAuthFilter re-checks apiAuthRepository->isActive() on every request and is
        // fail-open only when the query throws; once db_users exists it must contain
        // every session user_id this file uses, or previously-passing tests start
        // redirecting to login instead of exercising what they actually test.
        $this->ensureUsersTable();
        $this->seedActiveUser(101, 'vendor', 'Rahul Mehta');
        $this->seedActiveUser(202, 'vendor', 'Cashier');

        // webAuth filter resolves capabilities from the DB — mock it (the vendor
        // panel itself enforces isolation by vendorId, not by permissions).
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => [], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []]];
            }
        });

        // Session user 101 owns vendor 1; anyone else owns no vendor.
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $userId): ?array
            {
                return $userId === 101
                    ? ['id' => 1, 'display_name' => 'Sole Mate Footwear', 'legal_name' => 'Sole Mate Footwear Pvt Ltd', 'slug' => 'sole-mate', 'gstin' => '27AAACS1234F1Z5', 'gstin_status' => 'verified', 'status' => 'active', 'business_type_id' => 1]
                    : null;
            }
            public function findStaffVendor(int $userId): ?array { return null; }
            public function shopIdsForVendor(int $v): array { return [1, 2]; }
            public function shopIdsForStaff(int $vs): array { return [1]; }
        });
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 1, 'name' => 'Andheri'], ['id' => 2, 'name' => 'Bandra']]; }
        });
    }

    protected function tearDown(): void
    {
        $this->dropUsersTable();
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function vendorSession(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 101, 'user_name' => 'Rahul Mehta', 'principal_type' => 'vendor'];
    }

    private function postSession(): array
    {
        return service('session')->get() + $this->vendorSession();
    }

    public function testDashboardRequiresLogin(): void
    {
        $this->get('vendor/dashboard')->assertRedirect();
    }

    public function testNonVendorIsRejected(): void
    {
        // Logged in as a user that owns no vendor -> bounced to login.
        $result = $this->withSession(['isLoggedIn' => true, 'user_id' => 999, 'user_name' => 'X'])->get('vendor/dashboard');
        $result->assertRedirect();
    }

    public function testDashboardRenders(): void
    {
        Services::injectMock('vendorDashboardRepository', new class {
            public function metrics(int $vendorId): array { return ['shops' => 2, 'products' => 5, 'open_orders' => 1, 'sales' => 142500.0]; }
            public function recentOrders(int $vendorId, int $limit = 8): array { return [['id' => 1, 'sub_order_no' => 'SO-1001-1', 'grand_total' => '3918.88', 'status' => 'completed', 'created_at' => '2026-06-05', 'order_no' => 'ORD-2026-1001', 'customer' => 'Aarav']]; }
            public function perShop(int $v, array $s): array { return []; }
        });
        $result = $this->withSession($this->vendorSession())->get('vendor/dashboard');
        $result->assertStatus(200);
        $this->assertStringContainsString('Sole Mate Footwear', (string) $result->getBody());
        $this->assertStringContainsString('SO-1001-1', (string) $result->getBody());
    }

    /**
     * Runtime counterpart to CrossPanelLinkFixTest's source assertion: the sidebar's
     * monline entries must be emitted as absolute URLs on the monline host. Rendered
     * on vendor.shiplore.test (see setUp), a relative /monline/browse would hit a path
     * the monline route group never registers on this host — a 404 on the main
     * navigation vendors use to buy from manufacturers.
     */
    public function testSidebarMonlineLinksPointAtTheMonlineHost(): void
    {
        Services::injectMock('vendorDashboardRepository', new class {
            public function metrics(int $vendorId): array { return ['shops' => 0, 'products' => 0, 'open_orders' => 0, 'sales' => 0.0]; }
            public function recentOrders(int $vendorId, int $limit = 8): array { return []; }
            public function perShop(int $v, array $s): array { return []; }
        });
        $body = (string) $this->withSession($this->vendorSession())->get('vendor/dashboard')->getBody();

        $this->assertStringContainsString('//monline.shiplore.test/monline/browse', $body);
        $this->assertStringContainsString('//monline.shiplore.test/monline/orders', $body);
        // The bug being locked out: the same paths resolved against the vendor host.
        $this->assertStringNotContainsString('//vendor.shiplore.test/monline/', $body);
    }

    /** ...while every same-panel link stays on the vendor host. */
    public function testSidebarKeepsVendorLinksOnTheVendorHost(): void
    {
        Services::injectMock('vendorDashboardRepository', new class {
            public function metrics(int $vendorId): array { return ['shops' => 0, 'products' => 0, 'open_orders' => 0, 'sales' => 0.0]; }
            public function recentOrders(int $vendorId, int $limit = 8): array { return []; }
            public function perShop(int $v, array $s): array { return []; }
        });
        $body = (string) $this->withSession($this->vendorSession())->get('vendor/dashboard')->getBody();

        $this->assertStringContainsString('vendor/products', $body);
        $this->assertStringNotContainsString('monline.shiplore.test/vendor/', $body);
    }

    public function testStaffMemberSeesShopScopedSidebar(): void
    {
        // A cashier-style staff member (not the owner) with limited shop-scoped perms.
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $userId): array { return [['permissions' => ['pos.sell', 'inventory.view', 'order.view.own'], 'scope_type' => 'shop', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $userId): ?array { return null; }   // not an owner
            public function findStaffVendor(int $userId): ?array { return ['id' => 1, 'display_name' => 'Sole Mate Footwear', 'status' => 'active', 'business_type_id' => 1, 'vendor_staff_id' => 5]; }
            public function shopIdsForStaff(int $vsId): array { return [1]; }
            public function shopIdsForVendor(int $vid): array { return [1, 2]; }
        });
        Services::injectMock('vendorDashboardRepository', new class {
            public function metrics(int $vendorId): array { return ['shops' => 2, 'products' => 5, 'open_orders' => 1, 'sales' => 1000.0]; }
            public function recentOrders(int $vendorId, int $limit = 8): array { return []; }
            public function perShop(int $v, array $s): array { return []; }
        });
        $body = (string) $this->withSession(['isLoggedIn' => true, 'user_id' => 202, 'user_name' => 'Cashier', 'principal_type' => 'vendor'])->get('vendor/dashboard')->getBody();
        // granted items show
        $this->assertStringContainsString('POS Billing', $body);
        $this->assertStringContainsString('Orders', $body);
        // owner-only items hidden for staff
        $this->assertStringNotContainsString('Settlements', $body);
        // The topbar's active-location chip. It was generalised from "shop" so the
        // manufacturer panel could use it; the vendor path relies on the shop-name
        // FALLBACK, so this asserts that fallback still works.
        $this->assertStringContainsString('Andheri', $body, 'shop-scoped staff must still see their active shop');
        $this->assertStringNotContainsString('Staff &amp; Riders', $body);
    }

    public function testShopsListScopedToVendor(): void
    {
        Services::injectMock('vendorShopRepository', new class {
            public ?int $listedVendor = null;
            public function list(int $vendorId): array { $this->listedVendor = $vendorId; return [['id' => 1, 'name' => 'Andheri', 'code' => 'SM-AND', 'pincode' => '400058', 'state_code' => '27', 'delivery_radius_km' => '5', 'prep_time_min' => 20, 'pickup_enabled' => 1, 'gstin_status' => 'verified', 'status' => 'active']]; }
            public function findById(int $id, int $vendorId): ?array { return null; }
            public function hours(int $shopId): array { return []; }
            public function updateStatus(int $id, int $vendorId, string $st, ?int $a = null): bool { return true; }
        });
        $result = $this->withSession($this->vendorSession())->get('vendor/shops');
        $result->assertStatus(200);
        $this->assertStringContainsString('Andheri', (string) $result->getBody());
    }

    public function testForeignShopIsNotAccessible(): void
    {
        // The repo only returns a row when BOTH id AND the acting vendorId match.
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $vendorId): array { return []; }
            public function findById(int $id, int $vendorId): ?array { return ($id === 1 && $vendorId === 1) ? ['id' => 1, 'name' => 'Andheri', 'code' => 'X', 'pincode' => '1', 'state_code' => '27', 'delivery_radius_km' => '5', 'prep_time_min' => 20, 'pickup_enabled' => 1, 'gstin' => null, 'gstin_status' => 'verified', 'status' => 'active'] : null; }
            public function hours(int $shopId): array { return []; }
            public function updateStatus(int $id, int $vendorId, string $st, ?int $a = null): bool { return true; }
        });
        Services::injectMock('vendorStaffRepository', new class {
            public function staffForShop(int $shopId, int $vendorId): array { return []; }
        });
        // Own shop (id 1) opens fine:
        $this->withSession($this->vendorSession())->get('vendor/shops/1')->assertStatus(200);
        // A shop belonging to another vendor (id 2) -> not found -> redirected away.
        $foreign = $this->withSession($this->vendorSession())->get('vendor/shops/2');
        $foreign->assertRedirect();
        $this->assertStringContainsString('vendor/shops', $foreign->getRedirectUrl());
    }

    public function testShopEditRendersHoursForOwner(): void
    {
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 1, 'name' => 'Andheri']]; }
            public function findById(int $id, int $v): ?array { return $id === 1 && $v === 1 ? ['id' => 1, 'name' => 'Andheri', 'pincode' => '400058', 'state_code' => '27', 'address_json' => '{"line":"MG Rd"}'] : null; }
            public function hours(int $shopId): array { return []; }
            public function holidays(int $shopId): array { return []; }
        });
        $r = $this->withSession($this->vendorSession())->get('vendor/shops/1/edit');
        $r->assertStatus(200);
        $this->assertStringContainsString('Opening hours', (string) $r->getBody());
        $this->assertStringContainsString('Holidays', (string) $r->getBody());
    }

    public function testSaveHoursWiresSevenDays(): void
    {
        $spy = new class {
            public array $rows = [];
            public function findById(int $id, int $v): ?array { return $id === 1 && $v === 1 ? ['id' => 1, 'name' => 'A'] : null; }
            public function saveHours(int $s, int $v, array $rows, ?int $a = null): bool { $this->rows = $rows; return true; }
        };
        Services::injectMock('vendorShopRepository', $spy);
        $r = $this->withSession($this->postSession())->post('vendor/shops/1/hours', [csrf_token() => csrf_hash(), 'open_1' => '09:00', 'close_1' => '18:00', 'closed_0' => '1']);
        $r->assertRedirect();
        $this->assertCount(7, $spy->rows);
        $this->assertSame('09:00', $spy->rows[1]['open']);
        $this->assertTrue($spy->rows[0]['is_closed']);
    }

    public function testHolidayAddWires(): void
    {
        $spy = new class {
            public array $got = [];
            public function findById(int $id, int $v): ?array { return $id === 1 && $v === 1 ? ['id' => 1, 'name' => 'A'] : null; }
            public function addHoliday(int $s, int $v, string $date, string $reason, ?int $a = null): bool { $this->got = [$date, $reason]; return true; }
        };
        Services::injectMock('vendorShopRepository', $spy);
        $r = $this->withSession($this->postSession())->post('vendor/shops/1/holidays', [csrf_token() => csrf_hash(), 'holiday_date' => '2026-11-01', 'reason' => 'Diwali']);
        $r->assertRedirect();
        $this->assertSame(['2026-11-01', 'Diwali'], $spy->got);
    }

    public function testHoursByStaffWithoutPermissionBecomesChangeRequest(): void
    {
        // X3 governance: the old hard denial now CONVERTS into a change request
        // (doc 44 AR-08) — the direct write must still never happen.
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array { return [['permissions' => ['pos.sell'], 'scope_type' => 'shop', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return null; }
            public function findStaffVendor(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate', 'vendor_staff_id' => 5, 'staff_type' => 'cashier']; }
            public function shopIdsForStaff(int $vs): array { return [1]; }
            public function shopIdsForVendor(int $v): array { return [1]; }
        });
        $spy = new class {
            public bool $saved = false;
            public function findById(int $id, int $v): ?array { return ['id' => 1, 'name' => 'A']; }
            public function saveHours(int $s, int $v, array $rows, ?int $a = null): bool { $this->saved = true; return true; }
        };
        Services::injectMock('vendorShopRepository', $spy);
        $engine = new class {
            public array $submitted = [];
            public function submit(array $in, array $actor, $now = null): array { $this->submitted[] = [$in, $actor]; return ['ok' => true, 'id' => 1, 'status' => 'pending_l1']; }
        };
        Services::injectMock('changeRequestEngine', $engine);

        $r = $this->withSession(['isLoggedIn' => true, 'user_id' => 202, 'user_name' => 'C', 'principal_type' => 'vendor'])->post('vendor/shops/1/hours', [csrf_token() => csrf_hash(), 'open_1' => '09:00']);
        $r->assertRedirect();
        $this->assertFalse($spy->saved);            // no direct write…
        $this->assertCount(1, $engine->submitted);  // …a change request instead
        $this->assertSame('shop', $engine->submitted[0][0]['entity_type']);
        $this->assertSame('hours', $engine->submitted[0][0]['action']);
    }

    public function testOrdersShopFilterScoping(): void
    {
        $spy = new class {
            public mixed $shopArg = 'UNSET';
            public function list(int $v, ?string $s = null, ?int $shop = null): array { $this->shopArg = $shop; return []; }
        };
        Services::injectMock('vendorOrderRepository', $spy);
        // owner, no shop -> all shops (null)
        $this->withSession($this->vendorSession())->get('vendor/orders');
        $this->assertNull($spy->shopArg);
        // owner, allowed shop selected -> that shop
        $this->withSession($this->vendorSession())->get('vendor/orders?shop_id=2');
        $this->assertSame(2, $spy->shopArg);
        // owner, a shop they don't have -> ignored (null = all)
        $this->withSession($this->vendorSession())->get('vendor/orders?shop_id=99');
        $this->assertNull($spy->shopArg);
    }

    public function testStaffOrdersDefaultToTheirShop(): void
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array { return [['permissions' => ['order.view.own'], 'scope_type' => 'shop', 'scope_id' => 2, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return null; }
            public function findStaffVendor(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate', 'vendor_staff_id' => 5]; }
            public function shopIdsForStaff(int $vs): array { return [2]; }       // assigned to shop 2 only
            public function shopIdsForVendor(int $v): array { return [1, 2]; }
        });
        $spy = new class {
            public mixed $shopArg = 'UNSET';
            public function list(int $v, ?string $s = null, ?int $shop = null): array { $this->shopArg = $shop; return []; }
        };
        Services::injectMock('vendorOrderRepository', $spy);
        $this->withSession(['isLoggedIn' => true, 'user_id' => 202, 'user_name' => 'Mgr', 'principal_type' => 'vendor'])->get('vendor/orders');
        $this->assertSame(2, $spy->shopArg);   // staff scoped to their shop even with no ?shop_id
    }

    public function testForeignOrderIsNotAccessible(): void
    {
        Services::injectMock('vendorOrderRepository', new class {
            public function list(int $vendorId, ?string $s = null): array { return []; }
            public function findSubOrder(int $id, int $vendorId): ?array { return ($id === 1 && $vendorId === 1) ? ['id' => 1, 'sub_order_no' => 'SO-1', 'order_no' => 'ORD-1', 'shop' => 'A', 'customer' => 'Aarav', 'taxable_value' => '100', 'cgst' => '0', 'sgst' => '0', 'igst' => '0', 'commission_amount' => '10', 'grand_total' => '110', 'status' => 'confirmed'] : null; }
            public function items(int $subOrderId): array { return []; }
            public function delivery(int $subOrderId): ?array { return null; }
        });
        Services::injectMock('invoiceRepository', new class {
            public function invoiceIdForSubOrder(int $subOrderId): ?int { return null; }
        });
        // owner()'s real query uses TIMESTAMPDIFF, a MySQL-only function the SQLite
        // test DB cannot execute at all — this must be mocked, not backed by a table.
        Services::injectMock('orderClaimService', new class {
            public function owner(int $subOrderId): ?array { return null; }
        });
        // show()'s two remaining raw queries (available riders, whether a pool rider
        // has accepted) are real Database::connect() calls bypassing every repository.
        $this->ensureDeliveryTables();
        $this->withSession($this->vendorSession())->get('vendor/orders/1')->assertStatus(200);
        $foreign = $this->withSession($this->vendorSession())->get('vendor/orders/2');
        $foreign->assertRedirect();
        $this->assertStringContainsString('vendor/orders', $foreign->getRedirectUrl());
    }

    public function testShopCloseScoped(): void
    {
        Services::injectMock('vendorShopRepository', new class {
            public ?int $updatedVendor = null;
            public function list(int $vendorId): array { return []; }
            public function findById(int $id, int $vendorId): ?array { return ['id' => 1, 'status' => 'active']; }
            public function hours(int $shopId): array { return []; }
            public function updateStatus(int $id, int $vendorId, string $st, ?int $a = null): bool { $this->updatedVendor = $vendorId; return true; }
        });
        $result = $this->withSession($this->postSession())->post('vendor/shops/1/close', [csrf_token() => csrf_hash()]);
        $result->assertRedirect();
        $this->assertStringContainsString('vendor/shops', $result->getRedirectUrl());
    }

    public function testProductsAndStaffRender(): void
    {
        Services::injectMock('vendorProductRepository', new class {
            public function list(int $vendorId, ?string $s = null): array { return [['id' => 1, 'title' => 'Sneakers', 'slug' => 'sneakers', 'status' => 'published', 'category' => 'Shoes', 'sku' => 'SK1', 'base_price' => '2499', 'mrp' => '3999']]; }
        });
        Services::injectMock('vendorStaffRepository', new class {
            public function staffWithShops(int $vendorId): array { return [['id' => 1, 'staff_type' => 'cashier', 'employee_code' => 'E1', 'designation' => 'Cashier', 'status' => 'active', 'name' => 'Asha', 'email' => 'a@x.in', 'phone' => '1', 'shops' => 'Andheri']]; }
            public function riders(int $vendorId): array { return [['id' => 1, 'vehicle_type' => 'bike', 'vehicle_no' => 'MH01', 'availability' => 'available', 'status' => 'active', 'name' => 'Ramesh', 'phone' => '2']]; }
        });
        $this->withSession($this->vendorSession())->get('vendor/products')->assertStatus(200);
        $this->withSession($this->vendorSession())->get('vendor/staff')->assertStatus(200);
    }

    public function testInventoryListAndScopedUpdate(): void
    {
        Services::injectMock('vendorInventoryRepository', new class {
            public ?int $stockVendor = null;
            public function list(int $vendorId): array { return [['id' => 1, 'on_hand' => '48', 'reserved' => '3', 'available' => '45', 'reorder_level' => '10', 'title' => 'Sneakers', 'sku' => 'SK1', 'variant_id' => 3, 'base_price' => '2499', 'mrp' => '3999', 'shop' => 'Andheri']]; }
            public function ownsInventory(int $i, int $v): bool { return $v === 1; }
            public function updateStock(int $i, int $v, float $o): bool { $this->stockVendor = $v; return $v === 1; }
            public function updatePrice(int $vid, int $v, float $b, float $m): bool { return $v === 1; }
        });
        $this->withSession($this->vendorSession())->get('vendor/inventory')->assertStatus(200);
        $r = $this->withSession($this->postSession())->post('vendor/inventory/1/update', [csrf_token() => csrf_hash(), 'on_hand' => '50', 'variant_id' => '3', 'base_price' => '2399', 'mrp' => '3999']);
        $r->assertRedirect();
        $this->assertStringContainsString('vendor/inventory', $r->getRedirectUrl());
    }

    public function testTransfersListAndCreate(): void
    {
        Services::injectMock('vendorTransferRepository', new class {
            public function list(int $v): array { return []; }
            public function shops(int $v): array { return [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']]; }
            public function variants(int $v): array { return [['id' => 3, 'sku' => 'SK1', 'title' => 'Sneakers']]; }
        });
        Services::injectMock('transferService', new class {
            public function request(int $vendorId, int $f, int $t, int $vt, float $q, string $r = '', ?int $a = null): array { return ['ok' => $vendorId === 1 && $f !== $t, 'message' => 'Transfer requested.', 'id' => 1]; }
        });
        $this->withSession($this->vendorSession())->get('vendor/transfers')->assertStatus(200);
        $r = $this->withSession($this->postSession())->post('vendor/transfers', [csrf_token() => csrf_hash(), 'from_shop_id' => '1', 'to_shop_id' => '2', 'variant_id' => '3', 'qty' => '5']);
        $r->assertRedirect();
        $this->assertStringContainsString('vendor/transfers', $r->getRedirectUrl());
    }

    public function testRefundsGstNotificationsWarehousesRender(): void
    {
        Services::injectMock('vendorRefundRepository', new class {
            public function list(int $v): array { return [['id' => 1, 'amount' => '430', 'destination' => 'source', 'status' => 'completed', 'created_at' => '2026-06-06', 'order_no' => 'ORD-1004', 'method' => 'upi']]; }
        });
        Services::injectMock('vendorGstRepository', new class {
            public function summary(int $v): array { return ['orders' => 3, 'taxable' => 11796.0, 'cgst' => 359.88, 'sgst' => 359.88, 'igst' => 0.0, 'cess' => 0.0]; }
            public function lines(int $v, int $l = 50): array { return [['sub_order_no' => 'SO-1', 'place_of_supply' => '27', 'taxable_value' => '3499', 'cgst' => '209', 'sgst' => '209', 'igst' => '0', 'grand_total' => '3918', 'created_at' => '2026-06-05']]; }
        });
        Services::injectMock('vendorNotificationRepository', new class {
            public function list(int $userId, int $l = 100): array { return [['id' => 1, 'event_code' => 'order.new', 'title' => 'New order', 'body' => 'x', 'category' => 'transactional', 'status' => 'sent', 'read_at' => null, 'created_at' => '2026-06-08 16:31']]; }
        });
        Services::injectMock('vendorWarehouseRepository', new class {
            public function list(int $v): array { return [['id' => 1, 'name' => 'WH', 'code' => 'WH-1', 'pincode' => '400093', 'state_code' => '27', 'status' => 'active']]; }
        });
        $this->withSession($this->vendorSession())->get('vendor/refunds')->assertStatus(200);
        $this->withSession($this->vendorSession())->get('vendor/gst')->assertStatus(200);
        $this->withSession($this->vendorSession())->get('vendor/notifications')->assertStatus(200);
        $this->withSession($this->vendorSession())->get('vendor/warehouses')->assertStatus(200);
    }
}
