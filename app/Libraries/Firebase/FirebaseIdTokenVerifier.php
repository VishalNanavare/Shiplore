<?php

declare(strict_types=1);

namespace App\Libraries\Firebase;

use Throwable;

/**
 * FirebaseIdTokenVerifier — verifies a Firebase Phone-Auth ID token on the server.
 *
 * Firebase sends the OTP from the CLIENT SDK; the browser then hands us the
 * signed ID token. This class proves that token is genuine before we trust the
 * phone number in it:
 *   - RS256 signature against Google's rotating public certs (kid in the header)
 *   - aud  == our Firebase projectId
 *   - iss  == https://securetoken.google.com/<projectId>
 *   - exp  in the future, iat not in the future, sub (uid) present
 *   - auth_time present and in the past (when the user authenticated)
 *
 * The cert source is injectable so the verification logic is unit-testable
 * without network access. Returns the decoded claims (incl. phone_number) on
 * success, or null on any failure. Never throws.
 *
 * @see App\Controllers\Auth\FirebaseOtpController
 */
final class FirebaseIdTokenVerifier
{
    /** @var (callable():array<string,string>)|null kid => PEM cert; null = fetch Google's */
    private $certProvider;

    public function __construct(private string $projectId, ?callable $certProvider = null)
    {
        $this->certProvider = $certProvider;
    }

    /**
     * @return array<string,mixed>|null verified claims (phone_number, sub, …) or null if invalid
     */
    public function verify(string $idToken, int $now = 0): ?array
    {
        if ($this->projectId === '') {
            return null;
        }
        $now   = $now > 0 ? $now : time();
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        [$h64, $p64, $s64] = $parts;

        $header  = json_decode($this->b64($h64), true);
        $payload = json_decode($this->b64($p64), true);
        if (! is_array($header) || ! is_array($payload)) {
            return null;
        }
        if (($header['alg'] ?? '') !== 'RS256' || ($header['kid'] ?? '') === '') {
            return null;
        }

        $cert = $this->certs()[$header['kid']] ?? null;
        if ($cert === null) {
            return null;
        }
        $pub = openssl_pkey_get_public($cert);
        if ($pub === false) {
            return null;
        }
        if (openssl_verify($h64 . '.' . $p64, $this->b64($s64), $pub, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        // Claim checks (Firebase ID-token spec).
        if (($payload['aud'] ?? '') !== $this->projectId) {
            return null;
        }
        if (($payload['iss'] ?? '') !== 'https://securetoken.google.com/' . $this->projectId) {
            return null;
        }
        if ((int) ($payload['exp'] ?? 0) <= $now) {
            return null;
        }
        if ((int) ($payload['iat'] ?? 0) > $now + 300) {
            return null;
        }
        if (trim((string) ($payload['sub'] ?? '')) === '') {
            return null;
        }
        // auth_time: when the user actually authenticated. Must be present and
        // not in the future (allow the same 5-minute clock skew as iat).
        $authTime = (int) ($payload['auth_time'] ?? 0);
        if ($authTime <= 0 || $authTime > $now + 300) {
            return null;
        }

        return $payload;
    }

    /** @return array<string,string> kid => PEM cert */
    private function certs(): array
    {
        if ($this->certProvider !== null) {
            return ($this->certProvider)();
        }

        try {
            $cache = service('cache');
            $key   = 'firebase_securetoken_certs';
            $certs = $cache->get($key);
            if (! is_array($certs) || $certs === []) {
                $json  = file_get_contents('https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com');
                $certs = $json !== false ? (json_decode($json, true) ?: []) : [];
                if ($certs !== []) {
                    $cache->save($key, $certs, 3600);
                }
            }

            return is_array($certs) ? $certs : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function b64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'), true);
    }
}
