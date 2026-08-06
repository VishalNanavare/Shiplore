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
        $actorId = (int) ($ctx['actor_id'] ?? 0);
        if (! service('apiAuthRepository')->isActive($actorId)) {
            return service('response')
                ->setStatusCode(ApiResponse::statusFor('UNAUTHENTICATED'))
                ->setJSON(ApiResponse::error('UNAUTHENTICATED', 'Account is not active.'));
        }

        // Bind the token to the password it was minted under. Nullable-tolerant on
        // purpose: tokens already in the wild (minted before this claim existed) carry
        // no `pwd` claim and keep working until they expire — no shipped app is signed
        // out by this deploy. Once a token DOES carry the claim, a password change
        // invalidates it immediately instead of leaving it valid for the full 30-day TTL.
        $pwdClaim = $ctx['token_claims']['pwd'] ?? null;
        if ($pwdClaim !== null) {
            $current    = service('apiAuthRepository')->findById($actorId);
            $currentPwd = $current !== null ? \App\Libraries\TokenService::pwdClaim($current['password_hash'] ?? null) : null;
            if ($currentPwd === null || ! hash_equals($currentPwd, (string) $pwdClaim)) {
                return service('response')
                    ->setStatusCode(ApiResponse::statusFor('UNAUTHENTICATED'))
                    ->setJSON(ApiResponse::error('UNAUTHENTICATED', 'Session is no longer valid. Please sign in again.'));
            }
        }

        service('scopeContext')->set($ctx);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
