<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * BusinessTypeRepository — admin-governed business types (footwear, grocery, …).
 * Each gates which categories a vendor of that type may use. Lists types with
 * their mapped-category count + default commission, and toggles active/inactive.
 *
 * @see docs/architecture/31-BUSINESS-TYPE-COMMISSION.md
 */
final class BusinessTypeRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('business_types bt')
            ->select('bt.id, bt.code, bt.name, bt.default_commission_rate, bt.status')
            ->select('(SELECT COUNT(*) FROM business_type_category_map m WHERE m.business_type_id = bt.id) AS category_count', false)
            ->where('bt.deleted_at', null)
            ->orderBy('bt.id', 'ASC');

        if ($status !== null && $status !== '') {
            $builder->where('bt.status', $status);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('business_types')
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('business_types')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }

    /** @return list<array<string,mixed>> Active categories mapped to this business type. */
    public function categoriesFor(int $businessTypeId): array
    {
        if ($businessTypeId <= 0) {
            return [];
        }

        return Database::connect()->table('business_type_category_map m')
            ->select('c.id, c.name, c.slug, c.parent_id, p.name AS parent_name')
            ->join('categories c', 'c.id = m.category_id', 'inner')
            ->join('categories p', 'p.id = c.parent_id', 'left')
            ->where('m.business_type_id', $businessTypeId)
            ->where('c.status', 'active')->where('c.deleted_at', null)
            ->orderBy('p.name IS NULL', 'DESC', false)->orderBy('p.name')->orderBy('c.name')
            ->get()->getResultArray();
    }

    /**
     * Replace all category mappings for a business type atomically.
     * Only category IDs that actually exist in the `categories` table are kept.
     *
     * @param list<int> $categoryIds
     *
     * @return bool false when the transaction failed and nothing was written. This
     *         used to return void, so the caller reported success unconditionally —
     *         a failed sync silently left the old mapping in place while the admin
     *         was told the new one had been saved.
     */
    public function syncCategories(int $businessTypeId, array $categoryIds): bool
    {
        $db = Database::connect();
        $categoryIds = array_map('intval', array_filter($categoryIds));

        $validIds = [];
        if ($categoryIds !== []) {
            $rows     = $db->table('categories')->select('id')->whereIn('id', $categoryIds)->where('deleted_at', null)->get()->getResultArray();
            $validIds = array_column($rows, 'id');
        }

        $db->transStart();
        $db->table('business_type_category_map')->where('business_type_id', $businessTypeId)->delete();
        foreach ($validIds as $catId) {
            $db->table('business_type_category_map')->insert(['business_type_id' => $businessTypeId, 'category_id' => (int) $catId]);
        }
        $db->transComplete();

        return $db->transStatus();
    }
}
