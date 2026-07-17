<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * AttributeRepository — admin-governed product attributes (size, color, …) used
 * to define variants. Lists attributes and toggles active/inactive.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md (Attribute Management)
 */
final class AttributeRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('attributes')
            ->select('id, code, name, type, is_variant_defining, status')
            ->where('deleted_at', null)
            ->orderBy('id', 'ASC');

        if ($status !== null && $status !== '') {
            $builder->where('status', $status);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('attributes')
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('attributes')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }
}
