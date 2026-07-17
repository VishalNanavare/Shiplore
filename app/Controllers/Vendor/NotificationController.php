<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

/** Vendor\NotificationController — notifications addressed to the vendor user. */
final class NotificationController extends BaseVendorController
{
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        return $this->render('vendor/notifications/index', 'notifications', 'Notifications', [
            'notifications' => service('vendorNotificationRepository')->list((int) session()->get('user_id')),
        ]);
    }
}
