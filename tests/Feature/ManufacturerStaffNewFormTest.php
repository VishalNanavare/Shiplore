<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Manufacturer\StaffController::new() — the "Add Staff" form's unit preselection.
 *
 * Mirrors VendorStaffTest's testNewFormPreselectsTheOnlyShopWhenTheVendorHasExactlyOne:
 * the identical bug pattern exists here (this controller's own docblock cross-
 * references Vendor\StaffController as "the counterpart this mirrors"). A
 * single-unit manufacturer has no real assignment decision to make, but the
 * "Assign" checkbox (manufacturer/staff/form.php:93, name="mshop_ids[]") rendered
 * unchecked by default — 'assigned' was unconditionally []. validated() then fails
 * "Assign the staff member to at least one of your units." on submit, with nothing
 * in the form marking that checkbox as required.
 */
final class ManufacturerStaffNewFormTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'manufacturer.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array { return [['permissions' => ['mfg.staff.manage'], 'scope_type' => 'manufacturer', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('manufacturerAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Maker']; }
            public function findStaffManufacturer(int $u): ?array { return null; }
            public function mshopIdsForManufacturer(int $m): array { return [1]; }
            public function mshopIdsForStaff(int $ms): array { return [1]; }
        });
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 5, 'user_name' => 'Manufacturer', 'principal_type' => 'manufacturer'];
    }

    public function testNewFormPreselectsTheOnlyUnitWhenTheManufacturerHasExactlyOne(): void
    {
        Services::injectMock('manufacturerUnitRepository', new class {
            public function list(int $m): array { return [['id' => 77, 'name' => 'Main Unit']]; }
        });

        $r = $this->withSession($this->sess())->get('manufacturer/staff/new');

        $r->assertStatus(200);
        $this->assertMatchesRegularExpression(
            '/name="mshop_ids\[\]"\s+id="unit-77"\s+value="77"\s+checked/',
            (string) $r->getBody(),
            'the only unit a single-unit manufacturer could possibly assign to should be preselected',
        );
    }

    /** A manufacturer with more than one unit keeps the existing behaviour: no default pick. */
    public function testNewFormLeavesUnitsUnselectedWhenTheManufacturerHasMoreThanOne(): void
    {
        Services::injectMock('manufacturerUnitRepository', new class {
            public function list(int $m): array { return [['id' => 1, 'name' => 'Unit A'], ['id' => 2, 'name' => 'Unit B']]; }
        });

        $r = $this->withSession($this->sess())->get('manufacturer/staff/new');

        $r->assertStatus(200);
        $this->assertDoesNotMatchRegularExpression('/id="unit-1"\s+value="1"\s+checked/', (string) $r->getBody());
        $this->assertDoesNotMatchRegularExpression('/id="unit-2"\s+value="2"\s+checked/', (string) $r->getBody());
    }
}
