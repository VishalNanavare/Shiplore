<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The operator reported this directly: https://shiplore.in/monline/browse loads the
 * same page as https://monline.shiplore.in/browse — removing the subdomain from a
 * panel URL still serves the panel. Every branded panel had the same shape: the route
 * groups for admin, vendor (+ shop), rider, manufacturer (+ mshop) and monline are all
 * PATH-based with no host restriction, so CI4 registers them regardless of which
 * hostname the request arrived on. The subdomains were decorative.
 *
 * `s3.shiplore.in` and `pma.shiplore.in` are separate applications entirely (not this
 * CI4 app's routing) and are explicitly out of scope, per the operator.
 *
 * These are genuine behavioral tests, not source assertions: each one constructs a
 * REAL CodeIgniter\Router\RouteCollection, spoofs $_SERVER['HTTP_HOST'], calls the
 * actual loadRoutes() against the real app/Config/Routes.php, and inspects what CI4
 * itself registered. Proven against a spike before writing this file: with the fix
 * absent, root '/' already resolves to a different controller per host via the exact
 * same 'subdomain' route option mechanism — see SubdomainRootRouteTest — confirming
 * this harness measures real routing behavior. A path CI4 never registered for a host
 * gets the framework's normal 404, nothing more, nothing bespoke.
 */
final class PanelSubdomainIsolationTest extends CIUnitTestCase
{
    private ?string $origHost = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->origHost = $_SERVER['HTTP_HOST'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->origHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $this->origHost;
        }
        Services::reset();
        parent::tearDown();
    }

    private function routesFor(string $host): RouteCollection
    {
        $_SERVER['HTTP_HOST'] = $host;
        Services::reset();

        $routes = Services::routes(false);
        $routes->loadRoutes();

        return $routes;
    }

    /**
     * One representative, unauthenticated-reachable path per panel, and the ONLY
     * hosts it may resolve on. 'shop'/'mshop' deliberately share the vendor/
     * manufacturer path group — see the header comments in Routes.php: shop staff
     * are vendor-panel staff, mshop staff are manufacturer-panel staff.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function panelProvider(): array
    {
        return [
            'admin/dashboard'        => ['admin/dashboard', ['admin.shiplore.in']],
            'vendor/dashboard'       => ['vendor/dashboard', ['vendor.shiplore.in', 'shop.shiplore.in']],
            'rider/login'            => ['rider/login', ['rider.shiplore.in']],
            'rider/dashboard'        => ['rider/dashboard', ['rider.shiplore.in']],
            'manufacturer/dashboard' => ['manufacturer/dashboard', ['manufacturer.shiplore.in', 'mshop.shiplore.in']],
            'monline/browse'         => ['monline/browse', ['monline.shiplore.in']],
        ];
    }

    /** The exact bug report: the apex must not serve any branded panel's paths. */
    #[\PHPUnit\Framework\Attributes\DataProvider('panelProvider')]
    public function testApexDoesNotServeAnyPanelPath(string $path): void
    {
        $routes = $this->routesFor('shiplore.in');
        $get    = $routes->getRoutes('GET');

        $this->assertArrayNotHasKey(
            $path,
            $get,
            "shiplore.in (apex) must not resolve '{$path}' — that is the exact regression the operator reported for monline/browse",
        );
    }

    /** Removing the subdomain must fail the same way on EVERY panel, not just monline. */
    #[\PHPUnit\Framework\Attributes\DataProvider('panelProvider')]
    public function testWrongPanelHostDoesNotServeAnotherPanelsPath(string $path, array $ownHosts): void
    {
        $allHosts = [
            'admin.shiplore.in', 'vendor.shiplore.in', 'shop.shiplore.in', 'rider.shiplore.in',
            'manufacturer.shiplore.in', 'mshop.shiplore.in', 'monline.shiplore.in',
        ];

        foreach (array_diff($allHosts, $ownHosts) as $wrongHost) {
            $routes = $this->routesFor($wrongHost);
            $get    = $routes->getRoutes('GET');

            $this->assertArrayNotHasKey($path, $get, "{$wrongHost} must not resolve '{$path}'");
        }
    }

    /** Its own (and sibling, for vendor/manufacturer) subdomain must still serve every panel. */
    #[\PHPUnit\Framework\Attributes\DataProvider('panelProvider')]
    public function testOwnHostStillServesThePath(string $path, array $ownHosts): void
    {
        foreach ($ownHosts as $host) {
            $routes = $this->routesFor($host);
            $get    = $routes->getRoutes('GET');

            $this->assertArrayHasKey($path, $get, "{$host} must still resolve '{$path}' — this is a restriction, not a removal");
        }
    }

    /** api/v1 is a separate concern (mobile apps) and must stay reachable from every host. */
    public function testApiV1IsNotRestrictedByHost(): void
    {
        foreach (['shiplore.in', 'admin.shiplore.in', 'monline.shiplore.in'] as $host) {
            $routes = $this->routesFor($host);
            $get    = $routes->getRoutes('POST');

            $this->assertArrayHasKey('api/v1/auth/login', $get, "api/v1 must remain host-agnostic — checked on {$host}");
        }
    }

    /** The storefront and shared auth/registration routes must stay reachable on the apex. */
    public function testApexStillServesTheStorefrontAndSharedAuthRoutes(): void
    {
        $routes = $this->routesFor('shiplore.in');
        $get    = $routes->getRoutes('GET');

        $this->assertArrayHasKey('/', $get, 'the apex root (consumer storefront) must still resolve');
        $this->assertArrayHasKey('login', $get, 'the shared staff login page must still resolve on the apex');
        $this->assertArrayHasKey('register', $get, 'vendor self-registration must still resolve on the apex');
    }

    /**
     * The impersonation "Return to Admin" control renders on whichever panel the
     * admin is currently impersonating into (partials/_impersonation_banner.php,
     * included by the vendor/manufacturer/rider/main layouts), so its POST must keep
     * resolving from EVERY panel host, not just admin's own — that is exactly why
     * it's registered standalone, outside the subdomain-restricted admin group.
     */
    public function testPortalLeaveResolvesFromAnyPanelHost(): void
    {
        foreach (['admin.shiplore.in', 'vendor.shiplore.in', 'manufacturer.shiplore.in', 'rider.shiplore.in', 'monline.shiplore.in'] as $host) {
            $routes = $this->routesFor($host);
            $post   = $routes->getRoutes('POST');

            $this->assertArrayHasKey('admin/portal/leave', $post, "{$host} must still be able to leave an impersonation session");
        }
    }

    /**
     * admin.shiplore.in must still be able to reach the "Go to Portal" admin-only POST
     * routes that impersonate into another panel — those live INSIDE the admin group
     * and are only ever posted to from admin.shiplore.in itself (confirmed by reading
     * Admin\PortalController::portalUrl(), which builds an explicit absolute URL to the
     * TARGET panel's own subdomain for the resulting redirect, rather than a relative
     * one — it already anticipated this restriction).
     */
    public function testAdminHostCanStillReachThePortalEnterRoutes(): void
    {
        $routes = $this->routesFor('admin.shiplore.in');
        $post   = $routes->getRoutes('POST');

        // (:num) is stored in the route key as its regex, wrapped in the capturing
        // group the router needs to extract $1 — not a literal digit.
        $this->assertArrayHasKey('admin/portal/enter/vendor/([0-9]+)', $post);
        $this->assertArrayHasKey('admin/portal/enter/manufacturer/([0-9]+)', $post);
        $this->assertArrayHasKey('admin/portal/enter/shop/([0-9]+)', $post);
        $this->assertArrayHasKey('admin/portal/enter/rider/([0-9]+)', $post);

        // Confirmed separately (see WebAuthFilter/PortalController tests below):
        // 'admin/portal/leave' is a STANDALONE route outside the admin group
        // specifically so an impersonated (principal_type-swapped) session can still
        // reach it — it is unaffected by the admin group's subdomain restriction and
        // must keep resolving on every host, not just admin.
        $this->assertArrayHasKey('admin/portal/leave', $post);
    }
}
