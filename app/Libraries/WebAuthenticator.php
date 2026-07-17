<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * WebAuthenticator — verifies session-login credentials. Pure: the user lookup
 * is injected (a repository in production). Returns the user row on success,
 * throws AuthException with a uniform message on any failure (prevents user
 * enumeration). Lockout/attempt-logging is handled by the controller.
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §2,§7
 */
final class WebAuthenticator
{
    private const GENERIC_ERROR = 'Invalid credentials';

    /**
     * @param  callable(string):?array $lookup  login (email|phone) → user row|null
     * @return array  the authenticated user row
     */
    public function attempt(string $login, string $password, callable $lookup): array
    {
        $user = $lookup($login);

        if ($user === null) {
            throw new AuthException(self::GENERIC_ERROR);
        }
        if (($user['status'] ?? null) !== 'active') {
            throw new AuthException(self::GENERIC_ERROR);
        }
        if (empty($user['password_hash']) || ! password_verify($password, $user['password_hash'])) {
            throw new AuthException(self::GENERIC_ERROR);
        }

        return $user;
    }
}
