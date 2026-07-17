<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use App\Controllers\Concerns\ProductMediaActions;
use CodeIgniter\HTTP\RedirectResponse;

/** Vendor\ProductMediaController — gallery + documents for the vendor's OWN products. */
final class ProductMediaController extends BaseVendorController
{
    use ProductMediaActions;

    protected function resolveProduct(int $productId): ?array
    {
        $p = service('adminProductRepository')->findById($productId);

        return ($p !== null && (int) $p['vendor_id'] === $this->vendorId()) ? $p : null;
    }

    protected function mediaPrefix(): string
    {
        return 'vendor';
    }

    protected function mediaGuard(): ?RedirectResponse
    {
        return $this->requireVendor();
    }
}
