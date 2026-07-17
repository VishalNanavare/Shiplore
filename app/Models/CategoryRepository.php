<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * CategoryRepository — admin-governed category tree. Lists categories with their
 * parent and the business types they are mapped to (the mapping that enforces
 * "footwear sellers can't see grocery categories"), and toggles active/inactive.
 *
 * @see docs/architecture/31-BUSINESS-TYPE-COMMISSION.md
 */
final class CategoryRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('categories c')
            ->select('c.id, c.name, c.slug, c.level, c.parent_id, c.default_commission_rate, c.status, pc.name AS parent')
            ->select('(SELECT GROUP_CONCAT(bt.name SEPARATOR ", ") FROM business_type_category_map m JOIN business_types bt ON bt.id = m.business_type_id WHERE m.category_id = c.id) AS business_types', false)
            ->join('categories pc', 'pc.id = c.parent_id', 'left')
            ->where('c.deleted_at', null)
            ->orderBy('c.path', 'ASC');

        if ($status !== null && $status !== '') {
            $builder->where('c.status', $status);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('categories')
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('categories')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'is_active' => $status === 'active' ? 1 : 0, 'updated_by' => $actorId]);
    }

    /** @return list<array<string,mixed>> id+name for the parent select. */
    public function options(): array
    {
        return Database::connect()->table('categories')
            ->select('id, name, level')->where('deleted_at', null)->orderBy('path', 'ASC')
            ->get()->getResultArray();
    }

    /** @param array<string,mixed> $d @return int|null new id (path/level derived from parent) */
    public function create(array $d, ?int $actorId = null): ?int
    {
        $db = Database::connect();
        $parentId = (int) ($d['parent_id'] ?? 0) ?: null;
        $level = 0;
        $base  = '/';
        if ($parentId !== null) {
            $p = $db->table('categories')->select('level, path')->where('id', $parentId)->get()->getRowArray();
            if ($p !== null) {
                $level = (int) $p['level'] + 1;
                $base  = $p['path'] ?: '/';
            }
        }
        $db->table('categories')->insert([
            'uuid' => bin2hex(random_bytes(18)), 'parent_id' => $parentId,
            'name' => mb_substr((string) $d['name'], 0, 191), 'slug' => $this->slug((string) $d['name']),
            'path' => '/', 'level' => $level,
            'default_commission_rate' => ($d['default_commission_rate'] ?? '') !== '' ? $d['default_commission_rate'] : null,
            'sort_order' => (int) ($d['sort_order'] ?? 0), 'is_active' => 1, 'status' => 'active', 'created_by' => $actorId,
        ]);
        $id = (int) $db->insertID();
        if ($id <= 0) {
            return null;
        }
        $db->table('categories')->where('id', $id)->update(['path' => rtrim($base, '/') . '/' . $id . '/']);

        return $id;
    }

    /** @param array<string,mixed> $d */
    public function update(int $id, array $d, ?int $actorId = null): bool
    {
        return Database::connect()->table('categories')->where('id', $id)->where('deleted_at', null)->update([
            'name' => mb_substr((string) $d['name'], 0, 191),
            'default_commission_rate' => ($d['default_commission_rate'] ?? '') !== '' ? $d['default_commission_rate'] : null,
            'sort_order' => (int) ($d['sort_order'] ?? 0), 'updated_by' => $actorId,
        ]);
    }

    private function slug(string $name): string
    {
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

        return ($s === '' ? 'category' : $s) . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
    }
}
