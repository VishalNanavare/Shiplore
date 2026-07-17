<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * ImportJobRepository — read-only view of bulk import jobs (product uploads).
 *
 * @see docs/architecture/24-ADMIN-PANEL.md (Bulk Product Handling)
 */
final class ImportJobRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('import_jobs j')
            ->select('j.id, j.type, j.total_rows, j.processed_rows, j.error_rows, j.status, j.created_at, u.name AS requested_by')
            ->join('users u', 'u.id = j.requested_by', 'left')
            ->orderBy('j.created_at', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('j.status', $status);
        }

        return $builder->get()->getResultArray();
    }

    public function create(string $type, ?int $actorId, int $totalRows): int
    {
        $db = Database::connect();
        $db->table('import_jobs')->insert([
            'uuid' => bin2hex(random_bytes(18)), 'type' => $type, 'total_rows' => $totalRows,
            'processed_rows' => 0, 'error_rows' => 0, 'requested_by' => $actorId,
            'status' => 'processing', 'created_by' => $actorId,
        ]);

        return (int) $db->insertID();
    }

    /** @param array<string,mixed> $raw @param list<string> $errors */
    public function addRow(int $jobId, int $rowNo, array $raw, string $status, array $errors): void
    {
        Database::connect()->table('import_rows')->insert([
            'import_job_id' => $jobId, 'row_no' => $rowNo,
            'raw' => json_encode($raw), 'errors' => $errors === [] ? null : json_encode($errors),
            'status' => $status,
        ]);
    }

    public function finalize(int $jobId, int $processed, int $errors, string $status): void
    {
        Database::connect()->table('import_jobs')->where('id', $jobId)->update([
            'processed_rows' => $processed, 'error_rows' => $errors, 'status' => $status,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('import_jobs')->where('id', $id)->get()->getRowArray();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function rows(int $jobId, int $limit = 500): array
    {
        return Database::connect()->table('import_rows')
            ->select('row_no, raw, errors, status')
            ->where('import_job_id', $jobId)->orderBy('row_no', 'ASC')->limit($limit)
            ->get()->getResultArray();
    }
}
