<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * ExportJobRepository — wires the (previously dormant) export_jobs table
 * into the async report engine (X5). Creating a job also enqueues an
 * `export.run` job on the durable queue drained by sync:work.
 */
final class ExportJobRepository
{
    public function create(string $type, array $params, ?int $requestedBy): int
    {
        $db = Database::connect();
        $db->table('export_jobs')->insert([
            'uuid' => $this->uuid(), 'type' => $type, 'params' => json_encode($params),
            'requested_by' => $requestedBy, 'status' => 'queued', 'created_by' => $requestedBy,
        ]);
        $id = (int) $db->insertID();

        service('jobQueueRepository')->enqueue('export', 'export.run', ['export_job_id' => $id], 'export-' . $id);

        return $id;
    }

    public function find(int $id): ?array
    {
        $row = Database::connect()->table('export_jobs')->where('id', $id)->get()->getRowArray();
        if ($row !== null && isset($row['params']) && is_string($row['params'])) {
            $row['params'] = json_decode($row['params'], true) ?: [];
        }

        return $row;
    }

    public function markProcessing(int $id): void
    {
        Database::connect()->table('export_jobs')->where('id', $id)->update(['status' => 'processing']);
    }

    public function markCompleted(int $id, int $mediaId, int $rowCount): void
    {
        Database::connect()->table('export_jobs')->where('id', $id)
            ->update(['status' => 'completed', 'output_media_id' => $mediaId, 'row_count' => $rowCount]);
    }

    public function markFailed(int $id): void
    {
        Database::connect()->table('export_jobs')->where('id', $id)->update(['status' => 'failed']);
    }

    /** @return list<array<string,mixed>> register incl. media uuid for download */
    public function list(int $limit = 100): array
    {
        return Database::connect()->table('export_jobs e')
            ->select('e.id, e.type, e.status, e.row_count, e.created_at, u.name AS requested_by_name, m.uuid AS media_uuid')
            ->join('users u', 'u.id = e.requested_by', 'left')
            ->join('media_assets m', 'm.id = e.output_media_id', 'left')
            ->orderBy('e.id', 'DESC')->limit($limit)->get()->getResultArray();
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0F | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
