<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Sync\RetryPolicy;
use Config\Database;

/**
 * JobQueueRepository — the durable background queue (`jobs`) with per-attempt
 * logging (`job_attempts`), exponential-backoff retry and a dead-letter queue
 * (`dead_letter_jobs`). Used by the sync engine and webhook dispatcher; drained
 * by the `sync:work` worker. Enqueue is idempotent on `idempotency_key`.
 *
 * @see docs/architecture/35-SYNC-ENGINE.md
 */
final class JobQueueRepository
{
    public function __construct(private readonly RetryPolicy $retry = new RetryPolicy()) {}

    /** @param array<string,mixed> $payload */
    public function enqueue(string $queue, string $type, array $payload, ?string $idempotencyKey = null, ?string $availableAt = null): int
    {
        $db = Database::connect();
        if ($idempotencyKey !== null) {
            $existing = $db->table('jobs')->select('id')->where('idempotency_key', $idempotencyKey)->get()->getRowArray();
            if ($existing !== null) {
                return (int) $existing['id'];
            }
        }
        $db->table('jobs')->insert([
            'uuid' => $this->uuid(), 'queue' => $queue, 'type' => $type,
            'payload' => json_encode($payload), 'available_at' => $availableAt ?? date('Y-m-d H:i:s'),
            'idempotency_key' => $idempotencyKey, 'attempts' => 0, 'status' => 'queued',
        ]);

        return (int) $db->insertID();
    }

    /** Claim the next due job (optimistic; safe under concurrency). @return array<string,mixed>|null */
    public function reserve(string $queue): ?array
    {
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        $row = $db->table('jobs')
            ->where('queue', $queue)->where('status', 'queued')->where('available_at <=', $now)
            ->orderBy('id', 'ASC')->get(1)->getRowArray();
        if ($row === null) {
            return null;
        }
        $db->table('jobs')->where('id', $row['id'])->where('status', 'queued')
            ->update(['status' => 'reserved', 'reserved_at' => $now]);
        if ($db->affectedRows() !== 1) {
            return null; // lost the race to another worker
        }

        return $db->table('jobs')->where('id', $row['id'])->get()->getRowArray();
    }

    public function complete(int $jobId): void
    {
        $db       = Database::connect();
        $attempts = (int) ($db->table('jobs')->select('attempts')->where('id', $jobId)->get()->getRowArray()['attempts'] ?? 0);
        $db->table('jobs')->where('id', $jobId)->update(['status' => 'done']);
        $this->logAttempt($jobId, $attempts + 1, 'ok', null);
    }

    /** Record a failure: retry with backoff, or dead-letter when exhausted. */
    public function fail(int $jobId, string $error): string
    {
        $db  = Database::connect();
        $job = $db->table('jobs')->where('id', $jobId)->get()->getRowArray();
        if ($job === null) {
            return 'missing';
        }
        $attempts = (int) $job['attempts'] + 1;
        $this->logAttempt($jobId, $attempts, 'error', $error);

        if ($this->retry->isExhausted($attempts)) {
            $db->table('dead_letter_jobs')->insert([
                'original_job_uuid' => $job['uuid'], 'queue' => $job['queue'], 'type' => $job['type'],
                'payload' => $job['payload'], 'last_error' => mb_substr($error, 0, 500),
                'failed_at' => date('Y-m-d H:i:s'), 'status' => 'open',
            ]);
            $db->table('jobs')->where('id', $jobId)->update(['attempts' => $attempts, 'status' => 'failed']);

            return 'dead_letter';
        }

        $db->table('jobs')->where('id', $jobId)->update([
            'attempts'     => $attempts,
            'status'       => 'queued',
            'available_at' => date('Y-m-d H:i:s', time() + $this->retry->nextDelaySeconds($attempts)),
            'reserved_at'  => null,
        ]);

        return 'retry';
    }

    /** @return array<string,int> */
    public function stats(): array
    {
        $db = Database::connect();
        $out = [];
        foreach (['queued', 'reserved', 'processing', 'done', 'failed'] as $s) {
            $out[$s] = (int) $db->table('jobs')->where('status', $s)->countAllResults();
        }
        $out['dead_letter'] = (int) $db->table('dead_letter_jobs')->where('status', 'open')->countAllResults();

        return $out;
    }

    /** @param 'ok'|'error' $status */
    private function logAttempt(int $jobId, int $attemptNo, string $status, ?string $error): void
    {
        Database::connect()->table('job_attempts')->insert([
            'job_id' => $jobId, 'attempt_no' => $attemptNo, 'error' => $error !== null ? mb_substr($error, 0, 500) : null,
            'started_at' => date('Y-m-d H:i:s'), 'finished_at' => date('Y-m-d H:i:s'), 'status' => $status,
        ]);
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
