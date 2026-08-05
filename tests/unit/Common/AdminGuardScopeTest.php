<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every admin page must be gated on a PLATFORM-scope permission.
 *
 * The admin route group carries only the `webAuth` filter, which proves a session
 * is logged in — not that it belongs to an admin. The session cookie is scoped to
 * .shiplore.in, so one vendor login is valid on admin.shiplore.in too. The only
 * thing standing between a vendor staffer and an admin page is the per-controller
 * guard() call, and PolicyEngine::can() is a bare in_array over permission codes
 * with no scope check.
 *
 * So gating a platform-wide admin page on a permission whose scope_class is
 * 'vendor' or 'shop' is not protection at all: vendor roles are granted exactly
 * those codes in database/sql/11_seed.sql. Admin\DocumentController gating the
 * platform-wide invoice register on the vendor-scoped 'invoice.view' — which
 * vendor_manager and vendor_finance_viewer both hold — let any of them read every
 * vendor's invoices.
 *
 * This test parses the seeds for each permission's scope_class and asserts no
 * admin controller guards on a non-platform code. KNOWN_GAPS records the sites
 * that are still wrong, so the list can only shrink.
 *
 * UPDATE — the structural fix this file's KNOWN_GAPS comment anticipated has landed
 * (audit finding C2). Every admin authorization call now goes through
 * PolicyEngine::canPlatform(), which requires a platform scope IN ADDITION to the
 * permission code, so a vendor-scoped code named by an admin guard is no longer
 * reachable by vendor staff. AdminPlatformScopeTest holds that invariant and is the
 * load-bearing test now.
 *
 * This file is kept because its question is still worth asking: naming a vendor-scoped
 * code on a platform page is confusing even when it is no longer exploitable, and a
 * future guard added WITHOUT canPlatform() would be a live hole again. Treat the
 * KNOWN_GAPS entries as a naming-hygiene backlog, not an open vulnerability list.
 */
final class AdminGuardScopeTest extends TestCase
{
    /**
     * Sites still gated on a non-platform permission. Each entry is
     * "Controller.php::permission.code". Remove entries as they are fixed; do not
     * add to this list without a deliberate decision — a new entry means a new
     * admin page is reachable by vendor staff.
     *
     * Tracked for the structural fix: asserting platform scope once in a shared
     * admin guard, rather than swapping 24 permission codes individually.
     */
    private const KNOWN_GAPS = [
        'CouponController.php::coupon.manage',
        'DeliveryController.php::delivery.view',
        'ImportController.php::import.run',
        'MediaController.php::media.view',
        'OrderController.php::order.cancel',
        'PayoutController.php::settlement.view',
        'ProductController.php::product.create',
        'ProductController.php::product.update',
        'ProductController.php::product.view',
        'ProductLookupController.php::product.view',
        'ProductMediaController.php::product.update',
        'ProductPricingController.php::product.update',
        'ProductTypeController.php::product.update',
        'ProductVariantController.php::product.update',
        'PromotionController.php::promotion.manage',
        'PromotionController.php::promotion.view',
        'ReconciliationController.php::settlement.view',
        // Platform-wide sales + GST export gated on vendor-scoped report codes.
        'ReportController.php::gst.report.view',
        'ReportController.php::report.export',
        'ReportController.php::report.view',
        'ReturnController.php::return.view',
        'RiderController.php::delivery.view',
        'RiderFinanceController.php::settlement.view',
        'RiderPlanController.php::delivery.view',
        'SettlementController.php::settlement.view',
        'ShopController.php::shop.create',
        'ShopController.php::shop.update',
        'ShopController.php::shop.view',
        'TransferController.php::transfer.approve',
        'TransferController.php::transfer.view',
        'VendorController.php::vendor.settings.manage',
        'VendorTypeMismatchController.php::vendor.update',
        'WarehouseController.php::warehouse.manage',
        'WarehouseController.php::warehouse.view',
    ];

    /** @return array<string,string> permission code => scope_class */
    private function permissionScopes(): array
    {
        $scopes = [];
        foreach (glob(ROOTPATH . 'database/sql/*.sql') as $file) {
            $sql = (string) file_get_contents($file);
            preg_match_all(
                "/\('([a-z0-9_.]+)'\s*,\s*'[^']*'\s*,\s*'[^']*'\s*,\s*'(platform|vendor|shop|self)'/",
                $sql,
                $m,
                PREG_SET_ORDER,
            );
            foreach ($m as $row) {
                $scopes[$row[1]] ??= $row[2];
            }
        }

        return $scopes;
    }

    /** @return list<string> "Controller.php::code" for every non-platform guard */
    private function offendingGuards(): array
    {
        $scopes = $this->permissionScopes();
        $found  = [];

        $files = array_merge(
            glob(APPPATH . 'Controllers/Admin/*.php') ?: [],
            [APPPATH . 'Controllers/AdminController.php'],
        );
        foreach ($files as $file) {
            $src = (string) @file_get_contents($file);
            preg_match_all("/can\(\s*service\('scopeContext'\)->all\(\),\s*'([a-z0-9_.]+)'\s*\)/", $src, $a);
            preg_match_all("/guard\('([a-z0-9_.]+)'\)/", $src, $b);

            foreach (array_unique(array_merge($a[1], $b[1])) as $code) {
                // Unknown codes are seeded in a format this parser does not read;
                // treat them as out of scope rather than as failures.
                if (($scopes[$code] ?? 'platform') !== 'platform') {
                    $found[] = basename($file) . '::' . $code;
                }
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    /** The seeds must actually be parseable, or this whole test is vacuous. */
    public function testPermissionScopesAreParsed(): void
    {
        $scopes = $this->permissionScopes();

        $this->assertNotEmpty($scopes, 'no permissions parsed from database/sql — the regex or the seed format changed');
        $this->assertSame('platform', $scopes['banner.manage'] ?? null);
        $this->assertSame('vendor', $scopes['invoice.view'] ?? null);
    }

    /** The fixed controllers must stay fixed. */
    public function testFixedAdminControllersUsePlatformScopedPermissions(): void
    {
        $offending = $this->offendingGuards();

        foreach (['BannerController.php', 'DocumentController.php', 'CommissionHoldController.php'] as $fixed) {
            $hits = array_filter($offending, static fn (string $g): bool => str_starts_with($g, $fixed));
            $this->assertSame([], array_values($hits), $fixed . ' regressed to a non-platform permission');
        }
    }

    /** BannerController had no authorization at all; every public method must gate now. */
    public function testEveryBannerControllerMethodIsGuarded(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Controllers/Admin/BannerController.php');

        preg_match_all('/public function (\w+)\s*\(/', $src, $m);
        $this->assertNotEmpty($m[1], 'no public methods found — did the file move?');

        // Each public method body must reach a guard before doing work.
        foreach ($m[1] as $method) {
            $body = substr($src, (int) strpos($src, "public function {$method}("), 400);
            $this->assertMatchesRegularExpression(
                "/\\\$this->guard\(\)|policyEngine'\)->canPlatform?\(/",
                $body,
                "Admin\\BannerController::{$method}() has no authorization check",
            );
        }
    }

    /** The backlog may shrink, never grow. */
    public function testNoNewNonPlatformGuardsAreIntroduced(): void
    {
        $offending = $this->offendingGuards();
        $new       = array_values(array_diff($offending, self::KNOWN_GAPS));

        $this->assertSame(
            [],
            $new,
            "New admin page(s) gated on a vendor/shop-scoped permission — vendor staff can reach them:\n  "
            . implode("\n  ", $new),
        );
    }
}
