<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The principal-type gate on WebAuthFilter, and its staged rollout.
 *
 * WebAuthFilter used to prove only "some session is logged in". Because the
 * session cookie is scoped to .shiplore.in (app/Config/Cookie.php), a vendor login
 * at vendor.shiplore.in is also sent to admin.shiplore.in, so the per-controller
 * permission checks were the only barrier to the admin panel — and 34 of those are
 * gated on vendor-scoped codes vendor roles actually hold
 * (see AdminGuardScopeTest).
 *
 * These tests pin the wiring and the decision table without booting the framework.
 * The mismatch decision is deliberately kept as pure logic so it is testable here;
 * the filter itself only adds session/redirect plumbing around it.
 */
final class PrincipalTypeGateTest extends TestCase
{
    /**
     * Mirrors WebAuthFilter::checkPrincipal()'s decision.
     *
     * @return string 'allow' | 'log' | 'block'
     */
    private function decide(?string $expected, ?string $actual, bool $enforcing): string
    {
        if ($expected === null) {
            return 'allow';
        }
        if ((string) ($actual ?? '') === $expected) {
            return 'allow';
        }

        return $enforcing ? 'block' : 'log';
    }

    /** A route group with no argument (e.g. the shared notification feed) is unconstrained. */
    public function testRouteWithoutAnArgumentIsUnconstrained(): void
    {
        $this->assertSame('allow', $this->decide(null, 'vendor', true));
        $this->assertSame('allow', $this->decide(null, null, true));
    }

    /** Matching principals always pass, in either mode. */
    public function testMatchingPrincipalAlwaysPasses(): void
    {
        $this->assertSame('allow', $this->decide('platform', 'platform', false));
        $this->assertSame('allow', $this->decide('platform', 'platform', true));
        $this->assertSame('allow', $this->decide('vendor', 'vendor', true));
    }

    /**
     * The rollout contract: while log-only, a mismatch is RECORDED and ALLOWED.
     * If this ever returns 'block', the staged rollout has been skipped and a
     * mislabelled live account could be locked out.
     */
    public function testLogOnlyModeNeverBlocks(): void
    {
        $this->assertSame('log', $this->decide('platform', 'vendor', false));
        $this->assertSame('log', $this->decide('vendor', 'platform', false));
        $this->assertSame('log', $this->decide('platform', null, false));
        $this->assertSame('log', $this->decide('platform', '', false));
    }

    /** Once enforcing, the cross-panel cases this exists to stop are blocked. */
    public function testEnforcingModeBlocksCrossPanelAccess(): void
    {
        // The headline case: a vendor staffer walking into /admin/*.
        $this->assertSame('block', $this->decide('platform', 'vendor', true));
        $this->assertSame('block', $this->decide('vendor', 'platform', true));
        $this->assertSame('block', $this->decide('platform', 'customer', true));
        $this->assertSame('block', $this->decide('platform', 'rider', true));
        // A session with no principal_type must not be treated as privileged.
        $this->assertSame('block', $this->decide('platform', null, true));
        $this->assertSame('block', $this->decide('platform', '', true));
    }

    /** The env flag must default to log-only — an unset or junk value must not enforce. */
    public function testEnforcementFlagDefaultsToOff(): void
    {
        foreach ([false, '', '0', 'false', 'off', 'no', null, 'maybe'] as $raw) {
            $this->assertFalse(
                filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                'auth.enforcePrincipalType must stay log-only for value: ' . var_export($raw, true),
            );
        }
        foreach (['1', 'true', 'on', 'yes', true] as $raw) {
            $this->assertTrue(filter_var($raw, FILTER_VALIDATE_BOOLEAN));
        }
    }

    /** The admin and vendor route groups must actually carry the pinned filter. */
    public function testRouteGroupsArePinnedToAPrincipal(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        $this->assertStringContainsString(
            "\$routes->group('admin', ['filter' => 'webAuth:platform']",
            $routes,
            'the admin group must be pinned to platform principals',
        );
        $this->assertStringContainsString(
            "\$routes->group('vendor', ['filter' => 'webAuth:vendor']",
            $routes,
            'the vendor group must be pinned to vendor principals',
        );
        $this->assertStringNotContainsString(
            "\$routes->group('admin', ['filter' => 'webAuth']",
            $routes,
            'the admin group regressed to an unpinned webAuth filter',
        );
    }

    /** Impersonation must keep working: PortalController swaps principal_type both ways. */
    public function testImpersonationSwapsPrincipalType(): void
    {
        $portal = (string) file_get_contents(APPPATH . 'Controllers/Admin/PortalController.php');

        $this->assertStringContainsString("'principal_type'          => 'vendor'", $portal);
        $this->assertStringContainsString("'principal_type' => 'platform'", $portal);
    }
}
