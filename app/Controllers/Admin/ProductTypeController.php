<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Controllers\Concerns\ProductTypeConfig;
use CodeIgniter\HTTP\RedirectResponse;

/** Admin\ProductTypeController — specialized-type config for any vendor's product. */
final class ProductTypeController extends BaseController
{
    use ProductTypeConfig;

    protected function resolveProduct(int $productId): ?array
    {
        return service('adminProductRepository')->findById($productId);
    }

    protected function prefix(): string
    {
        return 'admin';
    }

    protected function renderType(array $product, array $data)
    {
        if ($this->request->isAJAX()) {
            return view('partials/_product_type_body', $data);   // modal body only
        }

        return view('admin/products/type', $data + [
            'title' => 'Type setup · Admin', 'pageTitle' => 'Type setup', 'active' => 'products',
            'userName' => session()->get('user_name') ?: 'User',
        ]);
    }

    protected function typeGuard(): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'product.update')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
