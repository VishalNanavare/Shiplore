<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * RoleRepository — roles + their permission assignments (RBAC management).
 *
 * @see docs/architecture/41-SECURITY-PERFORMANCE.md
 */
final class RoleRepository
{
    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return Database::connect()->table('roles r')
            ->select('r.id, r.code, r.name, r.scope_class, r.is_system, r.status')
            ->select('(SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perms', false)
            ->where('r.deleted_at', null)->orderBy('r.id', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = Database::connect()->table('roles')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();

        return $row ?: null;
    }

    /** @return list<int> assigned permission ids */
    public function assignedPermissionIds(int $roleId): array
    {
        return array_map('intval', array_column(
            Database::connect()->table('role_permissions')->select('permission_id')->where('role_id', $roleId)->get()->getResultArray(),
            'permission_id'
        ));
    }

    /** @return array<string,list<array<string,mixed>>> permissions grouped by module */
    public function permissionsByModule(): array
    {
        $rows = Database::connect()->table('permissions')->select('id, code, module, description')->orderBy('module')->orderBy('code')->get()->getResultArray();
        $out  = [];
        foreach ($rows as $r) {
            $out[$r['module'] ?: 'other'][] = $r;
        }

        return $out;
    }

    /** Replace a role's permission set. @param list<int> $permissionIds */
    public function syncPermissions(int $roleId, array $permissionIds): void
    {
        $db = Database::connect();
        $db->table('role_permissions')->where('role_id', $roleId)->delete();
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach (array_unique(array_map('intval', $permissionIds)) as $pid) {
            if ($pid > 0) {
                $rows[] = ['role_id' => $roleId, 'permission_id' => $pid, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($rows !== []) {
            $db->table('role_permissions')->insertBatch($rows);
        }
    }
}
