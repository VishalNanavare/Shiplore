<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * CommissionRepository — admin management of commission plans (the configurable
 * rate definitions that drive the Product > Subcategory > Category >
 * BusinessType > Global hierarchy). Lists plans and toggles active/inactive.
 *
 * @see docs/architecture/31-BUSINESS-TYPE-COMMISSION.md
 */
final class CommissionRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('commission_plans')
            ->select('id, code, name, type, default_rate, base, valid_from, valid_to, status')
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
        $row = Database::connect()->table('commission_plans')
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('commission_plans')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }
}
