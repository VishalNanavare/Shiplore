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
        '76_manufacturer_parity.sql',
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

    /**
     * Every parity migration must be wired into run_all.sql, or a database rebuilt
     * from it silently lacks them — which is exactly the drift 71-73 already suffered.
     */
    public function testParityMigrationsAreSourcedByRunAll(): void
    {
        $runAll = (string) file_get_contents(ROOTPATH . 'database/sql/run_all.sql');

        foreach (['76_manufacturer_parity.sql', '77_manufacturer_delivery.sql'] as $file) {
            $this->assertStringContainsString("SOURCE {$file};", $runAll, "{$file} is not sourced by run_all.sql");
        }
    }

    /**
     * Migrations from 74 onward must contain no CLIENT-only commands.
     *
     * `SOURCE` and `DELIMITER` are commands of the mysql/mariadb command-line client,
     * not SQL — the server has never heard of either. That matters because
     * database/apply_sql.php, the documented way to apply a migration here (dev
     * machines have no mariadb CLI), drives mysqli_multi_query and sends statements
     * straight to the server: it fails on both. phpMyAdmin parses DELIMITER itself but
     * likewise fails on SOURCE, which is what an operator hit importing run_all.sql —
     * "Unrecognized statement type ... near SOURCE".
     *
     * EIGHT EARLIER MIGRATIONS USE DELIMITER (16, 17, 18, 22, 23, 25, 29, 70) and are
     * deliberately not failed here: they have already shipped and been applied, and
     * retroactively breaking the build over them helps nobody. The rule is
     * forward-looking, which is also why the boundary is a number rather than an
     * allow-list — a new file cannot quietly join the legacy set.
     *
     * Idempotence without a stored procedure is the PREPARE-guarded form 70 already
     * uses for users.principal_type; 77 shows it applied to ADD COLUMN.
     *
     * run_all.sql is exempt entirely: it is legitimately a CLI script and says so in
     * its own header.
     */
    public function testNewMigrationsUseNoClientOnlyCommands(): void
    {
        $files = glob(ROOTPATH . 'database/sql/*.sql') ?: [];
        $this->assertNotEmpty($files);

        $checked = 0;
        foreach ($files as $path) {
            $name = basename($path);
            if (! preg_match('/^(\d+)_/', $name, $m) || (int) $m[1] < 74) {
                continue;
            }

            // Strip -- line comments: these files DISCUSS DELIMITER while explaining
            // why they avoid it, and a comment must not fail an assertion about code.
            $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($path)) ?? '';

            $this->assertDoesNotMatchRegularExpression(
                '/^\s*DELIMITER\b/mi',
                $sql,
                "{$name} uses DELIMITER, a client-only command — database/apply_sql.php cannot apply it",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*SOURCE\s+\S+\.sql/mi',
                $sql,
                "{$name} uses SOURCE, a client-only command",
            );

            // A routine body is MULTI-STATEMENT. phpMyAdmin splits a script on ';' and
            // sends each piece separately, so BEGIN…END is cut at its first internal
            // semicolon and the server gets a truncated CREATE — #1064 near ''. Adding
            // DELIMITER does not save it either, since the assertion above bans that for
            // apply_sql.php's sake, and the two constraints together leave no way to
            // ship a routine that both tools can apply.
            //
            // This was added after 81_mfg_warehouses.sql failed on a live import for
            // exactly this reason: the DELIMITER rule above did not imply it, because a
            // CREATE PROCEDURE with no DELIMITER passes that check and breaks harder.
            // Idempotence without a routine is the flat PREPARE-guarded form in 77.
            $this->assertDoesNotMatchRegularExpression(
                '/\bCREATE\s+(OR\s+REPLACE\s+)?(DEFINER\s*=\s*\S+\s+)?(PROCEDURE|FUNCTION|TRIGGER)\b/i',
                $sql,
                "{$name} defines a stored routine — its BEGIN…END body is split on ';' by "
                . 'phpMyAdmin and arrives truncated. Use the flat PREPARE-guarded form (see 77).',
            );
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'no migrations at or above 74 were scanned — has the naming changed?');
    }

    /**
     * Manufacturer staff are `vendor_staff` rows, so the staff types this panel
     * assigns must exist in that shared enum — otherwise every create fails at the
     * database with a truncation error rather than anything the UI can explain.
     */
    public function testStaffTypeEnumCoversTheManufacturerRoles(): void
    {
        $sql = (string) file_get_contents(ROOTPATH . 'database/sql/76_manufacturer_parity.sql');

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
