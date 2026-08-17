<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

/**
 * Manufacturer\NotificationController — notifications addressed to the acting user.
 *
 * The feed already has real content on day one: PurchaseOrderRepository fires five
 * po.* events through NotificationService::notifyVendorOwner() for the whole B2B
 * lifecycle, and until now a manufacturer had nowhere in the panel to read them —
 * partials/_topbar.php sent its "View All" link to the dashboard precisely because
 * this page did not exist.
 *
 * `vendorNotificationRepository` is keyed on users.id alone and carries no vendor or
 * party assumption despite its name, so it is reused rather than forked. Scoping is
 * therefore per-USER, not per-tenant: a unit staff member sees their own feed, never
 * the owner's.
 *
 * @see \App\Controllers\Vendor\NotificationController — the vendor counterpart
 */
final class NotificationController extends BaseManufacturerController
{
    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }

        return $this->render('manufacturer/notifications/index', 'notifications', 'Notifications', [
            'notifications' => service('vendorNotificationRepository')->list((int) session()->get('user_id')),
        ]);
    }
}
