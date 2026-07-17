<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * SyncWorker — background job runner for the sync engine. Drains the `sync` and
 * `webhook` queues, handling each job and applying retry/backoff or dead-letter
 * on failure. Run as a cron/loop: `php spark sync:work --max=200`.
 *
 * @see docs/architecture/35-SYNC-ENGINE.md
 */
final class SyncWorker extends BaseCommand
{
    protected $group       = 'Sync';
    protected $name        = 'sync:work';
    protected $description  = 'Drain the sync/webhook job queues with retry + dead-letter.';
    protected $usage        = 'sync:work [--max N] [--queue sync|webhook]';

    public function run(array $params): int
    {
        $max    = (int) (CLI::getOption('max') ?: 100);
        $queues = CLI::getOption('queue') ? [(string) CLI::getOption('queue')] : ['sync', 'webhook', 'notify', 'export'];
        $q      = service('jobQueueRepository');
        $done   = 0;

        foreach ($queues as $queue) {
            while ($done < $max) {
                $job = $q->reserve($queue);
                if ($job === null) {
                    break;
                }
                $done++;
                try {
                    $this->handle($job);
                    $q->complete((int) $job['id']);
                    CLI::write("  ✓ #{$job['id']} {$job['type']}", 'green');
                } catch (Throwable $e) {
                    $result = $q->fail((int) $job['id'], $e->getMessage());
                    CLI::write("  ✗ #{$job['id']} {$job['type']} → {$result}", 'yellow');
                }
            }
        }

        CLI::write("Processed {$done} job(s).", 'cyan');

        return 0;
    }

    /** @param array<string,mixed> $job */
    private function handle(array $job): void
    {
        $payload = json_decode((string) $job['payload'], true) ?: [];

        switch ($job['type']) {
            case 'webhook.deliver':
                service('webhookDispatcher')->deliver(
                    (int) ($payload['subscription_id'] ?? 0),
                    (string) ($payload['event'] ?? ''),
                    (array) ($payload['payload'] ?? []),
                    (int) $job['attempts'] + 1,
                );
                break;
            case 'notification.deliver':
                service('notificationService')->deliver((int) ($payload['delivery_id'] ?? 0), (string) ($payload['channel'] ?? 'in_app'));
                break;
            case 'export.run':
                $res = service('reportExportService')->run((int) ($payload['export_job_id'] ?? 0));
                if (! ($res['ok'] ?? false)) {
                    throw new \RuntimeException($res['reason'] ?? 'export failed');
                }
                break;
            case 'sync.replay':
                // requeued dead-letter item — handled by the entity handler; no-op stub here
                break;
            default:
                // unknown type → treat as a no-op success so it leaves the queue
                break;
        }
    }
}
