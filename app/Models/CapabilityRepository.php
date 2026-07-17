<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * CapabilityRepository — loads a user's scoped role assignments from the DB
 * (user_roles → role_permissions → permissions), grouped per scope, in the
 * exact shape CapabilityResolver expects. Used by JwtAuthFilter's loader.
 */
final class CapabilityRepository
{
    /** @return list<array{permissions:list<string>,scope_type:string,scope_id:?int,attributes:array}> */
    public function loadAssignments(int $userId): array
    {
        $db = Database::connect();

        $rows = $db->table('user_roles ur')
            ->select('ur.scope_type, ur.scope_id, ur.attributes, p.code')
            ->join('role_permissions rp', 'rp.role_id = ur.role_id', 'left')
            ->join('permissions p', 'p.id = rp.permission_id', 'left')
            ->where('ur.user_id', $userId)
            ->where('ur.deleted_at', null)
            ->get()
            ->getResultArray();

        $byScope = [];
        foreach ($rows as $r) {
            $key = $r['scope_type'] . ':' . ($r['scope_id'] ?? 'null');
            if (! isset($byScope[$key])) {
                $byScope[$key] = [
                    'permissions' => [],
                    'scope_type'  => $r['scope_type'],
                    'scope_id'    => $r['scope_id'] !== null ? (int) $r['scope_id'] : null,
                    'attributes'  => ! empty($r['attributes']) ? (json_decode($r['attributes'], true) ?: []) : [],
                ];
            }
            if (! empty($r['code'])) {
                $byScope[$key]['permissions'][] = $r['code'];
            }
        }

        return array_values($byScope);
    }
}
