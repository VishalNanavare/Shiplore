<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * CapabilityResolver — assembles the PolicyEngine context from an actor's
 * role assignments (fed by a repository from user_roles + role_permissions +
 * vendor_staff + staff_shop_assignments). DB-agnostic and pure.
 *
 * Each assignment: ['permissions'=>string[], 'scope_type'=>string, 'scope_id'=>?int, 'attributes'=>array]
 * Output: ['actor_id'=>int, 'permissions'=>string[], 'scopes'=>[['type','id']], 'attributes'=>array]
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §5–6
 */
final class CapabilityResolver
{
    public function resolve(int $actorId, array $assignments): array
    {
        $permSet   = [];
        $scopes    = [];
        $seenScope = [];
        $attrs     = [];

        foreach ($assignments as $a) {
            foreach ($a['permissions'] ?? [] as $perm) {
                $permSet[$perm] = true;
            }

            $type = $a['scope_type'] ?? null;
            if ($type !== null) {
                $id  = $a['scope_id'] ?? null;
                $key = $type . ':' . var_export($id, true);
                if (! isset($seenScope[$key])) {
                    $seenScope[$key] = true;
                    $scopes[]        = ['type' => $type, 'id' => $id];
                }
            }

            foreach ($a['attributes'] ?? [] as $k => $v) {
                if (! array_key_exists($k, $attrs)) {
                    $attrs[$k] = $v;
                } elseif (is_numeric($v) && is_numeric($attrs[$k])) {
                    $attrs[$k] = max($attrs[$k], $v); // widest cap wins
                } else {
                    $attrs[$k] = $v;
                }
            }
        }

        $permissions = array_keys($permSet);
        sort($permissions);

        return [
            'actor_id'    => $actorId,
            'permissions' => $permissions,
            'scopes'      => $scopes,
            'attributes'  => $attrs,
        ];
    }
}
