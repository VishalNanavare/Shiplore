<?php

declare(strict_types=1);

namespace App\Libraries\Otp;

use Config\Database;

/**
 * OtpService — issues and verifies one-time codes (mobile OTP / email code) in
 * `auth_otp`. Only the SHA-256 hash is stored; the plain code is returned to the
 * caller to deliver via SMS/Firebase/email. Single-use, time-boxed.
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §OTP
 */
final class OtpService
{
    private const TTL          = 600; // 10 minutes
    private const MAX_ATTEMPTS = 5;   // wrong guesses before the code is burned

    /** Issue a 6-digit code for an identifier (phone/email). Returns the plain code. */
    public function issue(string $identifier, string $purpose = 'verify'): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Database::connect()->table('auth_otp')->insert([
            'identifier' => mb_substr($identifier, 0, 191),
            'code_hash'  => hash('sha256', $code),
            'purpose'    => $purpose,
            'attempts'   => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + self::TTL),
        ]);

        return $code;
    }

    /**
     * Invalidate every live code for an identifier/purpose. Called before re-issuing so
     * that exactly one code is ever live: verify() already honours only the newest row,
     * so this makes that implicit contract explicit and keeps the MAX_ATTEMPTS budget
     * meaningful (N live codes would otherwise mean N x MAX_ATTEMPTS guesses).
     *
     * @return int rows invalidated
     */
    public function invalidate(string $identifier, string $purpose = 'verify'): int
    {
        $db = Database::connect();
        $db->table('auth_otp')
            ->where('identifier', mb_substr($identifier, 0, 191))
            ->where('purpose', $purpose)
            ->where('consumed_at', null)
            ->update(['consumed_at' => date('Y-m-d H:i:s')]);

        return $db->affectedRows();
    }

    /** Invalidate any live code, then issue a fresh one. Used by "resend". */
    public function reissue(string $identifier, string $purpose = 'verify'): string
    {
        $this->invalidate($identifier, $purpose);

        return $this->issue($identifier, $purpose);
    }

    /**
     * Validate a code WITHOUT consuming it. Wrong guesses still count towards
     * MAX_ATTEMPTS, so this is not a free brute-force oracle. Use it when a later step
     * can still fail (GST lookup, vendor insert) and the code must survive for a retry;
     * call verify() to consume once every step has succeeded.
     */
    public function check(string $identifier, string $code, string $purpose = 'verify'): bool
    {
        return $this->match($identifier, $code, $purpose, false);
    }

    /** Verify a code; consumes it on success. */
    public function verify(string $identifier, string $code, string $purpose = 'verify'): bool
    {
        return $this->match($identifier, $code, $purpose, true);
    }

    private function match(string $identifier, string $code, string $purpose, bool $consume): bool
    {
        $db  = Database::connect();
        $row = $db->table('auth_otp')
            ->where('identifier', mb_substr($identifier, 0, 191))
            ->where('purpose', $purpose)
            ->where('consumed_at', null)
            ->orderBy('id', 'DESC')
            ->get()->getRowArray();

        if ($row === null || strtotime((string) $row['expires_at']) < time()) {
            return false;
        }
        // Per-OTP guess limit: once too many wrong attempts are made, burn the code so
        // it can't be brute-forced within its TTL (the IP throttle alone is insufficient).
        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            $db->table('auth_otp')->where('id', $row['id'])->update(['consumed_at' => date('Y-m-d H:i:s')]);

            return false;
        }
        if (! hash_equals((string) $row['code_hash'], hash('sha256', $code))) {
            // Atomic increment so concurrent wrong guesses can't all read the same value
            // and slip past the MAX_ATTEMPTS cap.
            $db->table('auth_otp')->where('id', $row['id'])->set('attempts', 'attempts + 1', false)->update();

            return false;
        }

        if ($consume) {
            $db->table('auth_otp')->where('id', $row['id'])->update(['consumed_at' => date('Y-m-d H:i:s')]);
        }

        return true;
    }
}
