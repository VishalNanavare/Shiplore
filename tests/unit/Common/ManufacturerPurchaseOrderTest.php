<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Manufacturer\PurchaseOrderController — the SELLER side of monline.
 *
 * accept / reject / pack / dispatch was fully implemented but had zero test coverage of
 * any kind: not even ManufacturerPanelIsolationTest's generic "must resolve a
 * manufacturer" sweep reached it, because that test enumerated its controllers by name
 * and this one was missing from the list.
 *
 * Source-scanning, in the same style as MonlineB2bTest — these are structural guards on
 * code that cannot be exercised without a migrated test database.
 */
final class ManufacturerPurchaseOrderTest extends CIUnitTestCase
{
    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

    private function controller(): string
    {
        return $this->read('Controllers/Manufacturer/PurchaseOrderController.php');
    }

    /** Only the four legal actions may reach the repository. */
    public function testTransitionOnlyAllowsTheLegalActionSet(): void
    {
        $body = $this->methodBody($this->controller(), 'transition');

        foreach (['accept' => 'accepted', 'reject' => 'rejected', 'pack' => 'packed', 'dispatch' => 'dispatched'] as $action => $status) {
            $this->assertStringContainsString("'{$action}'", $body, "transition() must handle the '{$action}' action");
            $this->assertStringContainsString("'{$status}'", $body, "'{$action}' must map to '{$status}'");
        }

        $this->assertStringContainsString('if (! isset($map[$action]))', $body, 'an unmapped action must be rejected');

        // The unknown-action guard must run BEFORE anything is handed to the repository.
        $guard    = strpos($body, 'if (! isset($map[$action]))');
        $repoCall = strpos($body, "service('purchaseOrderRepository')->transition(");
        $this->assertNotFalse($guard);
        $this->assertNotFalse($repoCall);
        $this->assertLessThan($repoCall, $guard, 'an arbitrary action string must be refused before the repository is called');
    }

    /**
     * The fulfilling unit must be one this user may act on.
     *
     * Without requireMshopAccess() a store keeper assigned to unit A could accept an
     * order against unit B's stock — the seller-side mirror of the buyer-side shop check
     * in Monline\OrderController::place().
     */
    public function testAcceptScopesTheFulfillingUnitToAllowedUnitsOnly(): void
    {
        $body = $this->methodBody($this->controller(), 'transition');

        $this->assertStringContainsString("\$this->request->getPost('seller_mshop_id')", $body);
        $this->assertStringContainsString('requireMshopAccess', $body, 'the chosen unit must be access-checked');

        $check = strpos($body, 'requireMshopAccess');
        $trust = strpos($body, "\$extra['seller_mshop_id']");
        $this->assertNotFalse($check);
        $this->assertNotFalse($trust);
        $this->assertLessThan($trust, $check, 'seller_mshop_id must pass requireMshopAccess() before it is trusted');
    }

    /**
     * EVERY transition must check the unit, not just accept.
     *
     * The unit check lived only inside the `accept` branch, and only fired when a unit
     * was posted. Once a PO was accepted against unit B, its seller_mshop_id is stored on
     * the row — and pack, dispatch and reject re-read it with no check at all. Dispatch
     * calls shipStockForPo(), which decrements the FULFILLING unit's mfg_inventory. So a
     * store keeper assigned to unit A could ship unit B's stock, which is exactly what
     * the unit-isolation model exists to prevent.
     *
     * Asserted structurally: the guard must sit BEFORE the branch that special-cases
     * accept, so it covers every action rather than one.
     */
    public function testEveryTransitionChecksTheUnitNotJustAccept(): void
    {
        $body = $this->methodBody($this->controller(), 'transition');

        $this->assertStringContainsString(
            'requireUnitOnPo',
            $body,
            'every transition must verify the PO’s assigned unit, not only the one posted on accept',
        );

        $unitGuard  = strpos($body, 'requireUnitOnPo');
        $acceptOnly = strpos($body, "if (\$action === 'accept')");
        $this->assertNotFalse($unitGuard);
        $this->assertNotFalse($acceptOnly);
        $this->assertLessThan(
            $acceptOnly,
            $unitGuard,
            'the unit guard must run for ALL actions — placing it inside the accept branch is the original bug',
        );
    }

    /**
     * The LIST is unit-scoped too, or the guard above only hides the door.
     *
     * listForSeller() had no unit parameter at all, unlike its sibling listForBuyer(),
     * which has taken $shopIds since it was written. Guarding the detail and transition
     * pages while still listing every plant's orders would leave the ids on screen and
     * the isolation only one URL away.
     */
    public function testTheOrderListIsScopedToTheUsersUnits(): void
    {
        $body = $this->methodBody($this->read('Models/PurchaseOrderRepository.php'), 'listForSeller');

        $this->assertNotSame('', $body, 'listForSeller() not found');
        $this->assertStringContainsString('$mshopIds', $body, 'listForSeller() must accept a unit scope');
        $this->assertMatchesRegularExpression(
            '/if \(\$mshopIds !== null\)[\s\S]{0,200}?whereIn\(\'po\.seller_mshop_id\'/',
            $body,
            'the unit scope must actually constrain the query',
        );
        // A pending order has no unit yet and must stay visible, or nobody can accept it.
        $this->assertStringContainsString(
            "orWhere('po.seller_mshop_id', null)",
            $body,
            'unassigned orders must remain listable',
        );
    }

