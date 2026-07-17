<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

/** Vendor\VendorDashboardController — KPIs + recent orders for the acting vendor. */
final class VendorDashboardController extends BaseVendorController
{
    public function dashboard()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        $vid  = $this->vendorId();
        $repo = service('vendorDashboardRepository');

        return $this->render('vendor/dashboard', 'dashboard', 'Dashboard', [
            'metrics'      => $repo->metrics($vid),
            'recentOrders' => $repo->recentOrders($vid),
            'perShop'      => $repo->perShop($vid, $this->allowedShopIds()),
        ]);
    }
}
