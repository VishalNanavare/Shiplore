<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;

/**
 * TasksRun — executes due rows of the `scheduled_tasks` registry (X0).
 * Run as a cron/loop alongside sync:work:  `php spark tasks:run`.
 *
 * Handlers are registered on the shared taskRunner service by the features
 * that own them (commission-hold promotion, request SLA expiry, exports …).
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §8 C5
 */
final class TasksRun extends BaseCommand
{
    protected $group       = 'Ops';
    protected $name        = 'tasks:run';
    protected $description = 'Run due scheduled_tasks (cron registry) once.';
    protected $usage       = 'tasks:run';

    /** Known tasks self-register idempotently on every tick: code => [cron, handler]. */
    private const SEEDS = [
        'governance.expire_sla'  => ['*/15 * * * *', 'governance.expire_sla'],
        'commission.promote_due' => ['*/30 * * * *', 'commission.promote_due'],
    ];

    public function run(array $params): int
    {
        $repo = service('scheduledTaskRepository');
        foreach (self::SEEDS as $code => [$cron, $handler]) {
            $repo->ensure($code, $cron, $handler);
        }

        $results = service('taskRunner')->runDue(new DateTimeImmutable('now'));

        foreach ($results as $r) {
            $color = str_starts_with($r['result'], 'ok') ? 'green' : (str_starts_with($r['result'], 'skipped') ? 'yellow' : 'red');
            CLI::write("  {$r['code']} [{$r['handler']}] → {$r['result']}", $color);
        }
        CLI::write('Ran ' . count($results) . ' task(s).', 'cyan');

        return 0;
    }
}
