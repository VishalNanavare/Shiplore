<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * PasswordResetService — issues and verifies password-reset tokens. The raw
 * token is e-mailed to the user; only its SHA-256 hash is persisted. Verify
 * checks the hash (constant-time) and expiry. Pure / deterministic.
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §3.3
 */
final class PasswordResetService
{
    /** @return array{token:string,hash:string} raw token (email it) + hash (store it) */
    public function generate(): array
    {
        $token = bin2hex(random_bytes(32));

        return ['token' => $token, 'hash' => hash('sha256', $token)];
    }

    public function verify(string $token, string $storedHash, int $expiresAt, int $now): bool
    {
        if ($now > $expiresAt) {
            return false;
        }

        return hash_equals($storedHash, hash('sha256', $token));
    }
}
