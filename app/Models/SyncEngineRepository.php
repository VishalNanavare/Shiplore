<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Sync\ConflictResolver;
use App\Libraries\Sync\SyncRegistry;
use Config\Database;
use Throwable;

/**
 * SyncEngineRepository — orchestrates inbound/outbound sync across all entities.
 * Inbound (push): idempotent per batch (sync_batches.idempotency_key) and per
 * item (sync_entities), with optimistic-concurrency conflict detection logged to
 * sync_conflicts and partial-recovery via sync_dead_letter. Outbound (pull):
 * generic delta read by updated_at with a returned anchor + cursor advance.
 *
 * @see docs/architecture/35-SYNC-ENGINE.md
 */
final class SyncEngineRepository
{
    public function __construct(
        private readonly SyncRegistry $registry = new SyncRegistry(),
        private readonly ConflictResolver $resolver = new ConflictResolver(),
    ) {}

    /**
     * Generic delta pull for an entity.
     * @return array{entity:string,rows:list<array<string,mixed>>,next_anchor:string}
     */
    public function pull(string $entity, ?string $since, ?int $vendorId = null, ?int $shopId = null, int $limit = 200): array
    {
        $table = $this->registry->table($entity);
        if ($table === null) {
            return ['entity' => $entity, 'rows' => [], 'next_anchor' => $since ?? ''];
        }
        $since = $since ?: '1970-01-01 00:00:00';
        $b     = Database::connect()->table($table)->where('updated_at >', $since);

        // Tenant scope: never return another tenant's rows. The caller MUST pass the
        // terminal's vendor_id/shop_id; entities with no tenant column are rejected
        // upstream (SyncController), so this is defense-in-depth.
        $col = $this->registry->tenantColumn($entity);
        if ($col === 'vendor_id') {
            $b->where('vendor_id', (int) $vendorId);
        } elseif ($col === 'shop_id') {
            $b->where('shop_id', (int) $shopId);
        }

        $rows   = $b->orderBy('updated_at', 'ASC')->limit($limit)->get()->getResultArray();
        $anchor = $rows === [] ? $since : (string) end($rows)['updated_at'];

        return ['entity' => $entity, 'rows' => $rows, 'next_anchor' => $anchor];
    }

    /** Map the code's logical result to the sync_entities.result ENUM. */
    private const RESULT_DB = ['applied' => 'applied', 'conflict' => 'conflict', 'duplicate' => 'skipped', 'failed' => 'deferred'];

    /**
     * Apply an inbound batch. Idempotent: replaying the same idempotency_key
     * returns the original result without re-applying.
     *
     * @param list<array<string,mixed>> $items
     * @return array{batch_uuid:string,replay:bool,applied:int,conflicts:int,duplicates:int,failed:int,results:list<array<string,mixed>>}
     */
    public function applyBatch(?int $terminalId, string $idempotencyKey, array $items, ?int $actorId = null): array
    {
        $db = Database::connect();

        $prior = $db->table('sync_batches')->where('idempotency_key', $idempotencyKey)->get()->getRowArray();
        if ($prior !== null) {
            return $this->summaryOf((int) $prior['id'], (string) $prior['uuid'], true);
        }

        // Dedup items by (entity_type, entity_uuid) — last occurrence wins — so the same
        // entity appearing twice can't violate uq_sync_entities(entity_type,entity_uuid,batch_id)
        // and abort the whole batch mid-loop.
        $byKey = [];
        foreach ($items as $it) {
            $byKey[((string) ($it['entity_type'] ?? '')) . '|' . ((string) ($it['entity_uuid'] ?? ''))] = $it;
        }
        $items = array_values($byKey);

        $uuid = $this->uuid();
        // direction/status must match the sync_batches ENUMs ('push'/'pull', received|processing|applied|partial|failed).
        try {
            $db->table('sync_batches')->insert([
                'uuid' => $uuid, 'terminal_id' => $terminalId, 'direction' => 'push',
                'item_count' => count($items), 'idempotency_key' => $idempotencyKey,
                'received_at' => date('Y-m-d H:i:s'), 'status' => 'processing',
            ]);
        } catch (Throwable) {
            // A concurrent push with the same idempotency_key won the race — replay it
            // rather than 500 (uq on idempotency_key makes this deterministic).
            $prior = $db->table('sync_batches')->where('idempotency_key', $idempotencyKey)->get()->getRowArray();
            if ($prior !== null) {
                return $this->summaryOf((int) $prior['id'], (string) $prior['uuid'], true);
            }
            throw new \RuntimeException('Could not record the sync batch.');
        }
        $batchId = (int) $db->insertID();

        $applied = $conflicts = $duplicates = $failed = 0;
        $results = [];
        foreach ($items as $item) {
            $r = $this->applyItem($batchId, $item, $terminalId);
            $results[] = $r;
            $applied   += $r['result'] === 'applied' ? 1 : 0;
            $conflicts += $r['result'] === 'conflict' ? 1 : 0;
            $duplicates += $r['result'] === 'duplicate' ? 1 : 0;
            $failed    += $r['result'] === 'failed' ? 1 : 0;
        }

        $db->table('sync_batches')->where('id', $batchId)->update([
            'processed_at' => date('Y-m-d H:i:s'),
            'status'       => $failed > 0 ? 'partial' : 'applied',
        ]);

        return ['batch_uuid' => $uuid, 'replay' => false, 'applied' => $applied, 'conflicts' => $conflicts, 'duplicates' => $duplicates, 'failed' => $failed, 'results' => $results];
    }

