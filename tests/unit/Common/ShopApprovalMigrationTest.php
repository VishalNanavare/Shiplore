<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * database/sql/85_shop_approval.sql — the shop-approval gate's schema-only phase.
 *
 * Two properties asserted against the migration's own text, the same reasoning as
 * ManufacturerPermissionScopeTest for its own files: the test environment has no
 * MySQL, and these are properties of what the migration WRITES, not of runtime
 * behaviour.
 */
final class ShopApprovalMigrationTest extends CIUnitTestCase
{
    private function sql(): string
    {
        return (string) file_get_contents(ROOTPATH . 'database/sql/85_shop_approval.sql');
    }

    /**
     * shop.approve/shop.reject must be scope_class 'platform', never 'vendor'/'shop' —
     * 11_seed.sql bulk-grants scope_class IN ('vendor','shop') to every vendor_owner,
     * so a mis-scoped permission here would let a vendor approve their OWN shop, which
     * defeats the entire point of an ADMIN approval gate.
     */
    public function testShopApprovePermissionsArePlatformScoped(): void
    {
        $sql = $this->sql();

        preg_match_all(
            "/\(\s*'(shop\.(?:approve|reject))'\s*,\s*'[^']*'\s*,\s*'[^']*'\s*,\s*'([a-z]+)'\s*,/i",
            $sql,
            $m,
            PREG_SET_ORDER,
        );

        $this->assertNotEmpty($m, 'the permission-row regex matched nothing — has the seed format changed?');
        foreach ($m as [$whole, $code, $scope]) {
            $this->assertSame('platform', $scope, "{$code} must be scope_class 'platform', not '{$scope}' — a vendor/shop scope would let a vendor approve their own shop");
        }
    }

    /** run_all.sql must actually source this file, or a database rebuilt from it lacks the gate entirely. */
    public function testIsSourcedByRunAll(): void
    {
        $runAll = (string) file_get_contents(ROOTPATH . 'database/sql/run_all.sql');

        $this->assertStringContainsString('SOURCE 85_shop_approval.sql;', $runAll);
    }

    /** Every existing shop must be grandfathered as 'not_required', not swept into 'pending'. */
    public function testExistingShopsAreGrandfatheredNotRequired(): void
    {
        $this->assertStringContainsString("DEFAULT 'not_required'", $this->sql());
        $this->assertStringNotContainsString('UPDATE `shops`', $this->sql(), 'no backfill UPDATE should be needed — the column default already grandfathers existing rows');
    }
}
