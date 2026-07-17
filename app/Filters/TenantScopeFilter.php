<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\ApiResponse;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * TenantScopeFilter — guards that a non-platform actor carries at least one
 * tenant scope before reaching vendor/shop routes. The actual row-level
 * filtering is applied at the repository/model layer (Layer 4) reading the
 * same ScopeContext; this filter is the early guard against an actor with no
 * resolvable scope hitting tenant data.
 *
 * @see docs/architecture/08-PERMISSIONS-ARCHITECTURE.md §5, 23 §6
 */
final class TenantScopeFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $scope = service('scopeContext');

        if (! $scope->isAuthenticated()) {
            return service('response')
                ->setStatusCode(ApiResponse::statusFor('UNAUTHENTICATED'))
                ->setJSON(ApiResponse::error('UNAUTHENTICATED', 'Authentication required'));
        }

        // Platform actors are unscoped (cross-tenant) by design; everyone else
        // must resolve to at least one scope, else they cannot touch tenant data.
        if (! $scope->isPlatform() && $scope->scopes() === []) {
            return service('response')
                ->setStatusCode(ApiResponse::statusFor('SCOPE_VIOLATION'))
                ->setJSON(ApiResponse::error('SCOPE_VIOLATION', 'No tenant scope resolved for this actor'));
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
