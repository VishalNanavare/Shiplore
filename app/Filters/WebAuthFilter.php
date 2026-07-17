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

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
