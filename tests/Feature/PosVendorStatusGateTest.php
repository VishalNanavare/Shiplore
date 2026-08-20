<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

require_once __DIR__ . '/../_support/MinimalSchema.php';

/**
 * Vendor status lifecycle, phase 4's POS/apps half: Api\V1\PosController and
 * Api\V1\VendorPosController.
 *
 * PosController::terminal() is the single choke point every POS request (scan, sync,
 * customers) resolves through — one check there covers all of them, mirroring
 * BaseVendorController::requireVendor()'s role for the web vendor panel. It already
 * carries the muscle memory this reuses: "unknown or inactive terminal" already means
 * "return null, the controller reports FORBIDDEN" for the terminal's OWN status, so a
 * blocked vendor status folds into the exact same response shape — no new error case
 * for the frozen POS client to learn.
 *
 * Log-only by default; see VendorStatusGateTest for the flag mechanics.
 */
final class PosVendorStatusGateTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('vendor.enforceStatusGate');
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => [], 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
        $this->ensureUsersTable();
        $this->seedActiveUser(4, 'staff', 'Cashier');
        $this->ensureVendorsTable();
        $this->ensureVendorStaffTable();
        $this->ensureShopsTable();
        $this->schemaConn()->table('vendors')->where('id', 1)->delete();
        $this->schemaConn()->query(
            'INSERT INTO db_vendors (id, owner_user_id, legal_name, display_name, party_type, status) VALUES (1, 4, ?, ?, ?, ?)',
            ['Cashier Co', 'Cashier Co', 'vendor', 'suspended'],
        );
        $this->schemaConn()->table('shops')->where('id', 1)->delete();
        $this->schemaConn()->table('shops')->insert(['id' => 1, 'vendor_id' => 1, 'name' => 'C1 Shop', 'status' => 'active']);

        // Reads the vendor's CURRENT status from the same DB row the tests flip, rather
        // than a value baked in at mock-construction time — otherwise a test that
        // updates the vendors table mid-test would have no way to affect this mock's
        // answer.
        $db = $this->schemaConn();
        Services::injectMock('posSyncRepository', new class ($db) {
            public function __construct(private object $db) {}
            public function terminal(int $id): ?array
            {
                if ($id !== 1) {
                    return null;
                }
                $status = (string) ($this->db->table('vendors')->select('status')->where('id', 1)->get()->getRowArray()['status'] ?? '');

                return ['id' => 1, 'shop_id' => 1, 'vendor_id' => 1, 'name' => 'C1', 'invoice_prefix' => 'SMA', 'status' => 'active', 'vendor_status' => $status];
            }
        });
        Services::injectMock('productBarcodeRepository', new class {
            public function resolveAll(string $code, int $vendorId, int $shopId): array
            {
                return [['product_id' => 9, 'resolved_variant_id' => 3, 'title' => 'Widget', 'barcode_type' => 'ean13', 'pack_level' => 'each']];
            }
        });
    }

    protected function tearDown(): void
    {
        $this->dropUsersTable();
        putenv('vendor.enforceStatusGate');
        Services::reset();
        parent::tearDown();
    }

    private function bearer(): array
    {
        $secret = (string) (getenv('JWT_SECRET') ?: env('jwt.secret', 'dev-insecure-secret-change-me'));
        $token  = service('tokenService')->issue(['sub' => 4, 'typ' => 'staff', 'name' => 'Cashier'], 3600, $secret, time());

        return ['Authorization' => 'Bearer ' . $token];
    }

    // ------------------------------------------------------------------ PosController::terminal()

    public function testByDefaultAScanForASuspendedVendorsTerminalStillWorks(): void
    {
        $r = $this->withHeaders($this->bearer())->get('api/v1/pos/scan/890123?terminal_id=1');

        $r->assertStatus(200);
    }

    public function testWithTheFlagSetAScanForASuspendedVendorsTerminalIsRefused(): void
    {
        putenv('vendor.enforceStatusGate=true');

        $r = $this->withHeaders($this->bearer())->get('api/v1/pos/scan/890123?terminal_id=1');

        $r->assertStatus(403);
        $this->assertStringContainsString('inactive terminal', (string) $r->getJSON(), 'reuses the terminal\'s OWN existing error shape — no new case for the frozen client');
    }

    public function testWithTheFlagSetAnActiveVendorsTerminalStillWorks(): void
    {
        $this->schemaConn()->table('vendors')->where('id', 1)->update(['status' => 'active']);
        putenv('vendor.enforceStatusGate=true');

        $this->withHeaders($this->bearer())->get('api/v1/pos/scan/890123?terminal_id=1')->assertStatus(200);
    }

    /**
     * The blocked vendor's OWN id must reach the log line, not a placeholder — an
     * operator grepping "vendor-status gate" needs to know WHICH vendor, not just that
     * something was blocked. shouldBlockForVendorStatus() logs on log-only too, so no
     * flag flip is needed to observe this.
     */
    public function testTheLoggedVendorIdIsTheTerminalsOwnVendor(): void
    {
        $log    = ROOTPATH . 'writable/logs/log-' . date('Y-m-d') . '.log';
        $before = is_file($log) ? filesize($log) : 0;

        $this->withHeaders($this->bearer())->get('api/v1/pos/scan/890123?terminal_id=1');

        $tail = substr((string) file_get_contents($log), $before);
        $this->assertStringContainsString('vendor #1', $tail);
    }

    // ------------------------------------------------------------------ VendorPosController::shops()

    public function testByDefaultTheShopListStillWorksForASuspendedVendorOwner(): void
    {
        $r = $this->withHeaders($this->bearer())->get('api/v1/vendor/pos/shops');

        $r->assertStatus(200);
    }

    public function testWithTheFlagSetTheShopListIsRefusedForASuspendedVendorOwner(): void
    {
        putenv('vendor.enforceStatusGate=true');

        $this->withHeaders($this->bearer())->get('api/v1/vendor/pos/shops')->assertStatus(403);
    }

    public function testWithTheFlagSetTheShopListStillWorksForAnActiveVendorOwner(): void
    {
        $this->schemaConn()->table('vendors')->where('id', 1)->update(['status' => 'active']);
        putenv('vendor.enforceStatusGate=true');

        $this->withHeaders($this->bearer())->get('api/v1/vendor/pos/shops')->assertStatus(200);
    }
}
