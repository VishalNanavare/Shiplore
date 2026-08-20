<?php

declare(strict_types=1);

use App\Models\VendorRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * VendorRepository::update() actually persists pan/state_code/commission_plan_id/
 * payout_cycle/is_composition, and payout_cycle is whitelisted the same way
 * updateStatus() whitelists vendors.status — payout_cycle is a NOT NULL ENUM
 * (database/sql/01_master.sql:177) and an illegal value would silently truncate to
 * '' under MySQL's non-strict mode, exactly the bug class that made 'rejected' go
 * unnoticed for vendors.status before LEGAL_STATUSES existed.
 */
final class VendorRepositoryUpdateFieldsTest extends CIUnitTestCase
{
    use MinimalSchema;

    private int $vendorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureVendorsTable();
        $this->ensureUsersTable(); // find() left-joins users for the owner contact
        $db = Database::connect();
        $db->table('vendors')->insert([
            'legal_name' => 'Old Legal', 'display_name' => 'Old Display', 'business_type_id' => 1,
        ]);
        $this->vendorId = (int) $db->insertID();
    }

    protected function tearDown(): void
    {
        Database::connect()->table('vendors')->where('id', $this->vendorId)->delete();
        // ensureUsersTable() creates db_users for real in the process-wide :memory: DB —
        // left behind, it flips every other file's unmocked fail-open re-check
        // (WebAuthFilter -> apiAuthRepository->isActive()) to fail-closed. Must drop.
        $this->dropUsersTable();
        parent::tearDown();
    }

    public function testUpdateWritesAllFiveNewColumns(): void
    {
        $ok = (new VendorRepository())->update($this->vendorId, [
            'legal_name' => 'New Legal', 'display_name' => 'New Display', 'business_type_id' => 2,
            'pan' => 'ABCDE1234F', 'state_code' => 'MH', 'commission_plan_id' => 9,
            'payout_cycle' => 'biweekly', 'is_composition' => 1,
        ], 7);

        $this->assertTrue($ok);
        $row = Database::connect()->table('vendors')->where('id', $this->vendorId)->get()->getRowArray();
        $this->assertSame('ABCDE1234F', $row['pan']);
        $this->assertSame('MH', $row['state_code']);
        $this->assertSame(9, (int) $row['commission_plan_id']);
        $this->assertSame('biweekly', $row['payout_cycle']);
        $this->assertSame(1, (int) $row['is_composition']);
    }

    public function testAnIllegalPayoutCycleIsRefusedRatherThanWritten(): void
    {
        $ok = (new VendorRepository())->update($this->vendorId, [
            'legal_name' => 'New Legal', 'display_name' => 'New Display', 'business_type_id' => 2,
            'payout_cycle' => 'yearly',
        ], 7);

        $this->assertFalse($ok);
        $row = Database::connect()->table('vendors')->where('id', $this->vendorId)->get()->getRowArray();
        $this->assertSame('Old Legal', $row['legal_name'], 'nothing must be written when payout_cycle is illegal');
    }

    public function testBlankPanStateAndCommissionPlanAreStoredAsNull(): void
    {
        (new VendorRepository())->update($this->vendorId, [
            'legal_name' => 'New Legal', 'display_name' => 'New Display', 'business_type_id' => 2,
            'pan' => '', 'state_code' => '', 'commission_plan_id' => 0,
        ], 7);

        $row = Database::connect()->table('vendors')->where('id', $this->vendorId)->get()->getRowArray();
        $this->assertNull($row['pan']);
        $this->assertNull($row['state_code']);
        $this->assertNull($row['commission_plan_id']);
        $this->assertSame(0, (int) $row['is_composition']);
    }

    public function testPayoutCycleDefaultsToWeeklyWhenOmitted(): void
    {
        // Pre-seed something other than 'weekly' so the assertion actually exercises
        // update()'s own default rather than coinciding with the column's DB default.
        Database::connect()->table('vendors')->where('id', $this->vendorId)->update(['payout_cycle' => 'monthly']);

        (new VendorRepository())->update($this->vendorId, [
            'legal_name' => 'New Legal', 'display_name' => 'New Display', 'business_type_id' => 2,
        ], 7);

        $row = Database::connect()->table('vendors')->where('id', $this->vendorId)->get()->getRowArray();
        $this->assertSame('weekly', $row['payout_cycle']);
    }

    public function testFindSelectsTheNewProfileColumns(): void
    {
        Database::connect()->table('vendors')->where('id', $this->vendorId)->update([
            'pan' => 'ABCDE1234F', 'state_code' => 'MH', 'commission_plan_id' => 9,
            'payout_cycle' => 'biweekly', 'is_composition' => 1,
        ]);

        $row = (new VendorRepository())->find($this->vendorId);

        $this->assertSame('ABCDE1234F', $row['pan']);
        $this->assertSame('MH', $row['state_code']);
        $this->assertSame(9, (int) $row['commission_plan_id']);
        $this->assertSame('biweekly', $row['payout_cycle']);
        $this->assertSame(1, (int) $row['is_composition']);
    }
}
