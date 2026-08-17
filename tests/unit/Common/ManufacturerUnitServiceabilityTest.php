<?php

declare(strict_types=1);

use App\Models\ManufacturerUnitRepository;
use CodeIgniter\Test\CIUnitTestCase;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * A unit's delivery settings, written against a real `mshops` table.
 *
 * The rule under test is a partial-update rule, and those are exactly the kind that
 * a mocked repository cannot verify: the manufacturer unit form posts BOTH the
 * address section and (for permitted users) the serviceability section, so the
 * repository has to distinguish "this request did not include delivery settings" from
 * "this request set them to empty". Get that wrong and a unit manager correcting a
 * pincode silently switches off their factory's delivery.
 *
 * The controller decides the `serviceability` flag from the acting user's permission
 * and never accepts it from input; this covers the other half — what the repository
 * does once told.
 */
final class ManufacturerUnitServiceabilityTest extends CIUnitTestCase
{
    use MinimalSchema;

    private ManufacturerUnitRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureMshopsTable();
        $this->schemaConn()->table('mshops')->truncate();
        $this->schemaConn()->query(
            'INSERT INTO db_mshops (id, vendor_id, name, pincode, state_code, delivery_enabled, pickup_enabled,
                delivery_radius_km, prep_time_min, min_order_value, delivery_fee, free_delivery_above, status)
             VALUES (11, 1, ?, ?, ?, 1, 1, 25.0, 60, 5000.0, 150.0, 20000.0, ?)',
            ['Bhiwandi Plant', '421302', 'MH', 'active'],
        );
        $this->repo = new ManufacturerUnitRepository();
    }

    protected function tearDown(): void
    {
        $this->dropMshopsTable();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function unit(): array
    {
        return (array) $this->schemaConn()->table('mshops')->where('id', 11)->get()->getRowArray();
    }

    /**
     * The load-bearing case. An address-only edit — the exact shape a user without
     * mfg.unit.serviceability submits — must leave every delivery column untouched.
     */
    public function testAnEditWithoutTheFlagLeavesDeliverySettingsAlone(): void
    {
        $this->repo->update(11, 1, ['name' => 'Bhiwandi Plant', 'pincode' => '421303'], 9);

        $u = $this->unit();
        $this->assertSame('421303', $u['pincode'], 'the address change must still apply');
        $this->assertSame(1, (int) $u['delivery_enabled'], 'delivery must not be switched off');
        $this->assertSame(25.0, (float) $u['delivery_radius_km']);
        $this->assertSame(60, (int) $u['prep_time_min']);
        $this->assertSame(150.0, (float) $u['delivery_fee']);
    }

    /**
     * ...and the nastier variant: the delivery FIELDS are absent, as they would be for
     * a user whose form never rendered that section, but the request is otherwise a
     * normal edit. Without the flag check those absent fields would be read as empty
     * and written as NULL/0.
     */
    public function testAbsentDeliveryFieldsAreNotTreatedAsCleared(): void
    {
        $this->repo->update(11, 1, ['name' => 'Renamed Plant'], 9);

        $u = $this->unit();
        $this->assertSame('Renamed Plant', $u['name']);
        $this->assertSame(1, (int) $u['delivery_enabled']);
        $this->assertNotNull($u['delivery_radius_km']);
    }

    public function testWithTheFlagDeliverySettingsAreWritten(): void
    {
        $this->repo->update(11, 1, [
            'serviceability'     => true,
            'name'               => 'Bhiwandi Plant',
            'delivery_enabled'   => '1',
            'pickup_enabled'     => '1',
            'delivery_radius_km' => '40.5',
            'prep_time_min'      => '90',
            'min_order_value'    => '7500',
            'delivery_fee'       => '200',
            'free_delivery_above' => '30000',
        ], 9);

        $u = $this->unit();
        $this->assertSame(1, (int) $u['delivery_enabled']);
        $this->assertSame(40.5, (float) $u['delivery_radius_km']);
        $this->assertSame(90, (int) $u['prep_time_min']);
        $this->assertSame(7500.0, (float) $u['min_order_value']);
    }

    /** An unticked checkbox posts nothing at all, which must mean "off", not "unchanged". */
    public function testWithTheFlagAnUntickedSwitchTurnsDeliveryOff(): void
    {
        $this->repo->update(11, 1, [
            'serviceability' => true,
            'name'           => 'Bhiwandi Plant',
            // delivery_enabled deliberately absent — the switch was unticked.
            'pickup_enabled' => '1',
        ], 9);

        $this->assertSame(0, (int) $this->unit()['delivery_enabled']);
    }

    /** Blank numeric fields clear to NULL rather than being coerced to 0. */
    public function testWithTheFlagBlankNumbersBecomeNullNotZero(): void
    {
        $this->repo->update(11, 1, [
            'serviceability'     => true,
            'name'               => 'Bhiwandi Plant',
            'delivery_enabled'   => '1',
            'delivery_radius_km' => '',
            'delivery_fee'       => '',
        ], 9);

        $u = $this->unit();
        $this->assertNull($u['delivery_radius_km'], 'a blank radius means "unset", not "zero km"');
        $this->assertNull($u['delivery_fee']);
    }

    /** Tenant scoping still applies — another manufacturer's unit is not updatable. */
    public function testAnotherManufacturersUnitIsNotUpdatable(): void
    {
        $this->assertFalse($this->repo->update(11, 999, ['serviceability' => true, 'name' => 'Hijacked'], 9));
        $this->assertSame('Bhiwandi Plant', $this->unit()['name']);
    }
}
