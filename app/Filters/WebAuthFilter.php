<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * WebAuthFilter — session guard for web (browser) pages. Redirects unauthenticated
 * visitors to the login screen, and populates ScopeContext from the session user
 * (reusing CapabilityRepository + CapabilityResolver) so views/policies can read
 * permissions. Web counterpart to JwtAuthFilter (which serves the API).
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §2
 */
final class WebAuthFilter implements FilterInterface
{
    /**
     * Panels this filter can pin a route group to, mapped to the landing page a
     * mismatched principal is sent back to. Mirrors Auth\LoginController::landingFor().
     */
    private const LANDING = [
        'platform'     => 'admin/dashboard',
        'vendor'       => 'vendor/dashboard',
        'manufacturer' => 'manufacturer/dashboard',
    ];

    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();

        if (! $session->get('isLoggedIn')) {
            return redirect()->to('login')->with('error', 'Please sign in to continue.');
        }

        $userId   = (int) $session->get('user_id');
        $resolver = service('capabilityResolver');
        $repo     = service('capabilityRepository');

        $ctx                   = $resolver->resolve($userId, $repo->loadAssignments($userId));
        $ctx['principal_type'] = $session->get('principal_type');
        service('scopeContext')->set($ctx);

        return $this->checkPrincipal($request, $session->get('principal_type'), $arguments[0] ?? null, $userId);
    }

    /**
     * Pin a route group to one kind of principal (`webAuth:platform`, `webAuth:vendor`).
     *
     * Without this the filter proved only "some session is logged in". The session
     * cookie is scoped to .shiplore.in (app/Config/Cookie.php), so a vendor login at
     * vendor.shiplore.in is sent to admin.shiplore.in as well — leaving the
     * per-controller permission checks as the only thing between vendor staff and
     * the admin panel. Those checks are inconsistent: 34 admin authorization sites
     * are gated on vendor- or shop-scoped permission codes that vendor roles
     * actually hold (see tests/unit/Common/AdminGuardScopeTest.php). This closes
     * the hole at the source instead of at 34 call sites.
     *
     * Impersonation is unaffected: Admin\PortalController rewrites principal_type to
     * 'vendor' on enter and back to 'platform' on leave.
     *
     * ROLLOUT — log-only by default. On a mismatch it records what it WOULD have
     * blocked and lets the request through, so a mislabelled account cannot lock
     * real staff out of production. Read the 'principal-type mismatch' warnings for
     * a full traffic day, confirm they are all genuine cross-panel access, then set
     *
     *     auth.enforcePrincipalType = true
     *
     * in .env to enforce. Both flipping and rolling back are env-only, no deploy.
     */
    private function checkPrincipal(RequestInterface $request, mixed $actual, ?string $expected, int $userId)
    {
        if ($expected === null) {
            return null; // route group opted out (e.g. the shared notification feed)
        }

        $actual = (string) ($actual ?? '');
        if ($actual === $expected) {
            return null;
        }

        $enforcing = filter_var(env('auth.enforcePrincipalType', false), FILTER_VALIDATE_BOOLEAN);

        log_message(
            $enforcing ? 'warning' : 'notice',
            sprintf(
                'principal-type mismatch [%s]: user %d is "%s" but %s requires "%s".',
                $enforcing ? 'BLOCKED' : 'would block',
                $userId,
                $actual !== '' ? $actual : '(none)',
                $request->getUri()->getPath(),
                $expected,
            ),
        );

        if (! $enforcing) {
            return null;
        }

        return redirect()
            ->to(self::LANDING[$actual] ?? 'login')
            ->with('error', 'That area is not available for this account.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
