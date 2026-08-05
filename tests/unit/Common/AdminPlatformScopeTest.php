<?php

declare(strict_types=1);

use App\Libraries\PolicyEngine;
use PHPUnit\Framework\TestCase;

/**
 * Admin pages are platform-wide, so holding the permission code is not sufficient —
 * the actor must also be scoped to the platform.
 *
 * Why this matters: PolicyEngine::can() is a bare in_array over permission codes with
 * no scope test, the admin route group is path-based (so /admin/* resolves on
 * vendor.shiplore.in, where the vendor's cookie is already sent), and
 * database/sql/11_seed.sql grants EVERY vendor/shop-scoped permission to vendor_owner.
 * Around 34 admin guards name codes that are in that grant — settlement.view,
 * product.update, media.view, report.export, order.cancel, transfer.approve — so the
 * code alone let an ordinary vendor owner reach platform-wide pages.
 *
 * The structural fix (anticipated by AdminGuardScopeTest's own comment) is to assert
 * platform scope at the authorization call rather than swap 34 permission codes:
 * PolicyEngine::canPlatform() requires a platform scope AND the code.
 */
final class AdminPlatformScopeTest extends TestCase
{
    /**
     * Admin\PortalController is exempt from the canPlatform() rule because it already
     * spells the same check out longhand: `! $ctx->isPlatform() || ! ...->can(...)`.
     * It was the one guard in the panel that got this right.
     */
    private const LONGHAND_ISPLATFORM = ['PortalController.php'];

    /** @return list<string> admin controller files */
    private function adminControllers(): array
    {
        return array_merge(
            glob(APPPATH . 'Controllers/Admin/*.php') ?: [],
            array_filter([APPPATH . 'Controllers/AdminController.php'], 'is_file'),
        );
    }

    /** The primitive must exist and must require platform scope, not just the code. */
    public function testCanPlatformRequiresBothScopeAndPermission(): void
    {
        $engine = new PolicyEngine();

        $platform = ['permissions' => ['report.export'], 'scopes' => [['type' => 'platform', 'id' => null]]];
        $vendor   = ['permissions' => ['report.export'], 'scopes' => [['type' => 'vendor', 'id' => 7]]];

        $this->assertTrue($engine->canPlatform($platform, 'report.export'), 'platform staff holding the code must pass');
        $this->assertFalse(
            $engine->canPlatform($vendor, 'report.export'),
            'a vendor holding the SAME code must be refused — this is the escalation',
        );
        $this->assertFalse($engine->canPlatform($platform, 'something.else'), 'platform scope alone is not enough');
        $this->assertFalse($engine->canPlatform(['permissions' => ['x']], 'x'), 'no scopes at all must be refused');
    }

    /** can() itself must stay scope-blind — the vendor and rider panels rely on it. */
    public function testPlainCanIsUnchanged(): void
    {
        $engine = new PolicyEngine();
        $vendor = ['permissions' => ['product.update'], 'scopes' => [['type' => 'vendor', 'id' => 7]]];

        $this->assertTrue($engine->can($vendor, 'product.update'));
    }

    /** No admin controller may authorize with the scope-blind can(). */
    public function testEveryAdminControllerAuthorizesWithPlatformScope(): void
    {
        $offenders = [];

        foreach ($this->adminControllers() as $file) {
            if (in_array(basename($file), self::LONGHAND_ISPLATFORM, true)) {
                continue;
            }
            $src = (string) file_get_contents($file);

            // The scope-blind form, anchored to the real call so a comment mentioning
            // can() cannot trip or satisfy this.
            if (preg_match("/->can\(\s*service\('scopeContext'\)->all\(\)/", $src)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "these admin controllers authorize with the scope-blind can(); a vendor_owner holding "
            . 'the same permission code reaches the page: ' . implode(', ', $offenders),
        );
    }

    /** The exempt controller must actually still contain the longhand check. */
    public function testPortalControllerStillChecksIsPlatformLonghand(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Controllers/Admin/PortalController.php');

        $this->assertStringContainsString('$ctx->isPlatform()', $src);
    }

    /** Sanity: the sweep must actually be looking at files, or it passes vacuously. */
    public function testTheSweepCoversTheAdminPanel(): void
    {
        $this->assertGreaterThan(50, count($this->adminControllers()));
    }
}
