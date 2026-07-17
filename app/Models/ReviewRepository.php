<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * ReviewRepository — admin review moderation. Lists product reviews (joined to
 * the product) and transitions status (publish / reject / flag). Fail-safe.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md (Review Moderation)
 */
final class ReviewRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('reviews r')
            ->select('r.id, r.rating, r.title, r.body, r.is_verified_purchase, r.status, r.created_at, p.title AS product')
            ->join('products p', 'p.id = r.product_id', 'left')
            ->where('r.deleted_at', null)
            ->orderBy('r.created_at', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('r.status', $status);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('reviews')
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('reviews')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }
}
