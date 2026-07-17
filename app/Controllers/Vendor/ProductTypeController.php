<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use App\Controllers\Concerns\ProductTypeConfig;
use CodeIgniter\HTTP\RedirectResponse;

/** Vendor\ProductTypeController — specialized-type config for the vendor's OWN products. */
final class ProductTypeController extends BaseVendorController
{
    use ProductTypeConfig;

    protected function resolveProduct(int $productId): ?array
    {
        $p = service('adminProductRepository')->findById($productId);

        return ($p !== null && (int) $p['vendor_id'] === $this->vendorId()) ? $p : null;
    }

    protected function prefix(): string
    {
        return 'vendor';
    }

    protected function renderType(array $product, array $data)
    {
        if ($this->request->isAJAX()) {
            return view('partials/_product_type_body', $data);   // modal body only
        }

        return $this->render('vendor/products/type', 'products', ucfirst((string) $data['type']) . ' setup', $data);
    }

    protected function typeGuard(): ?RedirectResponse
    {
        return $this->requireVendor();
    }
}
