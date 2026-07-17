<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use App\Controllers\Concerns\ProductLookups;

/** Vendor\ProductLookupController — cascading JSON lookups, locked to the vendor's own data. */
final class ProductLookupController extends BaseVendorController
{
    use ProductLookups;

    protected function effectiveVendorId(int $requested): ?int
    {
        $vid = $this->vendorId();   // always the logged-in vendor; the URL param is ignored

        return $vid > 0 ? $vid : null;
    }

    protected function lookupGuard()
    {
        if ($this->requireVendor() !== null) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        return null;
    }
}
