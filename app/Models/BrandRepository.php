<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * BrandRepository — admin-governed brands. Lists brands and transitions status
 * (approve pending -> active, deactivate). Fail-safe.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md
 */
final class BrandRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('brands')
            ->select('id, name, slug, status, created_at')
            ->where('deleted_at', null)
            ->orderBy('name', 'ASC');

        if ($status !== null && $status !== '') {
            $builder->where('status', $status);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('brands')
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('brands')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }
}
