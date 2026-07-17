<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * RequestAuthenticator — turns an Authorization header into the resolved auth
 * context that PolicyEngine consumes. Pure glue over TokenService (verify) +
 * CapabilityResolver (assignments → permissions/scopes/attributes). The
 * assignment loader is injected so this stays DB-agnostic and testable; in
 * production the JwtAuthFilter supplies a repository-backed loader.
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §2,§5
 */
final class RequestAuthenticator
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly CapabilityResolver $capabilities,
    ) {}

    /**
     * @param  callable(int):array $assignmentLoader  user_id → role assignments
     * @return array  PolicyEngine context + principal_type + token_claims
     */
    public function authenticate(?string $authorizationHeader, string $secret, int $now, callable $assignmentLoader): array
    {
        if ($authorizationHeader === null || stripos($authorizationHeader, 'Bearer ') !== 0) {
            throw new AuthException('Missing or invalid Authorization header');
        }

        $token = trim(substr($authorizationHeader, 7));

        try {
            $claims = $this->tokens->verify($token, $secret, $now);
        } catch (TokenException $e) {
            throw new AuthException('Invalid token: ' . $e->getMessage(), 0, $e);
        }

        $userId = (int) ($claims['sub'] ?? 0);
        if ($userId <= 0) {
            throw new AuthException('Token missing subject');
        }

        $ctx = $this->capabilities->resolve($userId, $assignmentLoader($userId));
        $ctx['principal_type'] = $claims['typ'] ?? null;
        $ctx['token_claims']   = $claims;

        return $ctx;
    }
}
