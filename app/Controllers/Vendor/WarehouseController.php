<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

/** Vendor\WarehouseController — the vendor's own warehouses (read-only). */
final class WarehouseController extends BaseVendorController
{
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        return $this->render('vendor/warehouses/index', 'warehouses', 'Warehouses', [
            'warehouses' => service('vendorWarehouseRepository')->list($this->vendorId()),
        ]);
    }
}
