<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Manufacturer permissions must never be seeded with scope_class 'vendor' or 'shop'.
 *
 * database/sql/11_seed.sql:234 (re-run at :421) grants vendor_owner every permission
 * matching `scope_class IN ('vendor','shop')` in BULK. A manufacturer permission
 * created with either class is therefore handed silently to EVERY EXISTING VENDOR
 * OWNER — no error, no warning, and nothing in the manufacturer panel would look
 * wrong. 70_manufacturer.sql calls this out in a comment; this asserts it, because a
 * comment does not fail a build.
 *
 * Asserted against the SQL text rather than a live database: the test environment has
 * no MySQL, and this is a property of what the migrations WRITE.
 */
final class ManufacturerPermissionScopeTest extends CIUnitTestCase
{
    /** Migrations that seed manufacturer/monline permissions. */
    private const FILES = [
        '70_manufacturer.sql',
        '71_monline_b2b.sql',
        '73_admin_manufacturer_oversight.sql',
        '74_manufacturer_parity.sql',
    ];

    /** @return list<array{0:string,1:string,2:string}> [file, code, scope_class] */
    private function seededPermissions(string $file): array
    {
        $sql = (string) file_get_contents(ROOTPATH . 'database/sql/' . $file);

        // Rows look like: ('mfg.unit.update','mfg_unit','update','mshop','...'),
        preg_match_all(
            "/\(\s*'([a-z0-9_.]+)'\s*,\s*'[^']*'\s*,\s*'[^']*'\s*,\s*'([a-z]+)'\s*,/i",
            $sql,
            $m,
            PREG_SET_ORDER,
        );

        $out = [];
        foreach ($m as [$whole, $code, $scope]) {
            $out[] = [$file, $code, $scope];
        }

        return $out;
    }

    public function testNoManufacturerPermissionUsesAVendorOrShopScopeClass(): void
    {
        $checked = 0;

        foreach (self::FILES as $file) {
            foreach ($this->seededPermissions($file) as [$f, $code, $scope]) {
                if (! str_starts_with($code, 'mfg.') && ! str_starts_with($code, 'monline.po.oversight')
                    && ! str_starts_with($code, 'manufacturer.')) {
                    continue; // buyer-side monline.* codes are vendor-scoped ON PURPOSE
                }
                $checked++;
                $this->assertNotContains(
                    $scope,
                    ['vendor', 'shop'],
                    "{$f}: permission '{$code}' is scope_class '{$scope}'. 11_seed.sql bulk-grants "
                    . "scope_class IN ('vendor','shop') to vendor_owner, so this would be handed to "
                    . 'every existing vendor owner.',
                );
            }
        }

        $this->assertGreaterThan(20, $checked, 'the permission-row regex matched suspiciously little — has the seed format changed?');
    }

    /**
     * The buyer-side monline codes are the deliberate exception and must STAY
     * vendor/shop-scoped: they are held by vendors buying from manufacturers, not by
     * manufacturers. Asserting it so the rule above is not "fixed" by widening it.
     */
    public function testBuyerSideMonlinePermissionsRemainVendorScoped(): void
    {
        $found = [];
        foreach ($this->seededPermissions('71_monline_b2b.sql') as [$f, $code, $scope]) {
            if (str_starts_with($code, 'monline.') && ! str_contains($code, 'oversight')) {
                $found[$code] = $scope;
            }
        }

        $this->assertNotSame([], $found, 'no buyer-side monline permissions found');
        foreach ($found as $code => $scope) {
            $this->assertContains($scope, ['vendor', 'shop'], "{$code} should stay buyer-scoped");
        }
    }

    /** 74 must be wired into run_all.sql, or a rebuilt database silently lacks it. */
    public function testParityMigrationIsSourcedByRunAll(): void
    {
        $runAll = (string) file_get_contents(ROOTPATH . 'database/sql/run_all.sql');

        $this->assertStringContainsString('SOURCE 74_manufacturer_parity.sql;', $runAll);
    }

    /**
     * Manufacturer staff are `vendor_staff` rows, so the staff types this panel
     * assigns must exist in that shared enum — otherwise every create fails at the
     * database with a truncation error rather than anything the UI can explain.
     */
    public function testStaffTypeEnumCoversTheManufacturerRoles(): void
    {
        $sql = (string) file_get_contents(ROOTPATH . 'database/sql/74_manufacturer_parity.sql');

        foreach (App\Models\ManufacturerStaffRepository::types() as $type) {
            // 'manager' predates this migration and is already in 10_staff.sql's enum.
            if ($type === 'manager') {
                continue;
            }
            $this->assertStringContainsString(
                "'" . $type . "'",
                $sql,
                "ManufacturerStaffRepository assigns staff_type '{$type}', which 74 must add to the vendor_staff enum",
            );
        }
    }
}
