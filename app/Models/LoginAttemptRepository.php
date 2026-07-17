<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * LoginAttemptRepository — append-only login audit + brute-force source of truth
 * (`login_attempts`). `recentFailureCount()` powers lockout; `record()` logs every
 * attempt. Both are best-effort: a DB hiccup must never break or wrongly lock login
 * (count failures open → returns 0 on error).
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §7
 */
final class LoginAttemptRepository
{
    public function recentFailureCount(string $identifier, int $windowMinutes): int
    {
        try {
            $since = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));

            return Database::connect()->table('login_attempts')
                ->where('identifier', mb_substr($identifier, 0, 191))
                ->where('success', 0)
                ->where('created_at >=', $since)
                ->countAllResults();
        } catch (Throwable) {
            return 0; // fail-open: never lock out due to a DB error
        }
    }

    public function record(string $identifier, bool $success, ?string $reason, ?int $userId, ?string $ip, ?string $userAgent): void
    {
        try {
            Database::connect()->table('login_attempts')->insert([
                'identifier'     => mb_substr($identifier, 0, 191),
                'user_id'        => $userId,
                'ip'             => $ip,
                'user_agent'     => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'channel'        => 'web',
                'success'        => $success ? 1 : 0,
                'failure_reason' => $reason,
            ]);
        } catch (Throwable) {
            // audit logging must not break the login response
        }
    }
}
