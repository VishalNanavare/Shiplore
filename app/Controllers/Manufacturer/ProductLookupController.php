<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use App\Controllers\Concerns\ProductLookups;

/**
 * Manufacturer\ProductLookupController — cascading JSON lookups (categories,
 * attributes, attribute values, brands, category defaults) for the product form and
 * variant builder, locked to this manufacturer's own data.
 *
 * The public endpoints come from the shared ProductLookups trait; this class only
 * supplies the two things that differ per panel — how the tenant is established, and
 * what "logged in here" means. The requested id in the URL is deliberately ignored in
 * favour of the session's own tenant, exactly as the vendor version does.
 *
 * @see \App\Controllers\Vendor\ProductLookupController — the vendor counterpart
 */
final class ProductLookupController extends BaseManufacturerController
{
    use ProductLookups;

    protected function effectiveVendorId(int $requested): ?int
    {
        // Always the logged-in manufacturer; $requested is never trusted.
        $id = (int) $this->manufacturerId();

        return $id > 0 ? $id : null;
    }

    protected function lookupGuard()
    {
        // requireManufacturer() is the tenant gate for every endpoint this class
        // exposes through ProductLookups — see ManufacturerPanelIsolationTest, which
        // requires that call to appear in this file by name.
        if ($this->requireManufacturer() !== null) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        return null;
    }
}
