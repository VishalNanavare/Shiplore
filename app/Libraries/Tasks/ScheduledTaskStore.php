<?php

declare(strict_types=1);

namespace App\Libraries\Tasks;

/**
 * ScheduledTaskStore — persistence contract for the `scheduled_tasks` registry,
 * so TaskRunner stays DB-agnostic and unit-testable (mirrors how
 * RequestAuthenticator takes an injected assignment loader).
 */
interface ScheduledTaskStore
{
    /**
     * Tasks ready to run: status=active AND (never ran OR next_run_at <= $nowSql).
     * @return list<array<string,mixed>>
     */
    public function due(string $nowSql): array;

    /**
     * Record a run. $nextRunAtSql NULL (unparseable cron) pauses the task so it
     * cannot hot-loop every tick.
     */
    public function markRun(int $id, string $ranAtSql, ?string $nextRunAtSql): void;

    /** Idempotent self-registration used by features that own a task. */
    public function ensure(string $code, string $cronExpr, string $handler, array $payload = []): int;
}
