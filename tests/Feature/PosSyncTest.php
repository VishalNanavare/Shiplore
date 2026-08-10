<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

require_once __DIR__ . '/../_support/MinimalSchema.php';

/**
 * Phase 10 — Windows POS sync API: activation, catalog/stock download, delta
 * pull, idempotent invoice push (offline-queue reconciliation) and shifts.
 * JWT filter runs; PosSyncRepository mocked. apiAuthRepository is NOT mocked —
 * JwtAuthFilter re-checks isActive() against a real (SQLite) users row on every
 * request, so one must exist here.
 */
final class PosSyncTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => [], 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
        $this->ensureUsersTable();
        $this->seedActiveUser(4, 'staff', 'Cashier');
        // PosController::activate() resolves callerVendorIds() via a real (unmocked)
        // vendorAccountRepository — the terminal mock below is vendor_id 1, so user 4
        // must own vendor 1 or activation is correctly refused (an empty allow-list
        // matches nothing, by design — see the P1b fix this test exercises).
        $this->ensureVendorsTable();
        $this->ensureVendorStaffTable();
        // Explicit id + delete-first, like seedActiveUser(): the SQLite :memory: DB is
        // shared across every test method in this process, so a plain insert() without
        // this would auto-increment a NEW vendor id on each setUp() call — by the 2nd
        // or 3rd test in the file, that id no longer matches the hardcoded vendor_id=1
        // the terminal mock below assumes, and every endpoint that resolves a terminal
        // (not just activate) starts 404ing as an "unknown terminal" out of nowhere.
        $this->schemaConn()->table('vendors')->where('id', 1)->delete();
        $this->schemaConn()->query(
            'INSERT INTO db_vendors (id, owner_user_id, legal_name, display_name, party_type, status) VALUES (1, 4, ?, ?, ?, ?)',
            ['Cashier Co', 'Cashier Co', 'vendor', 'active'],
        );
        Services::injectMock('posSyncRepository', new class {
            public function activate(string $code, string $fp): ?array { return $code === 'POS-DEMO-0001' ? ['id' => 1, 'shop_id' => 1, 'vendor_id' => 1, 'name' => 'C1', 'invoice_prefix' => 'SMA', 'status' => 'active'] : null; }
            public function terminal(int $id): ?array { return $id === 1 ? ['id' => 1, 'shop_id' => 1, 'vendor_id' => 1, 'name' => 'C1', 'invoice_prefix' => 'SMA', 'status' => 'active'] : null; }
            public function bootstrap(array $t): array { return ['server_time' => '2026-06-10 10:00:00', 'catalog' => [['variant_id' => 3, 'barcode' => '890123', 'base_price' => 2499, 'tax_rate' => 12]], 'stock' => [['variant_id' => 3, 'on_hand' => 20]]]; }
            public function pull(array $t, ?string $since): array { return ['server_time' => '2026-06-10 10:05:00', 'catalog' => [], 'stock' => [], 'next_anchor' => '2026-06-10 10:05:00']; }
            public function push(array $t, ?int $shift, int $cashier, array $sales): array
            {
                // first invoice new, second a replay -> idempotent duplicate
                return [
                    ['offline_invoice_no' => 'SMA-20260610-1', 'status' => 'synced', 'server_invoice_no' => 'SMA/2026-27/00001'],
                    ['offline_invoice_no' => 'SMA-20260610-2', 'status' => 'duplicate', 'server_invoice_no' => 'SMA/2026-27/00002'],
                ];
            }
            public function openShift(int $t, int $u, float $f): ?int { return 7; }
            public function closeShift(int $id, float $cash, int $termId): ?array { return $id === 7 ? ['shift_id' => 7, 'expected_cash' => 5400.0, 'counted_cash' => $cash, 'variance' => $cash - 5400.0] : null; }
            // shiftOpen() is idempotent — it refuses when a shift is ALREADY open
            // (currentShift() !== null -> CONFLICT). This mock unconditionally returned
            // one, so testShiftOpenAndClose's own opening call could never succeed:
            // null here is "nothing open yet", which is what that test actually needs.
            public function currentShift(int $t): ?array { return null; }
            public function customers(string $q): array { return [['id' => 1, 'name' => 'Aarav', 'phone' => '9812345678']]; }
        });
    }

    protected function tearDown(): void
    {
        $this->dropUsersTable();
        Services::reset();
        parent::tearDown();
    }

    private function bearer(): array
    {
        $secret = (string) (getenv('JWT_SECRET') ?: env('jwt.secret', 'dev-insecure-secret-change-me'));
        $token  = service('tokenService')->issue(['sub' => 4, 'typ' => 'staff', 'name' => 'Cashier'], 3600, $secret, time());

        return ['Authorization' => 'Bearer ' . $token];
    }

    public function testActivateBindsTerminal(): void
    {
        $r = $this->withHeaders($this->bearer())->post('api/v1/pos/activate', ['activation_code' => 'POS-DEMO-0001', 'device_fingerprint' => 'DEV-1']);
        $r->assertStatus(200);
        $this->assertStringContainsString('SMA', (string) $r->getJSON());
    }

    public function testActivateRejectsBadCode(): void
    {
        $this->withHeaders($this->bearer())->post('api/v1/pos/activate', ['activation_code' => 'NOPE'])->assertStatus(404);
    }

    public function testBootstrapDownloadsCatalogAndStock(): void
    {
        $r = $this->withHeaders($this->bearer())->get('api/v1/pos/bootstrap?terminal_id=1');
        $r->assertStatus(200);
        $body = (string) $r->getJSON();
        $this->assertStringContainsString('catalog', $body);
        $this->assertStringContainsString('barcode', $body);
    }

    public function testPullReturnsAnchor(): void
    {
        $r = $this->withHeaders($this->bearer())->get('api/v1/pos/sync/pull?terminal_id=1&since=2026-06-01');
        $r->assertStatus(200);
        $this->assertStringContainsString('next_anchor', (string) $r->getJSON());
    }

    public function testPushIsIdempotent(): void
    {
        $payload = ['terminal_id' => 1, 'shift_id' => 7, 'sales' => [
            ['offline_invoice_no' => 'SMA-20260610-1', 'grand_total' => 2799, 'lines' => [], 'payments' => []],
            ['offline_invoice_no' => 'SMA-20260610-2', 'grand_total' => 999, 'lines' => [], 'payments' => []],
        ]];
        $r = $this->withHeaders($this->bearer())->post('api/v1/pos/sync/push', $payload);
        $r->assertStatus(200);
        $body = (string) $r->getJSON();
        $this->assertStringContainsString('duplicate', $body);    // replay deduped
        $this->assertStringContainsString('SMA/2026-27/00001', $body);
    }

    public function testPushRejectsEmpty(): void
    {
        $this->withHeaders($this->bearer())->post('api/v1/pos/sync/push', ['terminal_id' => 1, 'sales' => []])->assertStatus(422);
    }

    public function testShiftOpenAndClose(): void
    {
        $open = $this->withHeaders($this->bearer())->post('api/v1/pos/shift/open', ['terminal_id' => 1, 'opening_float' => 2000]);
        $open->assertStatus(201);

        $close = $this->withHeaders($this->bearer())->post('api/v1/pos/shift/close', ['terminal_id' => 1, 'shift_id' => 7, 'counted_cash' => 5350]);
        $close->assertStatus(200);
        $this->assertStringContainsString('variance', (string) $close->getJSON());
    }

    public function testCustomerLookupNeedsThreeChars(): void
    {
        $this->withHeaders($this->bearer())->get('api/v1/pos/customers?terminal_id=1&q=98')->assertStatus(422);
        $this->withHeaders($this->bearer())->get('api/v1/pos/customers?terminal_id=1&q=9812')->assertStatus(200);
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $this->get('api/v1/pos/bootstrap?terminal_id=1')->assertStatus(401);
    }
}
