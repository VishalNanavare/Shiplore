<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Controllers\Concerns\ProductLookups;

/** Admin\ProductLookupController — cascading JSON lookups for the product form (any vendor). */
final class ProductLookupController extends BaseController
{
    use ProductLookups;

    protected function effectiveVendorId(int $requested): ?int
    {
        return $requested > 0 ? $requested : null;   // admin reads any vendor; null = no constraint
    }

    protected function lookupGuard()
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), 'product.view')) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        return null;
    }
}
