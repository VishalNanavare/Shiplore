<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

require_once __DIR__ . '/../_support/MinimalSchema.php';

/**
 * Phase 6 — Admin Order management: list + detail (RBAC-guarded), cancel
 * (CSRF), permission-denied. Repository mocked; webAuth session simulated.
 *
 * show() also runs a raw $db->table('sub_orders')... query directly (not
 * through orderRepository, so it can't be mocked away) and instantiates
 * AdminOrderRepository with `new`, bypassing service() DI entirely — both
 * need MinimalSchema's real (SQLite) tables rather than a mock.
 */
final class AdminOrderTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.in');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        $this->grant(['order.view', 'order.cancel']);
        $this->ensureSubOrdersTable();
        $this->ensureSubOrderClaimLogsTable();
        $this->ensureUsersTable();
        // WebAuthFilter re-checks apiAuthRepository->isActive() on every request and is
        // fail-OPEN only when the query THROWS (e.g. the table doesn't exist at all) —
        // once db_users exists but has no matching row, isActive() cleanly returns
        // false, which is fail-CLOSED (a definite "not active" logs the session out).
        // Every session used in this file must have a real active row, or adding the
        // table here would make previously-passing tests start redirecting to login.
        $this->seedActiveUser(1, 'platform', 'Super Admin');
        // show()'s raw claim-info query looks up sub_orders by id directly (not through
        // the mocked orderRepository), and the view reads sub_order_no unconditionally
        // from that row — an empty/no-match result (a missing row is NOT an error here,
        // the query just returns []) leaves the view with no key to read at all.
        $this->schemaConn()->table('sub_orders')->where('id', 5)->delete();
        $this->schemaConn()->query(
            'INSERT INTO db_sub_orders (id, order_id, vendor_id, shop_id, sub_order_no, status) VALUES (5, 1, 1, 1, ?, ?)',
            ['SO-1', 'confirmed'],
        );
        Services::injectMock('returnRepository', new class {
            public function forOrder(int $orderId): array { return []; }
        });
        Services::injectMock('refundRepository', new class {
            public function forOrder(int $orderId): array { return []; }
        });

        Services::injectMock('orderRepository', new class {
            public function search(array $opts = []): array
            {
                return [
                    ['id' => 1, 'order_no' => 'ORD-1001', 'channel' => 'online', 'grand_total' => '2450.0000', 'payment_status' => 'paid', 'status' => 'confirmed', 'placed_at' => '2026-06-08 10:00:00', 'created_at' => '2026-06-08 10:00:00', 'customer' => 'Aarav Sharma'],
                    ['id' => 2, 'order_no' => 'ORD-1002', 'channel' => 'app', 'grand_total' => '980.0000', 'payment_status' => 'pending', 'status' => 'created', 'placed_at' => null, 'created_at' => '2026-06-08 11:00:00', 'customer' => 'Diya Patel'],
                ];
            }
            public function findById(int $id): ?array
            {
                return $id === 1 ? ['id' => 1, 'order_no' => 'ORD-1001', 'channel' => 'online', 'status' => 'confirmed', 'payment_status' => 'paid', 'subtotal' => '2300.0000', 'discount_total' => '0.0000', 'tax_total' => '150.0000', 'delivery_total' => '0.0000', 'grand_total' => '2450.0000', 'customer' => 'Aarav Sharma', 'customer_email' => 'aarav@example.com'] : null;
            }
            public function subOrders(int $orderId): array
            {
                return [['id' => 5, 'sub_order_no' => 'SO-1', 'status' => 'confirmed', 'grand_total' => '2450.0000', 'commission_amount' => '245.0000', 'taxable_value' => '2300.0000', 'cgst' => '75.0000', 'sgst' => '75.0000', 'igst' => '0.0000', 'vendor' => 'Fresh Foods', 'shop' => 'Andheri Outlet']];
            }
            public function items(int $orderId): array
            {
                return [['sub_order_id' => 5, 'product_title_snapshot' => 'Organic Almonds 500g', 'sku_snapshot' => 'ALM-500', 'qty' => '2.000', 'unit_price' => '1150.0000', 'discount_amount' => '0.0000', 'taxable_value' => '2300.0000', 'tax_rate' => '5.00']];
            }
            public function cancel(int $id, int $actorId, ?string $reason = null): bool { return true; }
        });
    }

    protected function tearDown(): void
    {
        $this->dropUsersTable();
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function grant(array $permissions): void
    {
        Services::injectMock('capabilityRepository', new class ($permissions) {
            public function __construct(private array $perms) {}
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => $this->perms, 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
    }

    private function adminSession(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Super Admin', 'principal_type' => 'platform'];
    }

    public function testListRequiresLogin(): void
    {
        $this->get('admin/orders')->assertRedirect();
    }

    public function testListRenders(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/orders');
        $result->assertStatus(200);
        $this->assertStringContainsString('ORD-1001', (string) $result->getBody());
        $this->assertStringContainsString('ordersTable', (string) $result->getBody());
    }

    public function testDetailRenders(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/orders/1');
        $result->assertStatus(200);
        $body = (string) $result->getBody();
        $this->assertStringContainsString('Fresh Foods', $body);
        $this->assertStringContainsString('Organic Almonds 500g', $body);
    }

    public function testDetailUnknownRedirects(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/orders/999');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/orders', $result->getRedirectUrl());
    }

    public function testCancelRedirectsToDetail(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/orders/1/cancel', $data);
        $result->assertRedirect();
        $this->assertStringContainsString('admin/orders/1', $result->getRedirectUrl());
    }

    public function testPermissionDeniedRedirectsToDashboard(): void
    {
        $this->grant(['shop.view']); // lacks order.view
        $result = $this->withSession($this->adminSession())->get('admin/orders');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $result->getRedirectUrl());
    }
}
