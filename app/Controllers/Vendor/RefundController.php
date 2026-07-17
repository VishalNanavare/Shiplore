<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

/** Vendor\RefundController — refunds on orders that include this vendor. */
final class RefundController extends BaseVendorController
{
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        // S0: staff see only refunds for their assigned shop(s).
        return $this->render('vendor/refunds/index', 'refunds', 'Refunds', [
            'refunds'     => service('vendorRefundRepository')->list($this->vendorId(), $this->effectiveShopId()),
            'shopOptions' => $this->shopOptions(),
            'shopId'      => $this->requestedShopId(),
        ]);
    }
}
