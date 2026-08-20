<?php

declare(strict_types=1);

use App\Models\ShopRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Shop approval, phase 4: ShopRepository::approve()/reject() — the write half of the
 * admin "Pending Approval → Shop Approval" queue. Mirrors
 * VendorController::transition()'s approve/reject shape, adapted to shops' own two
 * columns: approve() flips status AND approval_status together (the shop's first
 * go-live); reject() leaves status exactly where it is (inactive) and records why.
 *
 * Both are scoped to approval_status='pending' in the WHERE clause itself, not just
 * checked beforehand — a double-click or a stale page cannot re-approve an
 * already-decided shop.
 */
final class ShopRepositoryApprovalTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureShopsTable();
        $db = Database::connect();
        $db->table('shops')->where('id', 93001)->delete();
        $db->table('shops')->insert(['id' => 93001, 'vendor_id' => 1, 'name' => 'Pending Shop', 'status' => 'inactive', 'approval_status' => 'pending']);

        // approve() triggers a facet-cache invalidation (delivery zones changed) —
        // mirrors ShopRepository::updateStatus()'s existing side effect exactly.
        Services::injectMock('facetCache', new class {
            public int $invalidations = 0;
            public function invalidate(): void { $this->invalidations++; }
        });
    }

    protected function tearDown(): void
    {
        Database::connect()->table('shops')->where('id', 93001)->delete();
        Services::reset();
        parent::tearDown();
    }

    private function row(): array
    {
        return Database::connect()->table('shops')->where('id', 93001)->get()->getRowArray();
    }

    public function testApproveFlipsBothStatusAndApprovalStatusTogether(): void
    {
        $ok = (new ShopRepository())->approve(93001, 7);

        $this->assertTrue($ok);
        $row = $this->row();
        $this->assertSame('active', $row['status'], 'approval IS the shop\'s first go-live');
        $this->assertSame('approved', $row['approval_status']);
        $this->assertSame('7', (string) $row['approved_by']);
        $this->assertNotNull($row['approved_at']);
    }

    public function testApproveInvalidatesTheFacetCache(): void
    {
        (new ShopRepository())->approve(93001, 7);

        $this->assertSame(1, service('facetCache')->invalidations, 'a newly-live shop must refresh delivery-zone counts, same as activate()/deactivate()');
    }

    public function testRejectLeavesStatusInactiveAndRecordsTheReason(): void
    {
        $ok = (new ShopRepository())->reject(93001, 7, 'Address could not be verified.');

        $this->assertTrue($ok);
        $row = $this->row();
        $this->assertSame('inactive', $row['status'], 'a rejected shop was never live and must not become live');
        $this->assertSame('rejected', $row['approval_status']);
        $this->assertSame('Address could not be verified.', $row['rejected_reason']);
    }

    public function testRejectDoesNotInvalidateTheFacetCache(): void
    {
        (new ShopRepository())->reject(93001, 7, 'x');

        $this->assertSame(0, service('facetCache')->invalidations, 'nothing storefront-visible changed — the shop was never live');
    }

    /** Double-approving an already-decided shop must not silently "succeed" a second time. */
    public function testApprovingAnAlreadyDecidedShopFails(): void
    {
        $repo = new ShopRepository();
        $repo->approve(93001, 7);

        $second = $repo->approve(93001, 9);

        $this->assertFalse($second, 'a shop that is no longer pending cannot be approved again');
    }

    public function testRejectingAnAlreadyApprovedShopFails(): void
    {
        $repo = new ShopRepository();
        $repo->approve(93001, 7);

        $this->assertFalse($repo->reject(93001, 9, 'too late'));
        $this->assertSame('active', $this->row()['status'], 'a failed reject must not have touched the now-live shop');
    }
}
