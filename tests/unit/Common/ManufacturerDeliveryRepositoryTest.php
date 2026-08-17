<?php

declare(strict_types=1);

use App\Models\ManufacturerDeliveryRepository;
use CodeIgniter\Test\CIUnitTestCase;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Purchase-order deliveries and rider ownership, against real tables.
 *
 * Two rules here are the kind a mocked repository cannot verify, because they are
 * decisions the repository itself makes rather than pass-throughs:
 *
 *   1. a rider may only be assigned if they belong to THIS manufacturer's fleet —
 *      delivery_boys is shared with vendors, so `vendor_id` is the whole boundary;
 *   2. a delivery may only move to a state its current state can reach, so a
 *      delivered order cannot be walked backwards by re-posting.
 *
 * Both were mutation-survivors when only the controller was covered.
 */
final class ManufacturerDeliveryRepositoryTest extends CIUnitTestCase
{
    use MinimalSchema;

    private const MFG   = 1;
    private const OTHER = 2;

    private ManufacturerDeliveryRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureMfgDeliveryTables();
        // list() and riders() LEFT JOIN users (for the rider's name) and vendors (for
        // the buyer's), so both have to exist even though nothing here asserts on them.
        $this->ensureUsersTable();
        $this->ensureVendorsTable();
        $this->ensureMshopsTable();

        $db = $this->schemaConn();
        foreach (['mfg_deliveries', 'mfg_purchase_orders', 'delivery_boys'] as $t) {
            $db->table($t)->truncate();
        }

        // One PO sold by manufacturer 1 out of unit 11.
        $db->query(
            'INSERT INTO db_mfg_purchase_orders (id, po_no, buyer_vendor_id, seller_vendor_id, seller_mshop_id, status)
             VALUES (90, ?, 2, ?, 11, ?)',
            ['PO-2026-0090', self::MFG, 'dispatched'],
        );
        // One rider each: ours (user 700) and somebody else's (user 800).
        $db->query('INSERT INTO db_delivery_boys (id, user_id, vendor_id, vehicle_type, status) VALUES (1, 700, ?, ?, ?)', [self::MFG, 'van', 'active']);
        $db->query('INSERT INTO db_delivery_boys (id, user_id, vendor_id, vehicle_type, status) VALUES (2, 800, ?, ?, ?)', [self::OTHER, 'bike', 'active']);

