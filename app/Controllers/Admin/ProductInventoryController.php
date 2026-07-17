<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\ProductInventoryController — per-product stock management for any vendor:
 * receive stock (cost batches), adjust (damage/return/manual), view levels +
 * valuation. Every movement posts an inventory_ledger entry. Guarded by
 * inventory.manage (falls back to product.update).
 */
final class ProductInventoryController extends BaseController
{
    private function product(int $productId): ?array
    {
        return service('adminProductRepository')->findById($productId);
    }

    public function index(int $productId)
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $product = $this->product($productId);
        if ($product === null) {
            return redirect()->to('admin/products')->with('error', 'Product not found.');
        }
        $shops    = service('vendorShopRepository')->list((int) $product['vendor_id']);
        $variants = service('productVariantRepository')->listWithValues($productId);

        return view('admin/products/inventory', [
            'title' => 'Inventory · Admin', 'pageTitle' => 'Inventory', 'active' => 'products',
            'userName' => session()->get('user_name') ?: 'User',
            'product' => $product, 'shops' => $shops,
            'rows' => service('inventoryService')->snapshotRows($variants, $shops),
            'receiveUrl' => site_url('admin/products/' . $productId . '/inventory/receive'),
            'adjustUrl' => site_url('admin/products/' . $productId . '/inventory/adjust'),
            'backUrl' => site_url('admin/products'),
        ]);
    }

    public function receive(int $productId): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $this->doReceive($productId);

        return redirect()->to('admin/products/' . $productId . '/inventory');
    }

    public function adjust(int $productId): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $this->doAdjust($productId);

        return redirect()->to('admin/products/' . $productId . '/inventory');
    }

    /** Shared receive: validates the variant belongs to the product, then posts. */
    private function doReceive(int $productId): void
    {
        $variantId = (int) $this->request->getPost('variant_id');
        $shopId    = (int) $this->request->getPost('shop_id');
        $qty       = (float) $this->request->getPost('qty');
        if (! $this->variantInProduct($variantId, $productId) || $shopId <= 0 || $qty <= 0) {
            session()->setFlashdata('error', 'Invalid receive request.');

            return;
        }
        $ok = service('inventoryService')->receive($variantId, $shopId, $qty, (float) $this->request->getPost('cost'), [
            'batch_no' => $this->request->getPost('batch_no'), 'expiry_date' => $this->request->getPost('expiry_date'),
        ], (int) session()->get('user_id'));
        session()->setFlashdata($ok ? 'success' : 'error', $ok ? 'Stock received.' : 'Could not receive stock.');
    }

    private function doAdjust(int $productId): void
    {
        $variantId = (int) $this->request->getPost('variant_id');
        $shopId    = (int) $this->request->getPost('shop_id');
        $delta     = (float) $this->request->getPost('qty_delta');
        if (! $this->variantInProduct($variantId, $productId) || $shopId <= 0 || $delta == 0.0) {
            session()->setFlashdata('error', 'Invalid adjustment.');

            return;
        }
        $ok = service('inventoryService')->adjust($variantId, $shopId, $delta, (string) $this->request->getPost('reason'), (string) $this->request->getPost('notes'), (int) session()->get('user_id'));
        session()->setFlashdata($ok ? 'success' : 'error', $ok ? 'Stock adjusted.' : 'Could not adjust stock.');
    }

    private function variantInProduct(int $variantId, int $productId): bool
    {
        $v = service('productVariantRepository')->findVariant($variantId);

        return $v !== null && (int) $v['product_id'] === $productId;
    }

    protected function guard(): ?RedirectResponse
    {
        $ctx = service('scopeContext')->all();
        if (service('policyEngine')->can($ctx, 'inventory.manage') || service('policyEngine')->can($ctx, 'product.update')) {
            return null;
        }

        return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
    }
}
