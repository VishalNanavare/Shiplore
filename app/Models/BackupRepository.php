<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * BackupRepository — read-only view of backup / restore run logs.
 *
 * @see docs/architecture/32-OPS-EXPANSION-SEO-DR.md
 */
final class BackupRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('backup_logs')
            ->select('id, type, scope, status, size_bytes, location, started_at, finished_at, notes, created_at')
            ->orderBy('id', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('status', $status);
        }

        return $builder->get()->getResultArray();
    }
}
