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
 *     'scopes'      => [['type'=>'platform|vendor|shop|manufacturer|mshop|self', 'id'=>?int], ...],
 *     'attributes'  => array,                          // caps (discount_cap, ...)
 *   ]
 * Target shape (any subset): ['vendor_id'=>?, 'shop_id'=>?, 'mshop_id'=>?, 'owner_id'=>?]
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

    /**
     * RBAC gate for PLATFORM-WIDE pages: hold the code AND be scoped to the platform.
     *
     * can() is deliberately scope-blind, which is correct for the vendor and rider
     * panels where the tenant is established elsewhere. It is not sufficient for
     * /admin/*: those routes are path-based (so they resolve on vendor.<domain>,
     * where a vendor's session cookie is already sent), and database/sql/11_seed.sql
     * grants every vendor/shop-scoped permission to vendor_owner — including codes the
     * admin panel guards on, such as settlement.view, product.update, media.view,
     * report.export and transfer.approve. Without the scope test, holding the code was
     * the whole gate, and an ordinary vendor owner could read and write every tenant's
     * data. Admin\PortalController already spelled this check out longhand; this makes
     * it available to the rest of the panel as one call.
     */
    public function canPlatform(array $ctx, string $permission): bool
    {
        if (! $this->can($ctx, $permission)) {
            return false;
        }

        foreach ($ctx['scopes'] ?? [] as $scope) {
            if (($scope['type'] ?? null) === 'platform') {
                return true;
            }
        }

        return false;
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
        // mshop_id belongs in this list for the same reason shop_id does: a key that is
        // not recognised here sets no constraint, so the method concludes "unscoped
        // target" and allows it outright. Omitting it made every mshop-keyed target
        // fail OPEN to any actor, which is the opposite of the deny-by-default the rest
        // of this method is built on.
        $hasConstraint = array_key_exists('vendor_id', $target)
            || array_key_exists('shop_id', $target)
            || array_key_exists('mshop_id', $target)
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

                // A manufacturer IS a `vendors` row (party_type='manufacturer'), so its
                // data carries vendor_id — products.vendor_id, mfg_purchase_orders
                // .seller_vendor_id. Matching vendor_id here is therefore the same test
                // the 'vendor' case makes, against the same unique id space; it cannot
                // cross parties because vendors.id identifies exactly one row.
                case 'manufacturer':
                    if (array_key_exists('vendor_id', $target)
                        && (int) $target['vendor_id'] === (int) ($scope['id'] ?? -1)) {
                        return true;
                    }
                    break;

                // Deliberately NOT satisfied by a 'manufacturer' scope: owning the
                // manufacturer is not the same as being assigned to a unit, exactly as
                // owning a vendor does not grant a branch. requireMshopAccess() draws
                // that line in the controller and this must not undercut it.
                case 'mshop':
                    if (array_key_exists('mshop_id', $target)
                        && (int) $target['mshop_id'] === (int) ($scope['id'] ?? -1)) {
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
