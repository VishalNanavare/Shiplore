<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Tasks\ScheduledTaskStore;
use Config\Database;

/**
 * ScheduledTaskRepository — DB access for the (previously unwired)
 * `scheduled_tasks` registry. Implements the runner's store contract.
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §8 C5
 */
final class ScheduledTaskRepository implements ScheduledTaskStore
{
    public function due(string $nowSql): array
    {
        return Database::connect()->table('scheduled_tasks')
            ->where('status', 'active')
            ->groupStart()->where('next_run_at IS NULL', null, false)->orWhere('next_run_at <=', $nowSql)->groupEnd()
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    public function markRun(int $id, string $ranAtSql, ?string $nextRunAtSql): void
    {
        $update = ['last_run_at' => $ranAtSql, 'next_run_at' => $nextRunAtSql];
        if ($nextRunAtSql === null) {
            $update['status'] = 'paused'; // unparseable cron must not hot-loop
        }
        Database::connect()->table('scheduled_tasks')->where('id', $id)->update($update);
    }

    public function ensure(string $code, string $cronExpr, string $handler, array $payload = []): int
    {
        $db  = Database::connect();
        $row = $db->table('scheduled_tasks')->select('id')->where('code', $code)->get()->getRowArray();
        if ($row !== null) {
            return (int) $row['id'];
        }
        $db->table('scheduled_tasks')->insert([
            'code' => $code, 'cron_expr' => $cronExpr, 'handler' => $handler,
            'payload' => $payload === [] ? null : json_encode($payload),
            'status' => 'active',
        ]);

        return (int) $db->insertID();
    }

    /** @return list<array<string,mixed>> for the ops/admin view */
    public function all(): array
    {
        return Database::connect()->table('scheduled_tasks')->orderBy('code')->get()->getResultArray();
    }
}
