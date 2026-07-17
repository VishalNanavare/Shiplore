<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

/** Vendor\GstController — GST collected by the vendor (read-only). */
final class GstController extends BaseVendorController
{
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        $repo = service('vendorGstRepository');
        $vid  = $this->vendorId();

        return $this->render('vendor/gst/index', 'gst', 'GST', [
            'summary' => $repo->summary($vid),
            'lines'   => $repo->lines($vid),
        ]);
    }
}
