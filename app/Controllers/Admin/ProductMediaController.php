<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Controllers\Concerns\ProductMediaActions;
use CodeIgniter\HTTP\RedirectResponse;

/** Admin\ProductMediaController — gallery reorder/primary/remove + documents, any vendor. */
final class ProductMediaController extends BaseController
{
    use ProductMediaActions;

    protected function resolveProduct(int $productId): ?array
    {
        return service('adminProductRepository')->findById($productId);
    }

    protected function mediaPrefix(): string
    {
        return 'admin';
    }

    protected function mediaGuard(): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'product.update')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
