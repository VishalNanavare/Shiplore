<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Phase 6 — remaining admin modules (brands, promotions, reviews, warehouses,
 * transfers, coupons, notifications, audit logs, deliveries, imports, settings,
 * reports). One batch: list renders (RBAC), an action per action-module (CSRF),
 * and permission-denied. Repositories mocked.
 */
final class AdminPhase6BatchTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.in');
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

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Super Admin', 'principal_type' => 'platform'];
    }

    private function csrf(): array
    {
        return [csrf_token() => csrf_hash()];
    }

    private function postSession(): array
    {
        return service('session')->get() + $this->sess();
    }

    // ---- Brands ----
    public function testBrandsList(): void
    {
        $this->grant(['brand.view']);
        Services::injectMock('brandRepository', new class {
            public function list(?string $s = null): array { return [['id' => 1, 'name' => 'Sole Mate', 'slug' => 'sole-mate', 'status' => 'pending', 'created_at' => '2026-06-01']]; }
            public function findById(int $id): ?array { return ['id' => 1, 'status' => 'pending']; }
            public function updateStatus(int $id, string $st, ?int $a = null): bool { return true; }
        });
        $r = $this->withSession($this->sess())->get('admin/brands');
        $r->assertStatus(200);
        $this->assertStringContainsString('brandsTable', (string) $r->getBody());
    }

    public function testBrandApprove(): void
    {
        $this->grant(['brand.approve']);
        Services::injectMock('brandRepository', new class {
            public function list(?string $s = null): array { return []; }
            public function findById(int $id): ?array { return ['id' => 1, 'status' => 'pending']; }
            public function updateStatus(int $id, string $st, ?int $a = null): bool { return true; }
        });
        $r = $this->withSession($this->postSession())->post('admin/brands/1/approve', $this->csrf());
        $r->assertRedirect();
        $this->assertStringContainsString('admin/brands', $r->getRedirectUrl());
    }

    public function testBrandsDenied(): void
    {
        $this->grant(['shop.view']);
        $this->withSession($this->sess())->get('admin/brands')->assertRedirect();
    }

    // ---- Promotions ----
    public function testPromotionsList(): void
    {
        $this->grant(['promotion.view']);
        Services::injectMock('promotionRepository', new class {
            public function list(?string $s = null): array { return [['id' => 1, 'name' => 'Festive', 'type' => 'percentage', 'value' => '20', 'funded_by' => 'vendor', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30', 'status' => 'active', 'vendor' => 'Sole Mate']]; }
            public function findById(int $id): ?array { return ['id' => 1, 'status' => 'active']; }
            public function updateStatus(int $id, string $st, ?int $a = null): bool { return true; }
        });
        $r = $this->withSession($this->sess())->get('admin/promotions');
        $r->assertStatus(200);
        $this->assertStringContainsString('promotionsTable', (string) $r->getBody());
    }

    public function testPromotionPause(): void
    {
        $this->grant(['promotion.manage']);
        Services::injectMock('promotionRepository', new class {
            public function list(?string $s = null): array { return []; }
            public function findById(int $id): ?array { return ['id' => 1, 'status' => 'active']; }
            public function updateStatus(int $id, string $st, ?int $a = null): bool { return true; }
        });
        $r = $this->withSession($this->postSession())->post('admin/promotions/1/pause', $this->csrf());
        $r->assertRedirect();
        $this->assertStringContainsString('admin/promotions', $r->getRedirectUrl());
    }

    // ---- Reviews ----
    public function testReviewsList(): void
    {
        $this->grant(['review.moderate']);
        Services::injectMock('reviewRepository', new class {
            public function list(?string $s = null): array { return [['id' => 1, 'rating' => 5, 'title' => 'Nice', 'body' => 'Good', 'is_verified_purchase' => 1, 'status' => 'pending', 'created_at' => '2026-06-01', 'product' => 'Sneakers']]; }
            public function findById(int $id): ?array { return ['id' => 1, 'status' => 'pending']; }
            public function updateStatus(int $id, string $st, ?int $a = null): bool { return true; }
        });
        $r = $this->withSession($this->sess())->get('admin/reviews');
        $r->assertStatus(200);
        $this->assertStringContainsString('reviewsTable', (string) $r->getBody());
    }

    public function testReviewPublish(): void
    {
        $this->grant(['review.moderate']);
        Services::injectMock('reviewRepository', new class {
            public function list(?string $s = null): array { return []; }
            public function findById(int $id): ?array { return ['id' => 1, 'status' => 'pending']; }
            public function updateStatus(int $id, string $st, ?int $a = null): bool { return true; }
        });
        $r = $this->withSession($this->postSession())->post('admin/reviews/1/publish', $this->csrf());
        $r->assertRedirect();
        $this->assertStringContainsString('admin/reviews', $r->getRedirectUrl());
    }

    public function testReviewRestoreFromRejectedGoesToPending(): void
    {
        $this->grant(['review.moderate']);
        Services::injectMock('reviewRepository', new class {
            public ?string $applied = null;
            public function list(?string $s = null): array { return []; }
            public function findById(int $id): ?array { return ['id' => 1, 'status' => 'rejected']; }
            public function updateStatus(int $id, string $st, ?int $a = null): bool { $this->applied = $st; return true; }
        });
        $this->withSession($this->postSession())->post('admin/reviews/1/restore', $this->csrf())->assertRedirect();
        $this->assertSame('pending', service('reviewRepository')->applied);
    }

    public function testReviewPublishBlockedFromRejected(): void
    {
        $this->grant(['review.moderate']);
        Services::injectMock('reviewRepository', new class {
            public ?string $applied = null;
            public function list(?string $s = null): array { return []; }
            public function findById(int $id): ?array { return ['id' => 1, 'status' => 'rejected']; }
            public function updateStatus(int $id, string $st, ?int $a = null): bool { $this->applied = $st; return true; }
        });
        $this->withSession($this->postSession())->post('admin/reviews/1/publish', $this->csrf())->assertRedirect();
        // The status guard blocks publishing a rejected review — updateStatus never runs.
        $this->assertNull(service('reviewRepository')->applied);
    }

    public function testReviewRestoreFromPublishedGoesToPending(): void
    {
        $this->grant(['review.moderate']);
        Services::injectMock('reviewRepository', new class {
            public ?string $applied = null;
            public function list(?string $s = null): array { return []; }
            public function findById(int $id): ?array { return ['id' => 1, 'status' => 'published']; }
            public function updateStatus(int $id, string $st, ?int $a = null): bool { $this->applied = $st; return true; }
        });
        $this->withSession($this->postSession())->post('admin/reviews/1/restore', $this->csrf())->assertRedirect();
        $this->assertSame('pending', service('reviewRepository')->applied);
    }

    // ---- Warehouses ----
    public function testWarehousesList(): void
    {
        $this->grant(['warehouse.view']);
        Services::injectMock('warehouseRepository', new class {
            public function list(?string $s = null): array { return [['id' => 1, 'name' => 'WH1', 'code' => 'WH-1', 'pincode' => '400001', 'state_code' => '27', 'status' => 'active', 'vendor' => 'Sole Mate']]; }
            public function findById(int $id): ?array { return ['id' => 1, 'status' => 'active']; }
            public function updateStatus(int $id, string $st, ?int $a = null): bool { return true; }
        });
        $r = $this->withSession($this->sess())->get('admin/warehouses');
        $r->assertStatus(200);
        $this->assertStringContainsString('warehousesTable', (string) $r->getBody());
    }

    public function testWarehouseDeactivate(): void
    {
        $this->grant(['warehouse.manage']);
        Services::injectMock('warehouseRepository', new class {
            public function list(?string $s = null): array { return []; }
            public function findById(int $id): ?array { return ['id' => 1, 'status' => 'active']; }
            public function updateStatus(int $id, string $st, ?int $a = null): bool { return true; }
        });
        $r = $this->withSession($this->postSession())->post('admin/warehouses/1/deactivate', $this->csrf());
        $r->assertRedirect();
        $this->assertStringContainsString('admin/warehouses', $r->getRedirectUrl());
    }

    // ---- Transfers ----
    public function testTransfersList(): void
    {
        $this->grant(['transfer.view']);
        Services::injectMock('transferRepository', new class {
            public function list(?string $s = null, ?int $v = null): array { return [['id' => 1, 'transfer_no' => 'TR-1', 'vendor_id' => 1, 'vendor' => 'Acme', 'title' => 'Tee', 'qty' => '20.000', 'qty_dispatched' => '0.000', 'qty_received' => '0.000', 'status' => 'requested', 'created_at' => '2026-06-01', 'from_shop' => 'A', 'to_shop' => 'B', 'sku' => 'SKU-1']]; }
            public function findById(int $id): ?array { return ['id' => 1, 'vendor_id' => 1, 'status' => 'requested']; }
        });
        $r = $this->withSession($this->sess())->get('admin/transfers');
        $r->assertStatus(200);
        $this->assertStringContainsString('transfersTable', (string) $r->getBody());
    }

    public function testTransferApproveOverridesViaEngine(): void
    {
        $this->grant(['transfer.approve']);
        Services::injectMock('transferRepository', new class {
            public function list(?string $s = null, ?int $v = null): array { return []; }
            public function findById(int $id): ?array { return ['id' => 1, 'vendor_id' => 7, 'status' => 'requested']; }
        });
        $spy = new class {
            public array $calls = [];
            public function approve(int $id, int $v, ?int $a = null): array { $this->calls[] = [$id, $v, $a]; return ['ok' => true, 'message' => 'ok']; }
        };
        Services::injectMock('transferService', $spy);
        $r = $this->withSession($this->postSession())->post('admin/transfers/1/approve', $this->csrf());
        $r->assertRedirect();
        $this->assertSame([1, 7, 1], $spy->calls[0]);   // admin drives the engine as the transfer's vendor (7)
    }

    // ---- Read-only modules ----
    public function testCouponsList(): void
    {
        $this->grant(['coupon.manage']);
        Services::injectMock('couponRepository', new class {
            public function list(?string $s = null): array { return [['id' => 1, 'code' => 'FEST20', 'usage_limit' => 1000, 'per_user_limit' => 1, 'used_count' => 84, 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30', 'status' => 'active', 'promotion' => 'Festive']]; }
        });
        $r = $this->withSession($this->sess())->get('admin/coupons');
        $r->assertStatus(200);
        $this->assertStringContainsString('couponsTable', (string) $r->getBody());
    }

    public function testNotificationsList(): void
    {
        $this->grant(['notification.send']);
        Services::injectMock('notificationRepository', new class {
            public function list(?string $s = null, int $l = 200): array { return [['id' => 1, 'event_code' => 'order.placed', 'title' => 'Confirmed', 'category' => 'transactional', 'status' => 'sent', 'read_at' => null, 'created_at' => '2026-06-05 10:03', 'recipient' => 'Aarav']]; }
        });
        $r = $this->withSession($this->sess())->get('admin/notifications');
        $r->assertStatus(200);
        $this->assertStringContainsString('notificationsTable', (string) $r->getBody());
    }

    public function testAuditLogsList(): void
    {
        $this->grant(['audit.view']);
        Services::injectMock('auditLogRepository', new class {
            public function list(?string $a = null, int $l = 300): array { return [['id' => 1, 'action' => 'vendor.approve', 'entity_type' => 'vendor', 'entity_id' => 1, 'actor_principal_type' => 'platform', 'result' => 'success', 'ip' => '127.0.0.1', 'created_at' => '2026-06-01 09:00', 'actor' => 'Admin']]; }
        });
        $r = $this->withSession($this->sess())->get('admin/audit-logs');
        $r->assertStatus(200);
        $this->assertStringContainsString('auditTable', (string) $r->getBody());
    }

    public function testDeliveriesList(): void
    {
        $this->grant(['delivery.view']);
        Services::injectMock('deliveryRepository', new class {
            public function list(?string $s = null): array { return [['id' => 1, 'mode' => 'self', 'status' => 'delivered', 'delivery_fee' => '0.0000', 'eta_at' => '2026-06-05 13:00', 'created_at' => '2026-06-05', 'sub_order_no' => 'SO-1', 'shop' => 'Andheri']]; }
        });
        $r = $this->withSession($this->sess())->get('admin/deliveries');
        $r->assertStatus(200);
        $this->assertStringContainsString('deliveriesTable', (string) $r->getBody());
    }

    public function testImportsList(): void
    {
        $this->grant(['import.run']);
        Services::injectMock('importJobRepository', new class {
            public function list(?string $s = null): array { return [['id' => 1, 'type' => 'product', 'total_rows' => 120, 'processed_rows' => 120, 'error_rows' => 0, 'status' => 'completed', 'created_at' => '2026-06-01', 'requested_by' => 'Priya']]; }
        });
        $r = $this->withSession($this->sess())->get('admin/imports');
        $r->assertStatus(200);
        $this->assertStringContainsString('importsTable', (string) $r->getBody());
    }

    public function testSettingsPage(): void
    {
        $this->grant(['settings.view']);
        Services::injectMock('settingsRepository', new class {
            public function settings(): array { return [['id' => 1, 'namespace' => 'core', 'skey' => 'currency', 'value' => 'INR', 'value_type' => 'string', 'scope_type' => 'platform', 'status' => 'active']]; }
            public function featureFlags(): array { return [['id' => 1, 'fkey' => 'pos_offline', 'description' => 'Offline POS', 'is_enabled' => 1, 'status' => 'active']]; }
            public function gstConfig(): ?array { return ['rounding_mode' => 'commercial', 'composition' => 0, 'rcm_default' => 0, 'einvoice_enabled' => 1, 'invoice_series_format' => 'INV-{FY}-{SEQ}', 'fy_start_month' => 4]; }
            public function deliveryMaxRadiusKm(): float { return 5.0; }
            public function brandName(): string { return 'Shiplore'; }
            public function logoUrl(): string { return 'https://shiplore.in/assets/images/logo.png'; }
            public function moduleEnabled(string $m): bool { return true; }
        });
        $r = $this->withSession($this->sess())->get('admin/settings');
        $r->assertStatus(200);
        $this->assertStringContainsString('Feature Flags', (string) $r->getBody());
    }

    public function testReportsPage(): void
    {
        $this->grant(['report.view']);
        Services::injectMock('reportRepository', new class {
            public function summary(): array { return ['orders_total' => 6, 'gross_sales' => 30000.0, 'commission_earned' => 2500.0, 'refunds_total' => 2798.88, 'settlements_due' => 120000.0, 'active_vendors' => 2, 'customers' => 5, 'pending_reviews' => 5]; }
            public function salesByChannel(): array { return [['channel' => 'online', 'orders' => 4, 'revenue' => 22000.0]]; }
            public function topVendors(int $l = 5): array { return [['vendor' => 'Sole Mate', 'orders' => 3, 'revenue' => 142500.0]]; }
        });
        $r = $this->withSession($this->sess())->get('admin/reports');
        $r->assertStatus(200);
        $this->assertStringContainsString('Top Vendors', (string) $r->getBody());
    }

    public function testReadOnlyModulesDenied(): void
    {
        $this->grant(['shop.view']);
        foreach (['coupons', 'notifications', 'audit-logs', 'deliveries', 'imports', 'settings', 'reports'] as $path) {
            $this->withSession($this->sess())->get('admin/' . $path)->assertRedirect();
        }
    }
}
