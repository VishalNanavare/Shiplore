<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Phase 6 — Admin Order management: list + detail (RBAC-guarded), cancel
 * (CSRF), permission-denied. Repository mocked; webAuth session simulated.
 */
final class AdminOrderTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grant(['order.view', 'order.cancel']);

        Services::injectMock('orderRepository', new class {
            public function list(?string $status = null): array
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
