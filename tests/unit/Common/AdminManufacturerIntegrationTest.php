<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The admin back-office's awareness of manufacturers and monline B2B orders.
 *
 * Manufacturers share the `vendors` table under party_type='manufacturer'. That makes
 * the default failure mode silent: a generic admin vendor query returns them with no
 * error and no label, so they get counted as vendors, listed as vendors, and — worst —
 * approved with the vendor permission. These tests pin the separation.
 */
final class AdminManufacturerIntegrationTest extends CIUnitTestCase
{
    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

    /**
     * The one that would be a real privilege escalation.
     *
     * VendorController::transition() backs approve() and reject(). Without a party_type
     * guard, a role holding only vendor.approve could approve a manufacturer through the
     * vendor route, and manufacturer.approve would enforce nothing.
     */
    public function testVendorScreensRefuseToActOnAManufacturer(): void
    {
        $src = $this->read('Controllers/Admin/VendorController.php');

        $this->assertStringContainsString(
            "(\$row['party_type'] ?? 'vendor') === 'manufacturer'",
            $src,
            'VendorController needs a manufacturer guard',
        );

        foreach (['show', 'edit', 'update', 'transition'] as $method) {
            $this->assertStringContainsString(
                'rejectManufacturer',
                $this->methodBody($src, $method),
                "VendorController::{$method}() must refuse a manufacturer row — otherwise the "
                . 'vendor permission silently covers manufacturers too',
            );
        }
    }

    /** The vendor list must be hard-scoped, not merely labelled. */
    public function testVendorListIsScopedToVendors(): void
    {
        $this->assertStringContainsString(
            "'party_type' => 'vendor'",
            $this->methodBody($this->read('Controllers/Admin/VendorController.php'), 'index'),
        );
        $this->assertStringContainsString(
            "\$b->where('v.party_type', \$f['party_type']);",
            $this->read('Models/VendorRepository.php'),
            'VendorRepository must be able to filter by party_type',
        );
    }

    /** Manufacturer approval uses its own permissions, never the vendor ones. */
    public function testManufacturerApprovalUsesItsOwnPermissions(): void
    {
        $src = $this->read('Controllers/Admin/ManufacturerController.php');

        $this->assertStringContainsString("'manufacturer.approve'", $src);
        $this->assertStringContainsString("'manufacturer.reject'", $src);
        $this->assertStringContainsString("'manufacturer.view'", $src);
        $this->assertStringNotContainsString("'vendor.approve'", $src, 'must not reuse the vendor permission');

        $sql = (string) file_get_contents(ROOTPATH . 'database/sql/73_admin_manufacturer_oversight.sql');
        foreach (['manufacturer.view', 'manufacturer.approve', 'manufacturer.reject',
            'monline.po.oversight.view', 'monline.po.oversight.cancel'] as $code) {
            $this->assertStringContainsString("'{$code}'", $sql, "permission {$code} is not seeded");
        }
    }

    /** Every manufacturer read is re-checked against party_type, not trusted from the id. */
    public function testManufacturerScreensVerifyPartyType(): void
    {
        $src = $this->read('Controllers/Admin/ManufacturerController.php');

        foreach (['show', 'transition'] as $method) {
            $this->assertStringContainsString(
                "!== 'manufacturer'",
                $this->methodBody($src, $method),
                "ManufacturerController::{$method}() must reject a plain vendor id",
            );
        }
    }

    /**
     * Impersonation must resolve the manufacturer's own principal type.
     *
     * enterVendor() hardcoded 'vendor' in both the user lookup and the session, so
     * entering a manufacturer failed with a misleading "no active owner login" error.
     */
    public function testManufacturerImpersonationResolvesItsOwnPrincipalType(): void
    {
        $src  = $this->read('Controllers/Admin/PortalController.php');
        $body = $this->methodBody($src, 'enterManufacturer');

        $this->assertNotSame('', $body, 'PortalController::enterManufacturer() is missing');
        $this->assertStringContainsString("activeUser((int) \$m['owner_user_id'], 'manufacturer')", $body);
        $this->assertStringContainsString("->where('party_type', 'manufacturer')", $body);

        // The session's principal_type must be parameterised, not hardcoded to vendor.
        $this->assertStringContainsString("'principal_type'          => \$principalType,", $src);
        $this->assertStringContainsString("string \$principalType = 'vendor'", $src, 'the default keeps existing callers working');
    }

    /** Dashboard KPIs must not count manufacturers as vendors. */
    public function testDashboardSeparatesManufacturersFromVendors(): void
    {
        $body = $this->methodBody($this->read('Models/DashboardRepository.php'), 'metrics');

        $this->assertStringContainsString("'vendors'         =>", $body);
        $this->assertStringContainsString("where('party_type', 'vendor')", $body, 'the vendor count must exclude manufacturers');
        $this->assertStringContainsString("'manufacturers'", $body);
        $this->assertStringContainsString("'open_purchase_orders'", $body);
    }

