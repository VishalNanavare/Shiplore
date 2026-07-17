<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\ApiResponse;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PermissionFilter — RBAC gate. Route alias `perm:<code>` supplies the
 * required permission as an argument; checks it against the resolved
 * ScopeContext via the tested PolicyEngine. ABAC (target scope) is enforced
 * downstream in services/repositories using the same context.
 *
 * @see docs/architecture/08-PERMISSIONS-ARCHITECTURE.md, 23 §5
 */
final class PermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $scope = service('scopeContext');

        if (! $scope->isAuthenticated()) {
            return service('response')
                ->setStatusCode(ApiResponse::statusFor('UNAUTHENTICATED'))
                ->setJSON(ApiResponse::error('UNAUTHENTICATED', 'Authentication required'));
        }

        $required = $arguments[0] ?? null;
        if ($required !== null && ! service('policyEngine')->can($scope->all(), $required)) {
            return service('response')
                ->setStatusCode(ApiResponse::statusFor('FORBIDDEN'))
                ->setJSON(ApiResponse::error('FORBIDDEN', 'Missing permission: ' . $required));
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