    /** Owners span every unit; staff are handed only their own. */
    public function testTheControllerPassesTheUnitScopeForStaffOnly(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$this->isOwner\(\)\s*\?\s*null\s*:\s*\$this->allowedMshopIds\(\)/',
            $this->methodBody($this->controller(), 'index'),
            'staff must be scoped to allowedMshopIds(); passing null unconditionally is the bug',
        );
    }

    /** Reading one PO is unit-scoped too, so a foreign unit's order is not even visible. */
    public function testShowIsAlsoUnitScoped(): void
    {
        $this->assertStringContainsString(
            'requireUnitOnPo',
            $this->methodBody($this->controller(), 'show'),
            'a unit-scoped user must not read another unit’s purchase order',
        );
    }

    /**
     * The helper must FAIL CLOSED on an unassigned PO only when the user is unit-scoped.
     *
     * A PO that has not been accepted yet has no seller_mshop_id, and every unit of the
     * manufacturer is still a legitimate candidate — refusing there would make it
     * impossible to accept anything. An owner spanning all units must likewise pass.
     */
    public function testTheUnitGuardAllowsAnUnassignedOrder(): void
    {
        $body = $this->methodBody($this->controller(), 'requireUnitOnPo');

        $this->assertNotSame('', $body, 'requireUnitOnPo() not found');
        $this->assertStringContainsString(
            'seller_mshop_id',
            $body,
            'the guard must read the unit already stored on the PO',
        );
        $this->assertMatchesRegularExpression(
            '/<=\s*0\)\s*\{\s*return null;/s',
            $body,
            'an unassigned PO must pass — it has no unit to check yet',
        );
    }

    /** A rejection reason is captured, length-capped, and stored on the PO. */
    public function testRejectCapturesTheReason(): void
    {
        $body = $this->methodBody($this->controller(), 'transition');

        $this->assertStringContainsString("\$this->request->getPost('reject_reason')", $body);
        $this->assertStringContainsString("\$extra['reject_reason']", $body);
        $this->assertStringContainsString('mb_substr($reason, 0, 255)', $body, 'the reason must be capped to the column width');

        $schema = (string) file_get_contents(ROOTPATH . 'database/sql/71_monline_b2b.sql');
        $this->assertStringContainsString('reject_reason', $schema, 'mfg_purchase_orders.reject_reason must exist');
    }

    /** Every seller-side action is RBAC-guarded, not merely tenant-resolved. */
    public function testEveryMethodIsPermissionGuarded(): void
    {
        $src = $this->controller();

        $this->assertStringContainsString("guard('mfg.po.view')", $this->methodBody($src, 'index'));
        $this->assertStringContainsString("guard('mfg.po.view')", $this->methodBody($src, 'show'));
        $this->assertStringContainsString("guard('mfg.po.manage')", $this->methodBody($src, 'transition'));
    }

    /** Reads and writes are scoped to the acting manufacturer — never to a request parameter. */
    public function testEveryQueryIsScopedToTheActingManufacturer(): void
    {
        $src = $this->controller();

        // Matched across newlines: the call now spans several lines because it also
        // passes the unit scope. What must hold is that the FIRST argument is the acting
        // manufacturer, never a request parameter — not how the call is wrapped.
        $this->assertMatchesRegularExpression(
            '/listForSeller\(\s*\(int\)\s*\$this->manufacturerId\(\)/s',
            $this->methodBody($src, 'index'),
        );
        $this->assertStringContainsString("findFor(\$id, (int) \$this->manufacturerId(), 'seller')", $this->methodBody($src, 'show'));

        $transition = $this->methodBody($src, 'transition');
        $this->assertStringContainsString('(int) $this->manufacturerId()', $transition);
        $this->assertStringContainsString("'seller',", $transition);
    }

    /**
     * The lifecycle must not be silent. Every transition tells the counterparty.
     *
     * Placement notifies the seller; accept/reject/dispatch notify the buyer; receipt
     * notifies the seller back. Wired in the repository rather than the controllers so
     * every caller is covered, matching TransferService.
     */
    public function testLifecycleTransitionsNotifyTheCounterparty(): void
    {
        $events = $this->read('Libraries/Notify/NotificationService.php');
        foreach (['po.placed', 'po.accepted', 'po.rejected', 'po.dispatched', 'po.received'] as $code) {
            $this->assertStringContainsString("'{$code}'", $events, "NotificationService::EVENTS is missing '{$code}'");
        }

        $repo = $this->read('Models/PurchaseOrderRepository.php');
        $this->assertStringContainsString("'po.placed'", $this->methodBody($repo, 'placeFromCart'), 'placing must notify the seller');
        $this->assertStringContainsString("'po.received'", $this->methodBody($repo, 'receive'), 'receipt must notify the seller');
        $this->assertStringContainsString(
            "notifyVendorOwner((int) \$found['po']['buyer_vendor_id']",
            $this->methodBody($repo, 'transition'),
            'accept/reject/dispatch must notify the BUYER, not the seller who triggered them',
        );
    }

    /**
     * A notification must never fire for an order that was rolled back.
     *
     * The notify call has to sit after transStatus() confirms the commit — inside the
     * try block it would announce a purchase order that does not exist.
     */
    public function testPlacementNotifiesOnlyAfterTheTransactionCommits(): void
    {
        $body = $this->methodBody($this->read('Models/PurchaseOrderRepository.php'), 'placeFromCart');

        $commitCheck = strpos($body, 'if (! $db->transStatus())');
        $notify      = strpos($body, "'po.placed'");
        $this->assertNotFalse($commitCheck);
        $this->assertNotFalse($notify);
        $this->assertLessThan($notify, $commitCheck, 'the seller must not be notified before the commit is confirmed');
    }

    /** Crude brace-matching body extractor — same convention as MonlineB2bTest. */
    private function methodBody(string $src, string $method): string
    {
        if (! preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $brace = strpos($src, '{', (int) $m[0][1]);
        if ($brace === false) {
            return '';
        }

        $depth = 0;
        for ($i = $brace, $len = strlen($src); $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }

        return '';
    }
}