    /** Admin B2B oversight exists and is permission-gated. */
    public function testB2bOversightIsPermissionGated(): void
    {
        $src = $this->read('Controllers/Admin/PurchaseOrderController.php');

        $this->assertStringContainsString("guard('monline.po.oversight.view')", $this->methodBody($src, 'index'));
        $this->assertStringContainsString("guard('monline.po.oversight.view')", $this->methodBody($src, 'show'));
        $this->assertStringContainsString("guard('monline.po.oversight.cancel')", $this->methodBody($src, 'cancel'));

        // A reason is mandatory — a silent platform cancellation would be unexplainable
        // to either trading party.
        $this->assertStringContainsString("\$reason === ''", $this->methodBody($src, 'cancel'));
    }

    /**
     * Force-cancel must go through the same state machine as everyone else.
     *
     * Admin is not exempt from physics: once goods are dispatched, cancelling the record
     * would leave stock in transit with nothing to unwind it.
     */
    public function testForceCancelRespectsTheStateMachine(): void
    {
        $body = $this->methodBody($this->read('Models/PurchaseOrderRepository.php'), 'adminCancel');

        $this->assertNotSame('', $body, 'PurchaseOrderRepository::adminCancel() is missing');
        $this->assertStringContainsString(
            "StatusMachine::canPurchaseOrder(\$from, 'cancelled')",
            $body,
            'admin cancellation must be gated by the same state machine, not bypass it',
        );
    }

    /**
     * adminCancel() must notify with an event that actually says "cancelled" — not
     * "rejected", which is both factually wrong (nobody rejected it, the state machine
     * doesn't even permit a 'rejected' status from 'accepted'/'packed') and misleading
     * about who acted (the platform, not the counterparty).
     */
    public function testAdminCancelUsesAnAccurateNotificationEvent(): void
    {
        $body = $this->methodBody($this->read('Models/PurchaseOrderRepository.php'), 'adminCancel');

        $this->assertStringContainsString("'po.cancelled'", $body);
        $this->assertStringNotContainsString(
            "'po.rejected'",
            $body,
            'a platform force-cancel is not a rejection by the manufacturer',
        );

        $events = $this->read('Libraries/Notify/NotificationService.php');
        $this->assertStringContainsString("'po.cancelled'", $events, 'the po.cancelled event must be registered');
    }

    /** Both trading parties must be able to see WHY an order was cancelled, not just admin. */
    public function testCancellationReasonIsVisibleToBothParties(): void
    {
        $buyerView = (string) file_get_contents(APPPATH . 'Views/monline/order_detail.php');
        $this->assertMatchesRegularExpression(
            "/\\['rejected', 'cancelled'\\]/",
            $buyerView,
            "order_detail.php's reason box must render for 'cancelled' too, not only 'rejected'",
        );

        $sellerView = (string) file_get_contents(APPPATH . 'Views/manufacturer/orders/show.php');
        $this->assertStringContainsString(
            'reject_reason',
            $sellerView,
            'the manufacturer-facing PO page never shows reject_reason for any status — a '
            . 'seller whose order is admin-cancelled has no way to see why',
        );
    }

    /** Admin routes exist for every new screen. */
    public function testAdminRoutesAreRegistered(): void
    {
        $routes = $this->read('Config/Routes.php');

        foreach ([
            "'Admin\\ManufacturerController::index'",
            "'Admin\\ManufacturerController::show/\$1'",
            "'Admin\\ManufacturerController::approve/\$1'",
            "'Admin\\PurchaseOrderController::index'",
            "'Admin\\PurchaseOrderController::show/\$1'",
            "'Admin\\PurchaseOrderController::cancel/\$1'",
            "'Admin\\PortalController::enterManufacturer/\$1'",
        ] as $needle) {
            $this->assertStringContainsString($needle, $routes, "missing route: {$needle}");
        }
    }

    /** Generic vendor-labelled admin queries must not silently include manufacturers. */
    public function testVendorLabelledQueriesExcludeManufacturers(): void
    {
        $this->assertStringContainsString(
            "->where('party_type', 'vendor')",
            $this->read('Controllers/Admin/ImportController.php'),
            'the bulk-import vendor list must not offer manufacturer slugs',
        );
        $this->assertStringContainsString(
            "->where('party_type', 'vendor')",
            $this->read('Controllers/Admin/MediaController.php'),
            'the media library vendor picker must not list manufacturers',
        );
        $this->assertStringContainsString(
            "->where('v.party_type', 'vendor')",
            $this->read('Models/ReportRepository.php'),
            'top-vendor reporting must not mix in manufacturers',
        );
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
