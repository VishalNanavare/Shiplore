<?php

declare(strict_types=1);

namespace App\Libraries\Sync;

/**
 * RetryPolicy — exponential backoff for queued sync/webhook jobs. After
 * `maxAttempts` the job is exhausted and moves to the dead-letter queue. Pure.
 *
 * @see docs/architecture/35-SYNC-ENGINE.md
 */
final class RetryPolicy
{
    public const MAX_ATTEMPTS = 5;
    private const BASE_SECONDS = 10;
    private const CAP_SECONDS  = 3600;

    /** Delay before the Nth attempt (1-based): 10, 20, 40, 80 … capped at 1h. */
    public function nextDelaySeconds(int $attempts): int
    {
        $delay = self::BASE_SECONDS * (2 ** max(0, $attempts - 1));

        return (int) min($delay, self::CAP_SECONDS);
    }

    public function isExhausted(int $attempts, int $max = self::MAX_ATTEMPTS): bool
    {
        return $attempts >= $max;
    }
}
