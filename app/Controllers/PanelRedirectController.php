<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use Config\App;

/**
 * 404 override: send a panel path that arrived on the WRONG panel host to the host
 * that actually serves it, instead of showing a 404.
 *
 * Every panel route group in app/Config/Routes.php carries a 'subdomain' option, and
 * RouteCollection::create() SKIPS REGISTRATION entirely when the host does not match
 * (system/Router/RouteCollection.php:1487). So on the wrong host the route does not
 * exist and Router::handle() throws PageNotFoundException before any filter runs —
 * `before` filters, the controller, and the global `after` filters are all skipped
 * (system/CodeIgniter.php:464 vs :488/:503/:539). set404Override is the ONLY hook on
 * that path, which is why this is a 404 override and not a filter.
 *
 * The target host is derived from the PATH ALONE. /admin/... belongs to admin.<domain>
 * whoever is asking; /vendor/... to vendor.<domain>; and so on. The session is
 * deliberately not consulted — see the note on redirectTarget().
 */
final class PanelRedirectController extends BaseController
{
    /**
     * First URI segment => every subdomain label whose host legitimately serves that
     * route group. Mirrors the 'subdomain' option on each group in Config\Routes:
     * :194 admin, :598 vendor, :147/:156 rider, :811 manufacturer, :951 monline.
     *
     * The FIRST label in each list is the canonical target — the one a redirect lands
     * on. vendor./manufacturer. are the owner logins and shop./mshop. the unit-staff
     * logins, so the owner label is canonical, matching
     * WebAuthFilter::LANDING_SUBDOMAIN which already makes the same choice.
     *
     * Every other label in the list is an ACCEPTED host: a 404 there is a real 404,
     * not a wrong-host request. That is what stops mshop./manufacturer/typo from being
     * bounced onto manufacturer. — same group, different login, no reason to move the
     * user — and it is the loop guard for admin./admin/does-not-exist.
     *
     * @var array<string, list<string>>
     */
    private const PANEL_HOSTS = [
        'admin'        => ['admin'],
        'vendor'       => ['vendor', 'shop'],
        'rider'        => ['rider'],
        'manufacturer' => ['manufacturer', 'mshop'],
        'monline'      => ['monline'],
    ];

    /**
     * @param string $message The PageNotFoundException message, passed by
     *                        CodeIgniter::display404errors(). Unused: the message does
     *                        not distinguish a routing miss from a controller-thrown
     *                        404, and the host/path test below does not need it to.
     */
    public function index(string $message = ''): RedirectResponse
    {
        $target = $this->redirectTarget();

        if ($target === null) {
            // A genuine 404. Hand it straight back to the framework so the normal
            // error pipeline renders it — same view, same Config\Exceptions
            // ignoreCodes behaviour, same status, same CLI vs web branch — rather
            // than reimplementing the error page here. This is also why the four
            // existing tests that expectException(PageNotFoundException) keep passing.
            //
            // Close the buffer first. CodeIgniter::handleRequest() opens exactly one
            // (outputBufferingStart(), system/CodeIgniter.php:850 + :1183-1187), and
            // the no-override branch of display404errors() calls outputBufferingEnd()
            // at :1021 before it throws — the override branch returns at :1016 and
            // never reaches that line. Without this, every genuine 404 leaks one
            // level: measured, PHPUnit reports "did not close its own output buffers"
            // and the level count climbs once per request.
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            throw PageNotFoundException::forPageNotFound(
                ENVIRONMENT !== 'production' ? $message : null,
            );
        }

        return redirect()->to($target);
    }