        $this->repo = new ManufacturerDeliveryRepository();
    }

    protected function tearDown(): void
    {
        $this->dropMfgDeliveryTables();
        // db_users especially: leaving it behind flips other files' unmocked
        // apiAuthRepository->isActive() check from fail-open to fail-closed, because
        // the SQLite :memory: connection is shared by the whole PHPUnit process.
        $this->dropUsersTable();
        $this->schemaConn()->query('DROP TABLE IF EXISTS db_vendors');
        $this->dropMshopsTable();
        parent::tearDown();
    }

    private function openDelivery(): int
    {
        return (int) $this->repo->ensureForPo(90, 11, 1);
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        return (array) $this->schemaConn()->table('mfg_deliveries')->where('id', $id)->get()->getRowArray();
    }

    public function testEnsureForPoOpensExactlyOneRecord(): void
    {
        $first  = $this->openDelivery();
        $second = $this->repo->ensureForPo(90, 11, 1);

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, $second, 're-dispatching must not open a second delivery');
        $this->assertSame(1, $this->schemaConn()->table('mfg_deliveries')->countAllResults());
    }

    public function testAssigningOwnRiderWorks(): void
    {
        $id  = $this->openDelivery();
        $res = $this->repo->assignRider($id, self::MFG, 700, 1);

        $this->assertTrue($res['ok'], $res['error']);
        $row = $this->row($id);
        $this->assertSame(700, (int) $row['rider_user_id']);
        $this->assertSame('assigned', $row['status']);
    }

    /** The boundary: delivery_boys is shared with vendors, so vendor_id is everything. */
    public function testAnotherBusinessesRiderCannotBeAssigned(): void
    {
        $id  = $this->openDelivery();
        $res = $this->repo->assignRider($id, self::MFG, 800, 1);

        $this->assertFalse($res['ok']);
        $this->assertNull($this->row($id)['rider_user_id'], 'a foreign rider must never be written');
    }

    public function testAnotherManufacturersDeliveryIsNotAssignable(): void
    {
        $id  = $this->openDelivery();
        $res = $this->repo->assignRider($id, self::OTHER, 800, 1);

        $this->assertFalse($res['ok']);
        $this->assertNull($this->row($id)['rider_user_id']);
    }

    public function testTheHappyPathWalksForward(): void
    {
        $id = $this->openDelivery();
        $this->repo->assignRider($id, self::MFG, 700, 1);

        foreach (['picked_up', 'in_transit', 'delivered'] as $to) {
            $res = $this->repo->transition($id, self::MFG, $to, null, 1);
            $this->assertTrue($res['ok'], $to . ': ' . $res['error']);
        }

        $row = $this->row($id);
        $this->assertSame('delivered', $row['status']);
        $this->assertNotNull($row['delivered_at']);
    }

    /** A delivered order must not be walked backwards by re-posting an earlier state. */
    public function testADeliveredOrderCannotGoBackwards(): void
    {
        $id = $this->openDelivery();
        $this->repo->assignRider($id, self::MFG, 700, 1);
        $this->repo->transition($id, self::MFG, 'picked_up', null, 1);
        $this->repo->transition($id, self::MFG, 'delivered', null, 1);

        $res = $this->repo->transition($id, self::MFG, 'picked_up', null, 1);

        $this->assertFalse($res['ok']);
        $this->assertSame('delivered', $this->row($id)['status']);
    }

    /** ...and an unassigned delivery cannot jump straight to delivered. */
    public function testAPendingDeliveryCannotSkipStraightToDelivered(): void
    {
        $id  = $this->openDelivery();
        $res = $this->repo->transition($id, self::MFG, 'delivered', null, 1);

        $this->assertFalse($res['ok']);
        $this->assertSame('pending', $this->row($id)['status']);
    }

    public function testMarkingFailedRequiresAReason(): void
    {
        $id = $this->openDelivery();
        $this->repo->assignRider($id, self::MFG, 700, 1);

        $this->assertFalse($this->repo->transition($id, self::MFG, 'failed', '', 1)['ok']);
        $this->assertSame('assigned', $this->row($id)['status']);

        $this->assertTrue($this->repo->transition($id, self::MFG, 'failed', 'Buyer gate locked', 1)['ok']);
        $this->assertSame('Buyer gate locked', $this->row($id)['failure_reason']);
    }

    public function testAnUnknownTargetStateIsRefused(): void
    {
        $id = $this->openDelivery();

        $this->assertFalse($this->repo->transition($id, self::MFG, 'teleported', null, 1)['ok']);
    }

    public function testListIsScopedToTheSellingManufacturer(): void
    {
        $this->openDelivery();

        $this->assertCount(1, $this->repo->list(self::MFG, [11]));
        $this->assertSame([], $this->repo->list(self::OTHER, [11]), 'another manufacturer must not see this PO');
    }

    public function testRidersAreScopedToTheirOwnBusiness(): void
    {
        $mine = $this->repo->riders(self::MFG);

        $this->assertCount(1, $mine);
        $this->assertSame(700, (int) $mine[0]['user_id']);
    }

    /**
     * PurchaseOrderRepository must open the delivery on dispatch. transition() needs
     * MariaDB (FOR UPDATE), so the wiring is asserted from source — with comments
     * stripped, and anchored on the CALL rather than on a condition that also appears
     * elsewhere in that method.
     */
    public function testDispatchOpensADeliveryRecord(): void
    {
        $src = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . 'Models/PurchaseOrderRepository.php')) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $src .= $t[1];
            } else {
                $src .= $t;
            }
        }

        $this->assertStringContainsString("service('manufacturerDeliveryRepository')->ensureForPo(", $src);
    }
}
