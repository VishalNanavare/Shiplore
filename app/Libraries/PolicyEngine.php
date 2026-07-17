<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * PolicyEngine — central RBAC + ABAC authorization resolver.
 *
 * Pure, deterministic, dependency-free: given an actor capability context and
 * a target, decides allow/deny. RBAC ("what action?") via the permission set;
 * ABAC ("on whose data?") via vendor/shop/self scope matching.
 *
 * Context shape:
 *   [
 *     'actor_id'    => int,
 *     'permissions' => string[],                       // resolved permission codes
 *     'scopes'      => [['type'=>'platform|vendor|shop|self', 'id'=>?int], ...],
 *     'attributes'  => array,                          // caps (discount_cap, ...)
 *   ]
 * Target shape (any subset): ['vendor_id'=>?, 'shop_id'=>?, 'owner_id'=>?]
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §6
 */
final class PolicyEngine
{
    /** RBAC gate: does the actor hold this permission code? */
    public function can(array $ctx, string $permission): bool
    {
        return in_array($permission, $ctx['permissions'] ?? [], true);
    }

    /** RBAC + ABAC: hold the permission AND be in scope for the target. */
    public function authorize(array $ctx, string $permission, array $target = []): bool
    {
        if (! $this->can($ctx, $permission)) {
            return false;
        }

        return $this->scopeAllows($ctx, $target);
    }

    /** ABAC scope check. No tenant constraint on the target => RBAC alone governs. */
    private function scopeAllows(array $ctx, array $target): bool
    {
        $hasConstraint = array_key_exists('vendor_id', $target)
            || array_key_exists('shop_id', $target)
            || array_key_exists('owner_id', $target);

        if (! $hasConstraint) {
            return true;
        }

        $actorId = $ctx['actor_id'] ?? null;

        foreach ($ctx['scopes'] ?? [] as $scope) {
            switch ($scope['type'] ?? null) {
                case 'platform':
                    return true; // cross-vendor

                case 'vendor':
                    if (array_key_exists('vendor_id', $target)
                        && (int) $target['vendor_id'] === (int) ($scope['id'] ?? -1)) {
                        return true;
                    }
                    break;

                case 'shop':
                    if (array_key_exists('shop_id', $target)
                        && (int) $target['shop_id'] === (int) ($scope['id'] ?? -1)) {
                        return true;
                    }
                    break;

                case 'self':
                    if (array_key_exists('owner_id', $target)
                        && $actorId !== null
                        && (int) $target['owner_id'] === (int) $actorId) {
                        return true;
                    }
                    break;
            }
        }

        return false; // deny-by-default; out-of-scope => caller maps to 404
    }
}
