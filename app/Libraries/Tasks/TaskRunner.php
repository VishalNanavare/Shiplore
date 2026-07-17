<?php

declare(strict_types=1);

namespace App\Libraries\Tasks;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * TaskRunner — executes due `scheduled_tasks` (X0). Handlers are plain
 * callables registered by the feature that owns them (commission-hold
 * promotion, request SLA expiry, scheduled exports …), so this stays a dumb,
 * reliable clock. A failing handler never blocks the other tasks; an
 * unparseable cron pauses its task instead of hot-looping.
 *
 * Driven by `php spark tasks:run` (cron/loop, like sync:work).
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §8 C5
 */
final class TaskRunner
{
    /** @var array<string, callable(array<string,mixed>):mixed> */
    private array $handlers = [];

    public function __construct(
        private readonly ScheduledTaskStore $tasks,
        private readonly CronSchedule $cron = new CronSchedule(),
    ) {}

    /** @param callable(array<string,mixed>):mixed $fn */
    public function register(string $handler, callable $fn): self
    {
        $this->handlers[$handler] = $fn;

        return $this;
    }

    /**
     * Run everything due at $now.
     * @return list<array{id:int,code:string,handler:string,result:string}>
     */
    public function runDue(DateTimeImmutable $now): array
    {
        $out = [];

        foreach ($this->tasks->due($now->format('Y-m-d H:i:s')) as $task) {
            $fn     = $this->handlers[(string) $task['handler']] ?? null;
            $result = 'ok';

            if ($fn === null) {
                $result = 'skipped:unknown_handler';
            } else {
                try {
                    $fn($task);
                } catch (Throwable $e) {
                    $result = 'error:' . $e->getMessage();
                }
            }

            try {
                $next = $this->cron->nextRunAt((string) $task['cron_expr'], $now)->format('Y-m-d H:i:s');
            } catch (InvalidArgumentException) {
                $next   = null; // store pauses the task
                $result .= '|bad_cron';
            }

            $this->tasks->markRun((int) $task['id'], $now->format('Y-m-d H:i:s'), $next);
            $out[] = ['id' => (int) $task['id'], 'code' => (string) $task['code'], 'handler' => (string) $task['handler'], 'result' => $result];
        }

        return $out;
    }
}
