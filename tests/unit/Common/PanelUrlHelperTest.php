<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * panel_url() (app/Helpers/panel_helper.php) is what closes the cross-panel-link half
 * of the subdomain isolation fix — see PanelSubdomainIsolationTest for the routing
 * half. Restricting each panel's route group to its own subdomain (Audit: operator
 * reported https://shiplore.test/monline/browse serving the same page as
 * https://monline.shiplore.test/browse) means a plain site_url('vendor/dashboard')
 * called while serving manufacturer.shiplore.test now points at a path that never
 * resolves on that host. panel_url() builds the link on the TARGET panel's own host
 * instead, exactly mirroring the pattern Admin\PortalController::portalUrl() already
 * used for the admin impersonation feature — this is the general-purpose version of
 * the same algorithm, used by 7 sites the audit found and confirmed would otherwise
 * break: vendor/dashboard.php, partials/_topbar.php (x2), partials/_impersonation_banner.php,
 * monline/_layout.php (x2), and WebAuthFilter's principal-mismatch redirect.
 *
 * This is a real behavioral test, not a source assertion: it spoofs
 * $_SERVER['HTTP_HOST'] and calls the actual function, the same technique proven in
 * PanelSubdomainIsolationTest.
 */
final class PanelUrlHelperTest extends CIUnitTestCase
{
    private ?string $origHost   = null;
    private ?string $origHttps  = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->origHost  = $_SERVER['HTTP_HOST'] ?? null;
        $this->origHttps = $_SERVER['HTTPS'] ?? null;
        require_once APPPATH . 'Helpers/panel_helper.php';
    }

    protected function tearDown(): void
    {
        if ($this->origHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $this->origHost;
        }
        if ($this->origHttps === null) {
            unset($_SERVER['HTTPS']);
        } else {
            $_SERVER['HTTPS'] = $this->origHttps;
        }
        Services::reset();
        parent::tearDown();
    }

    private function urlFor(string $host, string $sub, string $path, bool $secure = true): string
    {
        $_SERVER['HTTP_HOST'] = $host;
        if ($secure) {
            $_SERVER['HTTPS'] = 'on';
        } else {
            unset($_SERVER['HTTPS']);
        }
        Services::reset();

        return panel_url($sub, $path);
    }

    /** The exact case that broke: a vendor-panel page linking into monline. */
    public function testBuildsAnAbsoluteUrlOnTheTargetSubdomain(): void
    {
        $this->assertSame(
            'https://monline.shiplore.test/monline/browse',
            $this->urlFor('vendor.shiplore.test', 'monline', 'monline/browse'),
        );
    }

    /** shop. is a sibling of vendor. — the vendor group resolves on both. */
    public function testWorksFromASiblingSubdomainToo(): void
    {
        $this->assertSame(
            'https://monline.shiplore.test/monline/orders',
            $this->urlFor('shop.shiplore.test', 'monline', 'monline/orders'),
        );
    }

    /** The reverse direction the audit also found: monline linking back to vendor. */
    public function testWorksInTheOppositeDirection(): void
    {
        $this->assertSame(
            'https://vendor.shiplore.test/vendor/dashboard',
            $this->urlFor('monline.shiplore.test', 'vendor', 'vendor/dashboard'),
        );
    }

    /** Same-panel case must still resolve to the current host — no behavior change. */
    public function testSamePanelTargetStaysOnTheCurrentHost(): void
    {
        $this->assertSame(
            'https://admin.shiplore.test/admin/dashboard',
            $this->urlFor('admin.shiplore.test', 'admin', 'admin/dashboard'),
        );
    }

    /** http (not https) requests must not be upgraded to https in the built URL. */
    public function testSchemeFollowsTheCurrentRequestNotHardcodedHttps(): void
    {
        $this->assertSame(
            'http://rider.shiplore.test/rider/dashboard',
            $this->urlFor('admin.shiplore.test', 'rider', 'rider/dashboard', secure: false),
        );
    }

    /**
     * Defence in depth: a target host must be in Config\App::$allowedHostnames before
     * panel_url() will emit it, so a spoofed/unexpected Host header cannot make this
     * helper construct a link to an arbitrary attacker-chosen domain.
     */
    public function testFallsBackToARelativeUrlWhenTheTargetIsNotAllowlisted(): void
    {
        // 'evil' is not, and never will be, in Config\App::$allowedHostnames.
        $url = $this->urlFor('admin.shiplore.test', 'evil', 'evil/path');

        $this->assertStringNotContainsString('evil.shiplore.test', $url);
        $this->assertStringContainsString('/evil/path', $url, 'must still resolve to a URL on the CURRENT (trusted) host, not fail outright');
    }

    /**
     * Local dev hosts (shiplorelocal.in and friends) are not in allowedHostnames, so
     * the helper must degrade to a same-host relative URL rather than emitting a
     * broken absolute one — this is the same fallback PortalController::portalUrl()
     * already relies on for local testing.
     */
    public function testFallsBackGracefullyOnAnUnlistedLocalDevHost(): void
    {
        $url = $this->urlFor('vendor.shiplorelocal.in', 'monline', 'monline/browse');

        $this->assertStringContainsString('/monline/browse', $url);
        $this->assertStringNotContainsString('monline.shiplorelocal.in', $url);
    }

    /** A bare two-label host (the apex itself) has no subdomain to strip — must not crash or mis-target. */
    public function testApexHostAsCurrentHostDoesNotCrash(): void
    {
        $url = $this->urlFor('shiplore.test', 'monline', 'monline/browse');

        $this->assertStringContainsString('/monline/browse', $url);
    }
}
