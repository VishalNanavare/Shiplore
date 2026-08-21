<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;

require_once __DIR__ . '/../_support/MinimalSchema.php';

/**
 * VendorPosController::syncPush() — POST /api/v1/vendor/pos/sync/push.
 *
 * The on-prem "Local API" (pos/server, a separate Flutter/Dart POS project)
 * pushes completed offline sales up in batches. A DIFFERENT contract from
 * PosController::push() (the older ASP.NET Windows POS's terminal-activation-
 * scoped push, untouched) — sales land in pos_local_sales, a table forked
 * from pos_sales specifically because pos_sales.terminal_id/shift_id/
 * cashier_user_id are all hard foreign keys this vendor-JWT-scoped, no-cloud-
 * terminal contract has no equivalent for.
 */
final class VendorPosSyncPushTest extends CIUnitTestCase
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
        $this->seedActiveUser(4, 'vendor', 'Owner');
        $this->seedActiveUser(5, 'vendor', 'Other Owner');
        $this->ensureVendorsTable();
        $this->ensureShopsTable();
        $this->ensurePosLocalSalesTables();

        $db = Database::connect();
        $db->table('vendors')->where('id', 1)->delete();
        $db->table('vendors')->where('id', 2)->delete();
        $db->query(
            'INSERT INTO db_vendors (id, owner_user_id, legal_name, display_name, party_type, status) VALUES (1, 4, ?, ?, ?, ?)',
            ['Owner Co', 'Owner Co', 'vendor', 'active'],
        );
        $db->query(
            'INSERT INTO db_vendors (id, owner_user_id, legal_name, display_name, party_type, status) VALUES (2, 5, ?, ?, ?, ?)',
            ['Other Co', 'Other Co', 'vendor', 'active'],
        );
        $db->table('shops')->where('id', 10)->delete();
        $db->table('shops')->where('id', 20)->delete();
        $db->table('shops')->insert(['id' => 10, 'vendor_id' => 1, 'name' => 'Owner Shop', 'code' => 'SMA', 'status' => 'active']);
        $db->table('shops')->insert(['id' => 20, 'vendor_id' => 2, 'name' => 'Other Shop', 'code' => 'OTH', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        $db = Database::connect();
        $db->table('vendors')->whereIn('id', [1, 2])->delete();
        $db->table('shops')->whereIn('id', [10, 20])->delete();
        $db->table('pos_local_sales')->whereIn('shop_id', [10, 20])->delete();
        $this->dropUsersTable();
        Services::reset();
        parent::tearDown();
    }

    private function bearer(int $userId): array
    {
        $secret = (string) (getenv('JWT_SECRET') ?: env('jwt.secret', 'dev-insecure-secret-change-me'));
        $token  = service('tokenService')->issue(['sub' => $userId, 'typ' => 'vendor', 'name' => 'Owner'], 3600, $secret, time());

        return ['Authorization' => 'Bearer ' . $token];
    }

    /** @return array<string,mixed> */
    private function sale(string $uuid, string $offlineNo = 'LOCAL-1'): array
    {
        return [
            'uuid' => $uuid, 'terminal_id' => 'local-till-1', 'offline_invoice_no' => $offlineNo,
            'grand_total' => 250.0, 'taxable_value' => 220.0, 'cgst' => 15.0, 'sgst' => 15.0,
            'igst' => 0, 'cess' => 0, 'round_off' => 0, 'sold_at' => '2026-08-21 10:00:00',
            'items' => [
                ['variant_id' => 501, 'sku_snapshot' => 'SKU1', 'qty' => 2, 'unit_price' => 110.0, 'taxable_value' => 220.0, 'cgst' => 15.0, 'sgst' => 15.0, 'igst' => 0, 'cess' => 0, 'line_total' => 220.0],
            ],
            'payments' => [
                ['tender_type' => 'cash', 'amount' => 250.0],
            ],
        ];
    }

    public function testPushRequiresAVendorOwnerAccount(): void
    {
        $this->seedActiveUser(6, 'vendor', 'Not An Owner');

        $r = $this->withHeaders($this->bearer(6))->post('api/v1/vendor/pos/sync/push', ['shop_id' => 10, 'sales' => [$this->sale('u-1')]]);

        $r->assertStatus(403);
    }

    public function testPushRejectsAShopThatDoesNotBelongToThisVendor(): void
    {
        $r = $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/push', ['shop_id' => 20, 'sales' => [$this->sale('u-1')]]);

        $r->assertStatus(404);
    }

    public function testPushRejectsAnEmptySalesArray(): void
    {
        $r = $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/push', ['shop_id' => 10, 'sales' => []]);

        $r->assertStatus(422);
    }

    public function testPushInsertsANewSaleWithItsLinesAndPayments(): void
    {
        $r    = $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/push', ['shop_id' => 10, 'sales' => [$this->sale('u-new-1')]]);
        $body = json_decode((string) $r->getJSON(), true);

        $r->assertStatus(200);
        $this->assertSame('synced', $body['data']['results'][0]['status']);
        $this->assertNotEmpty($body['data']['results'][0]['server_invoice_no']);

        $db  = Database::connect();
        $row = $db->table('pos_local_sales')->where('uuid', 'u-new-1')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['vendor_id']);
        $this->assertSame(10, (int) $row['shop_id']);
        $this->assertSame('local-till-1', $row['local_terminal_id']);

        $items = $db->table('pos_local_sale_items')->where('pos_local_sale_id', $row['id'])->get()->getResultArray();
        $this->assertCount(1, $items);
        $this->assertSame(501, (int) $items[0]['variant_id']);

        $payments = $db->table('pos_local_sale_payments')->where('pos_local_sale_id', $row['id'])->get()->getResultArray();
        $this->assertCount(1, $payments);
        $this->assertSame('cash', $payments[0]['tender_type']);
    }

    public function testPushIsIdempotentOnReplayOfTheSameUuid(): void
    {
        $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/push', ['shop_id' => 10, 'sales' => [$this->sale('u-replay')]]);

        $r    = $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/push', ['shop_id' => 10, 'sales' => [$this->sale('u-replay')]]);
        $body = json_decode((string) $r->getJSON(), true);

        $this->assertSame('duplicate', $body['data']['results'][0]['status']);
        $this->assertSame(1, Database::connect()->table('pos_local_sales')->where('uuid', 'u-replay')->countAllResults(), 'a replay must not insert a second row');
    }

    public function testPushRejectsASaleWithNoUuid(): void
    {
        $sale = $this->sale('');
        unset($sale['uuid']);

        $r    = $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/push', ['shop_id' => 10, 'sales' => [$sale]]);
        $body = json_decode((string) $r->getJSON(), true);

        $r->assertStatus(200);
        $this->assertSame('rejected', $body['data']['results'][0]['status']);
    }

    public function testPushHandlesAMixedBatchAndReportsEachResultInOrder(): void
    {
        $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/push', ['shop_id' => 10, 'sales' => [$this->sale('u-already')]]);

        $r    = $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/push', [
            'shop_id' => 10,
            'sales'   => [$this->sale('u-already'), $this->sale('u-fresh', 'LOCAL-2')],
        ]);
        $body = json_decode((string) $r->getJSON(), true);

        $this->assertSame('duplicate', $body['data']['results'][0]['status']);
        $this->assertSame('synced', $body['data']['results'][1]['status']);
        $this->assertSame(1, $body['meta']['synced']);
        $this->assertSame(2, $body['meta']['received']);
    }

    /**
     * uuid dedup is global (not vendor-scoped), matching pos_sales.uuid's own
     * existing UNIQUE KEY precedent — not a tenant-isolation gap: uuid is
     * client-generated (effectively random), so a genuine cross-vendor
     * collision is not realistically triggerable.
     */
    public function testDuplicateCheckMatchesPosSalesGlobalUuidUniquenessPrecedent(): void
    {
        $this->withHeaders($this->bearer(5))->post('api/v1/vendor/pos/sync/push', ['shop_id' => 20, 'sales' => [$this->sale('u-shared')]]);

        $r    = $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/push', ['shop_id' => 10, 'sales' => [$this->sale('u-shared')]]);
        $body = json_decode((string) $r->getJSON(), true);

        $this->assertSame('duplicate', $body['data']['results'][0]['status']);
    }
}
