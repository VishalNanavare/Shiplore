<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;

require_once __DIR__ . '/../_support/MinimalSchema.php';

/**
 * VendorPosController::syncPull() — POST /api/v1/vendor/pos/sync/pull.
 *
 * The on-prem "Local API" (pos/server, a separate Flutter/Dart POS project)
 * runs a background agent that periodically pulls central price changes for
 * the whole vendor's catalog. This is a DIFFERENT contract from
 * PosController::pull() (the older, already-shipped ASP.NET Windows POS's
 * terminal-scoped catalog+stock pull) — same-sounding name, deliberately
 * separate route, must never collide.
 *
 * since/anchor are Unix timestamps (int), converted in PHP rather than via a
 * DB-specific function like MySQL's UNIX_TIMESTAMP() — that wouldn't run
 * against the SQLite test DB, and portability was cheap here.
 */
final class VendorPosSyncPullTest extends CIUnitTestCase
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
        $this->ensureProductVariantsTable();
        $this->ensureProductsTable();

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
        $db->table('products')->insert(['id' => 1, 'vendor_id' => 1, 'title' => 'Widget', 'status' => 'published']);
    }

    protected function tearDown(): void
    {
        Database::connect()->table('vendors')->whereIn('id', [1, 2])->delete();
        Database::connect()->table('product_variants')->whereIn('vendor_id', [1, 2])->delete();
        Database::connect()->table('products')->where('id', 1)->delete();
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

    private function insertVariant(int $id, int $vendorId, string $basePrice, string $updatedAt, ?string $deletedAt = null): void
    {
        Database::connect()->table('product_variants')->insert([
            'id' => $id, 'product_id' => 1, 'vendor_id' => $vendorId, 'unit_id' => 1,
            'sku' => 'SKU-' . $id, 'base_price' => $basePrice,
            'updated_at' => $updatedAt, 'deleted_at' => $deletedAt,
        ]);
    }

    public function testPullRequiresAVendorOwnerAccount(): void
    {
        $this->seedActiveUser(6, 'vendor', 'Not An Owner');

        $r = $this->withHeaders($this->bearer(6))->post('api/v1/vendor/pos/sync/pull', ['since' => 0, 'types' => ['price']]);

        $r->assertStatus(403);
    }

    public function testPullOnlyReturnsThisVendorsChangedPrices(): void
    {
        $this->insertVariant(101, 1, '199.0000', '2026-08-20 10:00:00');
        $this->insertVariant(201, 2, '299.0000', '2026-08-20 10:00:00');

        $r    = $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/pull', ['since' => 0, 'types' => ['price']]);
        $body = json_decode((string) $r->getJSON(), true);
        $ids  = array_column($body['data']['prices'], 'variant_id');

        $r->assertStatus(200);
        $this->assertContains(101, $ids);
        $this->assertNotContains(201, $ids, 'must never leak another vendor\'s price changes — cross-tenant leak');
    }

    public function testPullOnlyReturnsVariantsChangedAfterSince(): void
    {
        $cutoff = strtotime('2026-08-20 12:00:00');
        $this->insertVariant(101, 1, '99.0000', '2026-08-20 11:00:00');   // before cutoff
        $this->insertVariant(102, 1, '149.0000', '2026-08-20 13:00:00');  // after cutoff

        $r    = $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/pull', ['since' => $cutoff, 'types' => ['price']]);
        $body = json_decode((string) $r->getJSON(), true);
        $ids  = array_column($body['data']['prices'], 'variant_id');

        $this->assertNotContains(101, $ids);
        $this->assertContains(102, $ids);
    }

    /** 'since' is exclusive — a variant updated at exactly the cursor was already seen last pull. */
    public function testPullExcludesAVariantUpdatedExactlyAtSince(): void
    {
        $cutoff = strtotime('2026-08-20 12:00:00');
        $this->insertVariant(101, 1, '99.0000', '2026-08-20 12:00:00');

        $r    = $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/pull', ['since' => $cutoff, 'types' => ['price']]);
        $body = json_decode((string) $r->getJSON(), true);

        $this->assertSame([], $body['data']['prices']);
    }

    public function testPullIgnoresTypesOtherThanPrice(): void
    {
        $this->insertVariant(101, 1, '99.0000', '2026-08-20 10:00:00');

        $r    = $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/pull', ['since' => 0, 'types' => ['stock']]);
        $body = json_decode((string) $r->getJSON(), true);

        $r->assertStatus(200);
        $this->assertSame([], $body['data']['prices'], 'only "price" is a recognised type today — others must not silently return price data');
    }

    /** next_anchor echoes $since when there are no changes — must reflect the CLAMPED value, not the raw negative input. */
    public function testPullClampsANegativeSinceToZero(): void
    {
        $r    = $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/pull', ['since' => -500, 'types' => ['price']]);
        $body = json_decode((string) $r->getJSON(), true);

        $r->assertStatus(200);
        $this->assertSame(0, $body['data']['next_anchor']);
    }

    public function testPullExcludesSoftDeletedVariants(): void
    {
        $this->insertVariant(101, 1, '99.0000', '2026-08-20 10:00:00', '2026-08-20 10:05:00');

        $r    = $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/pull', ['since' => 0, 'types' => ['price']]);
        $body = json_decode((string) $r->getJSON(), true);

        $this->assertSame([], $body['data']['prices']);
    }

    public function testPullAdvancesNextAnchorToTheLatestChange(): void
    {
        $this->insertVariant(101, 1, '99.0000', '2026-08-20 10:00:00');
        $this->insertVariant(102, 1, '149.0000', '2026-08-20 14:00:00');

        $r    = $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/pull', ['since' => 0, 'types' => ['price']]);
        $body = json_decode((string) $r->getJSON(), true);

        $this->assertSame(strtotime('2026-08-20 14:00:00'), $body['data']['next_anchor']);
    }

    public function testPullWithNoChangesReturnsEmptyAndUnchangedAnchor(): void
    {
        $since = strtotime('2026-08-21 00:00:00');

        $r    = $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/pull', ['since' => $since, 'types' => ['price']]);
        $body = json_decode((string) $r->getJSON(), true);

        $r->assertStatus(200);
        $this->assertSame([], $body['data']['prices']);
        $this->assertSame($since, $body['data']['next_anchor']);
    }

    public function testPullReturnsThePriceAsAString(): void
    {
        $this->insertVariant(101, 1, '199.5000', '2026-08-20 10:00:00');

        $r    = $this->withHeaders($this->bearer(4))->post('api/v1/vendor/pos/sync/pull', ['since' => 0, 'types' => ['price']]);
        $body = json_decode((string) $r->getJSON(), true);

        $this->assertIsString($body['data']['prices'][0]['price']);
    }
}
