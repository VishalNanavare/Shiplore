<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * PromotionRepository — admin oversight of promotions (vendor or platform
 * funded). Lists promotions with the owning vendor and toggles active/paused.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md
 */
final class PromotionRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('promotions p')
            ->select('p.id, p.name, p.type, p.value, p.funded_by, p.valid_from, p.valid_to, p.status, v.display_name AS vendor')
            ->join('vendors v', 'v.id = p.vendor_id', 'left')
            ->where('p.deleted_at', null)
            ->orderBy('p.id', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('p.status', $status);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('promotions')
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('promotions')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }
}