    /**
     * The absolute URL this request should have gone to, or null to 404 normally.
     *
     * WHY THE SESSION IS NOT CONSULTED — this is the design, not an omission:
     *
     *  - Correctness. The answer to "which host serves /admin/dashboard?" is a fact
     *    about Config\Routes, identical for every visitor. Who is asking cannot change
     *    it. Mixing identity in can only produce a WRONG answer for somebody.
     *  - No side effects on an unauthenticated 404. Nothing on the 404 path touches
     *    the session today (BaseController::initController leaves it commented out);
     *    calling service('session') here would start one, writing a session file and a
     *    Set-Cookie for every scanner probe and every crawler hit on a stale URL.
     *  - Cacheable and CDN-safe. The response varies only by Host + path, so it can be
     *    cached at the edge. A session-derived redirect would be a per-user answer on a
     *    URL with no Vary: Cookie, i.e. one user's redirect served to the next.
     *  - It works signed-out. The operator's own case is reached by typing a URL; a
     *    session-derived rule has to invent an answer for anonymous visitors, and the
     *    existing map for that (LoginController::landingFor()) defaults to
     *    admin/dashboard, so it would point anonymous visitors and customers at the
     *    admin host.
     *  - It never leaks. A path-derived rule discloses only the routing table, which is
     *    already public. A session-derived one turns the 404 handler into an oracle
     *    that reports the viewer's principal type.
     *
     * The user's identity still gets enforced — one hop later, on the correct host,
     * by webAuth/WebAuthFilter, which is where it belongs. This handler answers
     * "where does this path live", not "who are you".
     */
    private function redirectTarget(): ?string
    {
        // The body of a non-GET is not carried across a redirect: redirect()->to()
        // picks 303 for POST (system/HTTP/ResponseTrait.php:458), which downgrades to
        // GET and drops it, so the user would silently "succeed" at nothing; 307 would
        // carry it, but replaying a body cross-origin under a domain-wide session
        // cookie is not something a 404 handler should decide to do. Only navigations
        // are redirected.
        $method = strtoupper($this->request->getMethod());
        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }

        $path  = trim($this->request->getUri()->getPath(), '/');
        $panel = explode('/', $path)[0];

        // Segment-based, never str_starts_with: 'manufacturer-register' is a real
        // top-level unpinned route (Config\Routes:57) that a prefix test would capture
        // and bounce off the apex where it is meant to be served.
        if (! isset(self::PANEL_HOSTS[$panel])) {
            return null;
        }

        // The current host's own panel label, if it has one. Read from HTTP_HOST and
        // not from getUri()->getHost(): under the test suite the URI host is always
        // app.baseURL's (system/Test/FeatureTestTrait.php:331 builds SiteURI from the
        // config), so a URI-host rule would work live and silently misbehave in every
        // test. HTTP_HOST is also what panel_url() reads.
        $host  = explode(':', (string) $this->request->getServer('HTTP_HOST'))[0];
        $label = explode('.', $host)[0];
        if (! in_array($label, App::PANEL_SUBDOMAINS, true)) {
            $label = ''; // apex, an unknown host, or a bare hostname
        }

        // Already on a host that serves this group => the 404 is real. This is the loop
        // guard, and it is what makes the dual-subdomain groups behave.
        if (in_array($label, self::PANEL_HOSTS[$panel], true)) {
            return null;
        }

        // panel_url() builds $sub.<base-domain> from the CURRENT host and validates it
        // against Config\App::$allowedHostnames (derived from app.baseDomain), falling
        // back to a same-host relative URL when it cannot. Reused rather than rebuilt so
        // there is exactly one place in the app that constructs a sibling panel host,
        // and so no domain literal appears here.
        $url        = panel_url(self::PANEL_HOSTS[$panel][0], $path);
        $targetHost = (string) parse_url($url, PHP_URL_HOST);

        // Only ever leave this host for a host this application is configured to answer
        // on. Two things fail into panel_url()'s base_url() fallback and both must land
        // here as a plain 404, not a redirect:
        //   - a spoofed/unknown/bare/IP Host header, where the fallback resolves to
        //     $baseURL's host — redirecting there would bounce an attacker-chosen host
        //     onto a URL we did not derive from this request;
        //   - a host whose sibling cannot be validated, where the fallback is this same
        //     host and this same already-404ing path, i.e. a redirect loop.
        // Checking membership of $allowedHostnames rather than the shape of the string
        // states the property directly, and keeps app.baseDomain the only source.
        if ($targetHost === $host || ! in_array($targetHost, config(App::class)->allowedHostnames, true)) {
            return null;
        }

        $query = $this->request->getUri()->getQuery();

        return $query === '' ? $url : $url . '?' . $query;
    }
}