    /** @return array{counts:array<string,int>,conflicts_open:int,dead_letter:int,cursors:list<array<string,mixed>>} */
    public function health(): array
    {
        $db = Database::connect();
        $counts = [];
        foreach (['queued', 'reserved', 'processing', 'done', 'failed'] as $s) {
            $counts[$s] = (int) $db->table('jobs')->where('status', $s)->countAllResults();
        }

        return [
            'counts'         => $counts,
            'conflicts_open' => (int) $db->table('sync_conflicts')->where('status', 'open')->countAllResults(),
            'dead_letter'    => (int) $db->table('sync_dead_letter')->where('status', 'open')->countAllResults()
                + (int) $db->table('dead_letter_jobs')->where('status', 'open')->countAllResults(),
            'cursors'        => $db->table('sync_cursors')->orderBy('last_pulled_at', 'DESC')->limit(20)->get()->getResultArray(),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function openConflicts(int $limit = 50): array
    {
        return Database::connect()->table('sync_conflicts')
            ->select('id, uuid, entity_type, entity_uuid, conflict_type, server_version, client_version, resolution, status, created_at')
            ->where('status', 'open')->orderBy('created_at', 'DESC')->limit($limit)
            ->get()->getResultArray();
    }

    public function resolveConflict(int $id, string $resolution, ?int $actorId): bool
    {
        return Database::connect()->table('sync_conflicts')->where('id', $id)->where('status', 'open')->update([
            'resolution' => $resolution, 'resolved_by' => $actorId, 'resolved_at' => date('Y-m-d H:i:s'), 'status' => 'resolved',
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function deadLetters(int $limit = 50): array
    {
        return Database::connect()->table('sync_dead_letter')
            ->select('id, uuid, entity_type, entity_uuid, reason, attempts, status, created_at')
            ->where('status', 'open')->orderBy('created_at', 'DESC')->limit($limit)
            ->get()->getResultArray();
    }

    /** Requeue a dead-lettered item (manual recovery). */
    public function requeueDeadLetter(int $id, ?int $actorId): bool
    {
        $row = Database::connect()->table('sync_dead_letter')->where('id', $id)->where('status', 'open')->get()->getRowArray();
        if ($row === null) {
            return false;
        }
        service('jobQueueRepository')->enqueue('sync', 'sync.replay', ['entity_type' => $row['entity_type'], 'entity_uuid' => $row['entity_uuid'], 'payload' => $row['payload']], 'dlq-' . $row['uuid']);
        // sync_dead_letter.status ENUM is open|retried|resolved|discarded ('requeued' is invalid).
        Database::connect()->table('sync_dead_letter')->where('id', $id)->update(['status' => 'retried', 'updated_by' => $actorId]);

        return true;
    }

    // ---- internals ----
    /** @return array<string,mixed> */
    private function applyItem(int $batchId, array $item, ?int $terminalId = null): array
    {
        $db      = Database::connect();
        $entity  = (string) ($item['entity_type'] ?? '');
        $uuid    = (string) ($item['entity_uuid'] ?? '');
        $op      = $this->normalizeOp((string) ($item['op'] ?? 'update'));
        $clientV = (int) ($item['client_version'] ?? 0);
        $payload = $item['payload'] ?? null;

        if (! $this->registry->isKnown($entity) || $uuid === '') {
            return $this->record($batchId, $entity, $uuid, $op, 'failed', 'unknown entity or missing uuid', $payload);
        }

        // Per-item idempotency: same (entity_uuid, op) already applied earlier?
        $seen = $db->table('sync_entities se')->join('sync_batches sb', 'sb.id = se.batch_id')
            ->where('se.entity_uuid', $uuid)->where('se.entity_type', $entity)->where('se.op', $op)
            ->where('se.result', 'applied')->countAllResults();
        if ($seen > 0) {
            return $this->record($batchId, $entity, $uuid, $op, 'duplicate', null, $payload);
        }

        try {
            $serverV  = $this->serverVersion($this->registry->table($entity), $uuid);
            $decision = $this->resolver->decide($clientV, $serverV, $this->registry->strategy($entity));

            if ($decision['action'] === 'conflict') {
                $conflictId = $this->logConflict($terminalId, $entity, $uuid, $serverV, $clientV, $payload);

                return $this->record($batchId, $entity, $uuid, $op, 'conflict', 'version ' . $clientV . ' < ' . $serverV, $payload, $conflictId);
            }

            // Orchestration records intent + version reconciliation; the entity's
            // own handler performs the field-level write (e.g. PosSyncRepository).
            return $this->record($batchId, $entity, $uuid, $op, 'applied', null, $payload);
        } catch (Throwable $e) {
            return $this->record($batchId, $entity, $uuid, $op, 'failed', 'apply error', $payload);
        }
    }

    /** Map any client op verb to the sync_entities.op ENUM (insert|update|delete). */
    private function normalizeOp(string $op): string
    {
        $op = strtolower($op);
        if (str_contains($op, 'del')) {
            return 'delete';
        }
        if (in_array($op, ['insert', 'create', 'add', 'new'], true)) {
            return 'insert';
        }

        return 'update';
    }

    private function serverVersion(string $table, string $uuid): ?int
    {
        try {
            $row = Database::connect()->table($table)->select('row_version')->where('uuid', $uuid)->get()->getRowArray();

            return $row !== null ? (int) $row['row_version'] : null;
        } catch (Throwable) {
            return null; // table without uuid/row_version → treat as new
        }
    }

    private function logConflict(?int $terminalId, string $entity, string $uuid, ?int $serverV, int $clientV, mixed $payload): int
    {
        $db = Database::connect();
        // sync_conflicts.terminal_id is NOT NULL — must be threaded through from the batch.
        $db->table('sync_conflicts')->insert([
            'uuid' => $this->uuid(), 'terminal_id' => $terminalId, 'entity_type' => $entity, 'entity_uuid' => $uuid,
            'conflict_type' => 'version', 'server_version' => $serverV, 'client_version' => $clientV,
            'payload_client' => is_string($payload) ? $payload : json_encode($payload),
            'resolution' => 'recorded_flagged', 'status' => 'open',
        ]);

        return (int) $db->insertID();
    }

    /** @return array<string,mixed> */
    private function record(int $batchId, string $entity, string $uuid, string $op, string $result, ?string $error, mixed $payload = null, ?int $conflictId = null): array
    {
        // sync_entities requires payload (JSON NOT NULL); result/op must match the ENUMs.
        Database::connect()->table('sync_entities')->insert([
            'batch_id' => $batchId, 'entity_type' => $entity, 'entity_uuid' => $uuid, 'op' => $op,
            'payload' => is_string($payload) ? $payload : json_encode($payload ?? new \stdClass()),
            'result' => self::RESULT_DB[$result] ?? 'deferred', 'conflict_id' => $conflictId, 'error' => $error,
        ]);

        return ['entity_uuid' => $uuid, 'entity_type' => $entity, 'result' => $result, 'error' => $error];
    }

    /** @return array{batch_uuid:string,replay:bool,applied:int,conflicts:int,duplicates:int,failed:int,results:list} */
    private function summaryOf(int $batchId, string $uuid, bool $replay): array
    {
        $rows = Database::connect()->table('sync_entities')->where('batch_id', $batchId)->get()->getResultArray();
        // Map the DB ENUM (applied|conflict|skipped|deferred) back to the API's logical buckets.
        $back    = ['applied' => 'applied', 'conflict' => 'conflict', 'skipped' => 'duplicate', 'deferred' => 'failed'];
        $by      = ['applied' => 0, 'conflict' => 0, 'duplicate' => 0, 'failed' => 0];
        $results = [];
        foreach ($rows as $r) {
            $k = $back[$r['result']] ?? null;
            if ($k !== null) {
                $by[$k]++;
            }
            // Replay returns the same per-item shape as the original response.
            $results[] = ['entity_uuid' => $r['entity_uuid'], 'entity_type' => $r['entity_type'], 'result' => $k ?? $r['result'], 'error' => $r['error']];
        }

        return ['batch_uuid' => $uuid, 'replay' => $replay, 'applied' => $by['applied'], 'conflicts' => $by['conflict'], 'duplicates' => $by['duplicate'], 'failed' => $by['failed'], 'results' => $results];
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
