<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Tenant/shop scoping gaps on vendor + monline routes (audit M12-M17, L1, L2).
 *
 * Every finding here follows the same shape: a controller checks that the PARENT
 * resource (a product, a vendor, a shop) belongs to the caller, then passes a
 * CHILD id (a price-list id, a transfer id, a PO id) straight to a repository with
 * no further check — two independent path segments, only one of them verified.
 */
final class VendorTenantScopeTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    /**
     * This file hits BOTH admin/... and vendor/... routes across different tests, so
     * unlike a single-panel test file the host can't be fixed once in setUp() — each
     * test that makes a real request must call this first with its OWN panel.
     *
     * Both restricted route groups (app/Config/Routes.php 'subdomain' option) only
     * register for a matching Host header — see PanelSubdomainIsolationTest and
     * AdminAccessTest, which this reproduces exactly. resetSingle() is required
     * because 'request'/'routes' are cached singletons that otherwise keep whichever
     * host was current when they were first built for this process; tearDown()'s
     * unsetServer() is required because Superglobals::setServer() writes straight
     * into the real $_SERVER array, which plain Services::reset() does not undo — an
     * un-cleaned host here would leak into every test that runs after this file in
     * the same PHPUnit process.
     */
    private function withHost(string $host): void
    {
        service('superglobals')->setServer('HTTP_HOST', $host);
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

    /** Same brace-matching extractor used elsewhere this session (Auth/Xss/Media hardening tests). */
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

    /** Strip `//` line comments so a code-absence assertion can't be fooled by a
     *  comment that mentions the very thing it's explaining is gone. */
    private function stripLineComments(string $body): string
    {
        return (string) preg_replace('#//[^\r\n]*#', '', $body);
    }

    // ------------------------------------------------------------------ M12

    public function testPricingRepositoryDeleteSpecialChecksVariantOwner(): void
    {
        $src = $this->read('Models/PricingRepository.php');
        $this->assertMatchesRegularExpression(
            '/function deleteSpecial\(int \$listId, int \$vendorId\)/',
            $src,
            'deleteSpecial() must take a vendorId to scope by',
        );
        $body = $this->methodBody($src, 'deleteSpecial');
        $this->assertStringContainsString('product_variants', $body, 'ownership must be derived from the linked variant');
        $this->assertStringContainsString('pv.vendor_id', $body);
        // Structural presence of the join proves nothing if the mismatch is never
        // actually rejected — this is the line mutation testing caught missing.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$total\s*===\s*0\s*\|\|\s*\$total\s*!==\s*\$owned\s*\)\s*\{\s*return\s+false;/',
            $body,
            'a list with any item NOT owned by $vendorId (or no items at all) must be refused, not just counted',
        );
    }

    public function testPricingRepositoryDeleteTierChecksVariantOwner(): void
    {
        $src = $this->read('Models/PricingRepository.php');
        $this->assertMatchesRegularExpression('/function deleteTier\(int \$tierId, int \$vendorId\)/', $src);
        $body = $this->methodBody($src, 'deleteTier');
        $this->assertStringContainsString('pv.vendor_id', $body);
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$owned\s*===\s*0\s*\)\s*\{\s*return\s+false;/',
            $body,
            'a tier not owned by $vendorId must be refused before the delete() call, not just counted',
        );
    }

    public function testAdminPricingControllerPassesProductsOwnVendorId(): void
    {
        $src = $this->read('Controllers/Admin/ProductPricingController.php');
        foreach (['deleteSpecial', 'deleteTier'] as $m) {
            $body = $this->methodBody($src, $m);
            $this->assertStringContainsString(
                "product['vendor_id']",
                $body,
                $m . '() must resolve the product first and pass ITS vendor_id, not trust the id alone',
            );
        }
    }

    public function testVendorPricingControllerPassesOwnVendorId(): void
    {
        $src = $this->read('Controllers/Vendor/ProductPricingController.php');
        foreach (['deleteSpecial', 'deleteTier'] as $m) {
            $body = $this->methodBody($src, $m);
            $this->assertStringContainsString('$this->vendorId()', $body, $m . '() must pass the caller\'s own vendor id to the repository');
        }
    }

    public function testAdminPricingDeleteReflectsBooleanReturnInFlash(): void
    {
        // The audit's own note: a refused delete previously still flashed 'Removed.'
        // regardless of what the repository returned.
        $src = $this->read('Controllers/Admin/ProductPricingController.php');
        $this->assertStringContainsString("\$ok ? 'success' : 'error'", $src);
    }

    public function testApiDeleteProductPricingChecksProductOwnership(): void
    {
        $body = $this->methodBody($this->read('Controllers/Api/V1/VendorApiController.php'), 'deleteProductPricing');
        $this->assertStringContainsString(
            "where('vendor_id', \$vid)",
            $body,
            'every sibling method (productPricing, addSpecialPrice, addTierPrice) checks product ownership; this one must too',
        );
    }

    // ------------------------------------------------------------------ M13

    public function testTransferRepositoryHasVendorScopedFindOne(): void
    {
        $body = $this->methodBody($this->read('Models/VendorTransferRepository.php'), 'findOne');
        $this->assertStringContainsString("where('vendor_id', \$vendorId)", $body);
    }

    public function testTransferControllerGuardsEveryLifecycleAction(): void
    {
        $src = $this->read('Controllers/Vendor/TransferController.php');

        // act() is the single funnel every lifecycle action goes through — approve,
        // reject, pack, dispatch, receive, close, cancel all call it. If the guard is
        // inside act() itself, all seven get it for free with no per-action edit.
        $act = $this->methodBody($src, 'act');
        $this->assertStringContainsString(
            '$this->requireTransferAccess(',
            $act,
            'act() must check shop access, or every action funnelled through it stays unguarded',
        );

        // Confirm all seven actually funnel through act($id, ...) and not some
        // bypassed direct call.
        foreach (['approve', 'reject', 'pack', 'dispatch', 'receive', 'close', 'cancel'] as $action) {
            $body = $this->methodBody($src, $action);
            $this->assertStringContainsString('$this->act($id,', $body, $action . '() must funnel through the guarded act()');
        }
    }

    public function testRequireTransferAccessAllowsOwnerAndAssignedShopsOnly(): void
    {
        $body = $this->methodBody($this->read('Controllers/Vendor/TransferController.php'), 'requireTransferAccess');
        $this->assertStringContainsString('isOwner()', $body);
        $this->assertStringContainsString('allowedShopIds()', $body);
        $this->assertStringContainsString('from_shop_id', $body);
        $this->assertStringContainsString('to_shop_id', $body);
    }

    // ------------------------------------------------------------------ M14

    public function testAddRiderRequiresStaffPermission(): void
    {
        $body = $this->methodBody($this->read('Controllers/Vendor/StaffController.php'), 'addRider');
        $this->assertStringContainsString("can('rider.manage')", $body);
        $this->assertStringContainsString("can('vendor_staff.manage')", $body);
        $this->assertStringContainsString('isOwner()', $body);
    }

    public function testStaffViewHidesRiderFormWithoutPermission(): void
    {
        $view = $this->read('Views/vendor/staff/index.php');
        $this->assertMatchesRegularExpression(
            '/canAddRider.*?Add delivery rider/s',
            $view,
            'the rider form must be gated on canAddRider, not rendered unconditionally',
        );
    }

    // ------------------------------------------------------------------ M15 / L2

    public function testCreateStaffRefusesToAdoptAnExistingAccount(): void
    {
        $body = $this->methodBody($this->read('Controllers/Api/V1/VendorApiController.php'), 'createStaff');
        $this->assertStringContainsString('409', $body, 'an existing phone must be refused, not silently adopted into this tenant');
        $this->assertStringNotContainsString('$existingUser', $body, 'the old upsert-by-phone path must be gone entirely');
    }

    public function testStaffTypeIsValidatedAgainstTheFullEnum(): void
    {
        $src = $this->read('Controllers/Api/V1/VendorApiController.php');
        $this->assertStringContainsString('STAFF_TYPES', $src);
        foreach (['branch_manager', 'cashier', 'packer', 'helper', 'delivery_boy', 'manager', 'other'] as $type) {
            $this->assertStringContainsString("'{$type}'", $src, 'STAFF_TYPES is missing ' . $type . ' from the schema ENUM');
        }
        $update = $this->methodBody($src, 'updateStaff');
        $this->assertStringContainsString('STAFF_TYPES', $update, 'updateStaff() must validate type, not just createStaff()');
    }

    public function testStaffShopAssignmentsUseTheRealColumns(): void
    {
        // staff_shop_assignments is keyed on (vendor_staff_id, shop_id) — the old code
        // queried staff_user_id/vendor_id, columns that never existed on this table.
        $src = $this->read('Controllers/Api/V1/VendorApiController.php');
        foreach (['createStaff', 'updateStaff', 'deleteStaff'] as $m) {
            $body = $this->stripLineComments($this->methodBody($src, $m));
            $this->assertStringContainsString('vendor_staff_id', $body, $m . '() must key staff_shop_assignments on vendor_staff_id');
            $this->assertStringNotContainsString('staff_user_id', $body, $m . '() must not reference the nonexistent staff_user_id column');
        }
    }

    public function testCreateStaffShopIdsAreConstrainedToCallersOwnShops(): void
    {
        $body = $this->methodBody($this->read('Controllers/Api/V1/VendorApiController.php'), 'createStaff');
        $this->assertStringContainsString('shopIdsForVendor', $body, 'client-supplied shop_ids must be intersected against the caller\'s real shops');
    }

    // ------------------------------------------------------------------ M16

    public function testPortalLeaveRouteIsDeclaredOutsideThePinnedGroup(): void
    {
        $routes = $this->read('Config/Routes.php');

        $leavePos = strpos($routes, "post('admin/portal/leave'");
        $groupPos = strpos($routes, "group('admin', ['filter' => 'webAuth:platform', 'subdomain' => 'admin']");
        $this->assertNotFalse($leavePos);
        $this->assertNotFalse($groupPos);
        $this->assertLessThan($groupPos, $leavePos, 'admin/portal/leave must be declared BEFORE the pinned group — first-declaration-wins');

        // Plain webAuth, not webAuth:platform — impersonating admins are principal_type
        // 'vendor'/'manufacturer' while inside a portal, so the pin would block the exit.
        $line = substr($routes, $leavePos, 120);
        $this->assertStringContainsString("'webAuth'", $line);
        $this->assertStringNotContainsString("'webAuth:platform'", $line);
    }

    public function testPortalLeaveIsNoLongerDeclaredInsideTheAdminGroup(): void
    {
        $routes = $this->read('Config/Routes.php');
        $groupPos = strpos($routes, "group('admin', ['filter' => 'webAuth:platform', 'subdomain' => 'admin']");
        $groupEnd = strpos($routes, "\n});", $groupPos);
        $this->assertNotFalse($groupEnd);
        $groupBody = substr($routes, $groupPos, $groupEnd - $groupPos);

        $this->assertStringNotContainsString('portal/leave', $groupBody, 'the route must not also still be declared inside the pinned group');
    }

    // ------------------------------------------------------------------ M17

    public function testPurchaseOrderReceiveLocksTheRowBeforeCrediting(): void
    {
        $body = $this->methodBody($this->read('Models/PurchaseOrderRepository.php'), 'receive');
        $this->assertStringContainsString('FOR UPDATE', $body, 'the status re-check must happen under a row lock, or two concurrent calls both pass it');
        $this->assertStringContainsString('transBegin', $body);
    }

    public function testPurchaseOrderTransitionLocksTheRowBeforeWriting(): void
    {
        $body = $this->methodBody($this->read('Models/PurchaseOrderRepository.php'), 'transition');
        $this->assertStringContainsString('FOR UPDATE', $body);
        $this->assertStringContainsString('transBegin', $body);
    }

    // ------------------------------------------------------------------ L1

    public function testMonlineOrderControllerGuardsShowReceiveCancel(): void
    {
        $src = $this->read('Controllers/Monline/OrderController.php');
        foreach (['show', 'receive', 'cancel'] as $m) {
            $body = $this->methodBody($src, $m);
            $this->assertStringContainsString(
                '$this->requirePoShopAccess(',
                $body,
                $m . '() must check the PO\'s buyer_shop_id against buyerShopIds(), matching what place() and orders() already enforce',
            );
        }
    }

    // ------------------------------------------------------------------ Feature-level wiring (M12)

    public function testAdminDeleteSpecialWiresVendorIdThroughToRepo(): void
    {
        $this->withHost('admin.shiplore.test');
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array
            {
                return [['permissions' => ['product.update'], 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
        Services::injectMock('adminProductRepository', new class {
            public function findById(int $id): ?array { return ['id' => $id, 'vendor_id' => 4, 'title' => 'Tee']; }
        });
        $spy = new class {
            public array $got = [];
            public function deleteSpecial(int $listId, int $vendorId): bool { $this->got = [$listId, $vendorId]; return true; }
        };
        Services::injectMock('pricingRepository', $spy);

        $sess = ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Admin', 'principal_type' => 'platform'];
        $r = $this->withSession(service('session')->get() + $sess)
            ->post('admin/products/7/pricing/special-delete/99', [csrf_token() => csrf_hash()]);

        $r->assertRedirect();
        $this->assertSame([99, 4], $spy->got, 'the vendor id passed to the repository must come from the RESOLVED PRODUCT, not the request');
    }

    public function testVendorDeleteTierWiresCallersOwnVendorId(): void
    {
        $this->withHost('vendor.shiplore.test');
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array
            {
                return [['permissions' => [], 'scope_type' => 'vendor', 'scope_id' => 4, 'attributes' => []]];
            }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 4, 'is_owner' => true]; }
            public function findStaffVendor(int $u): ?array { return null; }
        });
        Services::injectMock('adminProductRepository', new class {
            public function findById(int $id): ?array { return ['id' => $id, 'vendor_id' => 4, 'title' => 'Tee']; }
        });
        $spy = new class {
            public array $got = [];
            public function deleteTier(int $tierId, int $vendorId): bool { $this->got = [$tierId, $vendorId]; return false; }
        };
        Services::injectMock('pricingRepository', $spy);

        $sess = ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'V', 'principal_type' => 'vendor'];
        $r = $this->withSession(service('session')->get() + $sess)
            ->post('vendor/products/7/pricing/tier-delete/55', [csrf_token() => csrf_hash()]);

        $r->assertRedirect();
        $this->assertSame([55, 4], $spy->got);
        // The repository refused (returned false) — the flash must say so, not 'Removed.'
        // Feature tests run in-process, so flashdata set during the request is visible
        // on the real session service right after — TestResponse has no getSession().
        $this->assertNotEmpty(service('session')->getFlashdata('error'));
    }
}
