<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * TokenService — minimal JWT (HS256) issue/verify, pure PHP (hash_hmac).
 * Deterministic: callers pass `$now` (unix seconds) so behavior is testable
 * and clock-independent; production passes time().
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §2
 */
final class TokenService
{
    /** The source-committed placeholder that must NEVER be used to sign real tokens. */
    public const INSECURE_DEFAULT = 'dev-insecure-secret-change-me';

    /**
     * Resolve the HS256 signing/verification secret from the environment. Fails
     * CLOSED: if the secret is unset or still the insecure source-committed default,
     * it throws rather than signing/verifying with a publicly-known key (which would
     * let anyone forge a token for any user). Set jwt.secret (or JWT_SECRET) per env.
     */
    public static function secret(): string
    {
        $s = (string) (getenv('JWT_SECRET') ?: env('jwt.secret', ''));
        if ($s === '' || $s === self::INSECURE_DEFAULT) {
            throw new \RuntimeException('JWT secret is not configured. Set jwt.secret (or JWT_SECRET env) to a strong per-environment value.');
        }

        return $s;
    }

    /** Issue a signed token. Adds iat/exp; returns header.payload.signature. */
    public function issue(array $claims, int $ttlSeconds, string $secret, int $now): string
    {
        $header  = ['alg' => 'HS256', 'typ' => 'JWT'];
        $claims['iat'] = $now;
        $claims['exp'] = $now + $ttlSeconds;

        $h = $this->b64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $p = $this->b64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $s = $this->sign("{$h}.{$p}", $secret);

        return "{$h}.{$p}.{$s}";
    }

    /** Verify signature + expiry; return claims or throw TokenException. */
    public function verify(string $token, string $secret, int $now): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new TokenException('Malformed token');
        }
        [$h, $p, $s] = $parts;

        // Defence in depth, not a fix for an exploitable hole: the signature check
        // below always expects an HS256 HMAC regardless of what the header claims,
        // so the usual alg:none / RS256->HS256 confusion attacks already fail here
        // by construction. Parsing the header and rejecting anything but HS256
        // narrows the claim surface so a future caller can't accidentally trust it.
        $header = json_decode($this->b64UrlDecode($h), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            throw new TokenException('Unsupported token algorithm');
        }

        $expected = $this->sign("{$h}.{$p}", $secret);
        if (! hash_equals($expected, $s)) {
            throw new TokenException('Invalid signature');
        }

        $json   = $this->b64UrlDecode($p);
        $claims = json_decode($json, true);
        if (! is_array($claims)) {
            throw new TokenException('Invalid payload');
        }

        // exp is now mandatory, not just enforced when present: issue() always sets
        // it, so this only closes a latent hole for a future caller that forgot to.
        if (! isset($claims['exp']) || $now > (int) $claims['exp']) {
            throw new TokenException('Token expired');
        }

        return $claims;
    }

    /**
     * Password-binding claim: a one-way fingerprint of the account's current
     * password_hash, so a token minted before a password change can be told
     * apart from one minted after. Never the raw hash — a stolen/decoded token
     * must not hand an attacker bcrypt material to attack offline. Null when
     * the account has no password set (OTP-only), so nothing is stamped and
     * JwtAuthFilter's check stays a no-op for those tokens.
     */
    public static function pwdClaim(?string $passwordHash): ?string
    {
        if ($passwordHash === null || $passwordHash === '') {
            return null;
        }

        return substr(hash('sha256', $passwordHash), 0, 16);
    }

    private function sign(string $data, string $secret): string
    {
        return $this->b64UrlEncode(hash_hmac('sha256', $data, $secret, true));
    }

    private function b64UrlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private function b64UrlDecode(string $s): string
    {
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }

        return (string) base64_decode(strtr($s, '-_', '+/'));
    }
}
