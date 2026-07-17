<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\ApiResponse;
use App\Libraries\AuthException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * JwtAuthFilter — authenticates the Bearer token and populates ScopeContext.
 * Thin glue: delegates all logic to the tested RequestAuthenticator
 * (TokenService + CapabilityResolver) and CapabilityRepository (DB loader).
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §2
 */
final class JwtAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $authenticator = service('requestAuthenticator');
        $repo          = service('capabilityRepository');

        $header = $request->getHeaderLine('Authorization');
        try {
            // Resolving the secret can throw if it's misconfigured — keep it inside the
            // try so the client still gets the JSON envelope, not a 500 HTML page.
            $secret = \App\Libraries\TokenService::secret();
            $ctx    = $authenticator->authenticate(
                $header !== '' ? $header : null,
                $secret,
                time(),
                static fn (int $userId): array => $repo->loadAssignments($userId),
            );
        } catch (AuthException $e) {
            return service('response')
                ->setStatusCode(ApiResponse::statusFor('UNAUTHENTICATED'))
                ->setJSON(ApiResponse::error('UNAUTHENTICATED', $e->getMessage()));
        } catch (\RuntimeException $e) {
            return service('response')
                ->setStatusCode(ApiResponse::statusFor('SERVER_ERROR'))
                ->setJSON(ApiResponse::error('SERVER_ERROR', 'Authentication is temporarily unavailable.'));
        }

        // Re-check the account is still active on EVERY request — a suspended/deleted
        // user (or a vendor staffer whose JWT was revoked) must lose API access at once,
        // not keep it for the token's 30-day TTL.
        if (! service('apiAuthRepository')->isActive((int) ($ctx['actor_id'] ?? 0))) {
            return service('response')
                ->setStatusCode(ApiResponse::statusFor('UNAUTHENTICATED'))
                ->setJSON(ApiResponse::error('UNAUTHENTICATED', 'Account is not active.'));
        }

        service('scopeContext')->set($ctx);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
