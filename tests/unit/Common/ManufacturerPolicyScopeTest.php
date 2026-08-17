<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/PolicyEngine.php';

use App\Libraries\PolicyEngine;

/**
 * PolicyEngine must understand the `manufacturer` and `mshop` scope types.
 *
 * `permissions/roles/user_roles` gained both scope classes in
 * database/sql/70_manufacturer.sql, and ManufacturerRegistrationRepository writes
 * scope_type='manufacturer' — but PolicyEngine::scopeAllows() only ever switched on
 * platform/vendor/shop/self. That left two defects pulling in opposite directions:
 *
 *  1. deny-by-default — a manufacturer-scoped actor failed EVERY authorize() call
 *     carrying a vendor_id target, because no case matched and the method fell through
 *     to `return false`. The panel only survived because it exclusively uses can()
 *     (pure RBAC) and never authorize().
 *  2. fail-OPEN — a target keyed only on mshop_id set no constraint at all, so
 *     scopeAllows() returned true for anyone, including an unrelated tenant.
 *
 * The second is the dangerous one and is why mshop_id has to join the constraint list
 * in the same change, not a later one.
 *
 * A manufacturer is a row in `vendors` (discriminated by party_type), so manufacturer
 * data carries vendor_id — products.vendor_id and mfg_purchase_orders.seller_vendor_id
 * both do. That is why a `manufacturer` scope matches a vendor_id target: the id spaces
 * are the same table. Cross-party confusion is impossible here because vendors.id is
 * unique — a manufacturer scope of id 7 can only ever match vendors row 7, which is
 * that same manufacturer.
 */
final class ManufacturerPolicyScopeTest extends TestCase
{
    /** @param list<array{type:string,id:int|null}> $scopes */
    private function ctx(array $scopes, array $permissions = ['mfg.product.update']): array
    {
        return [
            'actor_id'    => 42,
            'permissions' => $permissions,
            'scopes'      => $scopes,
            'attributes'  => [],
        ];
    }

    // ---------------------------------------------------------- manufacturer scope

    public function testManufacturerScopeAuthorizesItsOwnTenant(): void
    {
        $pe  = new PolicyEngine();
        $ctx = $this->ctx([['type' => 'manufacturer', 'id' => 7]]);

        $this->assertTrue(
            $pe->authorize($ctx, 'mfg.product.update', ['vendor_id' => 7]),
            'a manufacturer-scoped actor must be authorized on its own tenant row',
        );
    }

    public function testManufacturerScopeIsDeniedAnotherTenant(): void
    {
        $pe  = new PolicyEngine();
        $ctx = $this->ctx([['type' => 'manufacturer', 'id' => 7]]);

        $this->assertFalse($pe->authorize($ctx, 'mfg.product.update', ['vendor_id' => 8]));
    }

    // ----------------------------------------------------------------- mshop scope

    public function testMshopScopeAuthorizesItsOwnUnit(): void
    {
        $pe  = new PolicyEngine();
        $ctx = $this->ctx([['type' => 'mshop', 'id' => 3]], ['mfg.inventory.adjust']);

        $this->assertTrue($pe->authorize($ctx, 'mfg.inventory.adjust', ['mshop_id' => 3]));
    }

    public function testMshopScopeIsDeniedAnotherUnit(): void
    {
        $pe  = new PolicyEngine();
        $ctx = $this->ctx([['type' => 'mshop', 'id' => 3]], ['mfg.inventory.adjust']);

        $this->assertFalse(
            $pe->authorize($ctx, 'mfg.inventory.adjust', ['mshop_id' => 4]),
            'a store keeper assigned to unit 3 must not act on unit 4',
        );
    }

    /**
     * The fail-open case. Before mshop_id counted as a constraint, this returned true:
     * no recognised key was present, so scopeAllows() concluded "unscoped target" and
     * allowed it outright.
     */
    public function testMshopTargetIsNotTreatedAsUnscoped(): void
    {
        $pe  = new PolicyEngine();
        $ctx = $this->ctx([['type' => 'vendor', 'id' => 1]], ['mfg.inventory.adjust']);

        $this->assertFalse(
            $pe->authorize($ctx, 'mfg.inventory.adjust', ['mshop_id' => 99]),
            'an mshop_id target must constrain — otherwise any actor passes',
        );
    }

    // ------------------------------------------------------------- no regressions

    public function testPlatformScopeStillCrossesEveryTenant(): void
    {
        $pe  = new PolicyEngine();
        $ctx = $this->ctx([['type' => 'platform', 'id' => null]]);

        $this->assertTrue($pe->authorize($ctx, 'mfg.product.update', ['vendor_id' => 7]));
        $this->assertTrue($pe->authorize($ctx, 'mfg.product.update', ['mshop_id' => 3]));
    }

    public function testVendorAndShopScopesAreUnchanged(): void
    {
        $pe = new PolicyEngine();

        $vendor = $this->ctx([['type' => 'vendor', 'id' => 1]], ['order.view.own']);
        $this->assertTrue($pe->authorize($vendor, 'order.view.own', ['vendor_id' => 1]));
        $this->assertFalse($pe->authorize($vendor, 'order.view.own', ['vendor_id' => 2]));

        $shop = $this->ctx([['type' => 'shop', 'id' => 10]], ['inventory.view']);
        $this->assertTrue($pe->authorize($shop, 'inventory.view', ['shop_id' => 10]));
        $this->assertFalse($pe->authorize($shop, 'inventory.view', ['shop_id' => 11]));
    }

    /** A manufacturer scope must not stand in for an mshop scope, or unit isolation is moot. */
    public function testManufacturerScopeDoesNotSatisfyAUnitTarget(): void
    {
        $pe  = new PolicyEngine();
        $ctx = $this->ctx([['type' => 'manufacturer', 'id' => 7]], ['mfg.inventory.adjust']);

        $this->assertFalse(
            $pe->authorize($ctx, 'mfg.inventory.adjust', ['mshop_id' => 3]),
            'owning the manufacturer is not the same as being assigned to the unit; '
            . 'BaseManufacturerController::requireMshopAccess() draws that line and '
            . 'PolicyEngine must not undercut it',
        );
    }

    /** RBAC still comes first: the right scope with the wrong permission is a deny. */
    public function testScopeAloneDoesNotGrantAMissingPermission(): void
    {
        $pe  = new PolicyEngine();
        $ctx = $this->ctx([['type' => 'manufacturer', 'id' => 7]], ['mfg.product.view']);

        $this->assertFalse($pe->authorize($ctx, 'mfg.product.update', ['vendor_id' => 7]));
    }
}
