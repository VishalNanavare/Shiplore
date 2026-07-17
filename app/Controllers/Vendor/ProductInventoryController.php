<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Vendor\ProductInventoryController — per-product stock for the vendor's OWN
 * products and OWN shops only. Receives stock, adjusts (damage/return/manual),
 * shows levels + valuation; every movement posts an inventory_ledger entry.
 */
final class ProductInventoryController extends BaseVendorController
{
    private function ownProduct(int $productId): ?array
    {
        $p = service('adminProductRepository')->findById($productId);

        return ($p !== null && (int) $p['vendor_id'] === $this->vendorId()) ? $p : null;
    }

    public function index(int $productId)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $product = $this->ownProduct($productId);
        if ($product === null) {
            return redirect()->to('vendor/products')->with('error', 'Product not found.');
        }
        $shops    = $this->allowedShops(); // S0: receive/adjust only into assigned shops
        $variants = service('productVariantRepository')->listWithValues($productId);

        return $this->render('vendor/products/inventory', 'products', 'Inventory', [
            'product' => $product, 'shops' => $shops,
            'rows' => service('inventoryService')->snapshotRows($variants, $shops),
            'receiveUrl' => site_url('vendor/products/' . $productId . '/inventory/receive'),
            'adjustUrl' => site_url('vendor/products/' . $productId . '/inventory/adjust'),
            'backUrl' => site_url('vendor/products'),
        ]);
    }

    public function receive(int $productId): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if ($this->ownProduct($productId) === null) {
            return redirect()->to('vendor/products')->with('error', 'Product not found.');
        }
        $variantId = (int) $this->request->getPost('variant_id');
        $shopId    = (int) $this->request->getPost('shop_id');
        $qty       = (float) $this->request->getPost('qty');
        if ($this->ownVariantShop($variantId, $productId, $shopId) && $qty > 0) {
            $payload = [
                'variant_id' => $variantId, 'product_id' => $productId, 'shop_id' => $shopId, 'qty' => $qty,
                'cost' => (float) $this->request->getPost('cost'),
                'batch_no' => $this->request->getPost('batch_no'), 'expiry_date' => $this->request->getPost('expiry_date'),
            ];
            // S2: a staff stock receipt is proposed to the vendor; it posts to the
            // ledger only after approval. The owner receives directly.
            if (! $this->isOwner()) {
                [$type, $msg] = $this->requestFlash($this->submitChangeRequest('inventory', 'receive', $variantId, null, $payload, 'receive-' . $shopId));
                session()->setFlashdata($type, $msg);
            } else {
                $ok = service('inventoryService')->receive($variantId, $shopId, $qty, $payload['cost'], ['batch_no' => $payload['batch_no'], 'expiry_date' => $payload['expiry_date']], (int) session()->get('user_id'));
                session()->setFlashdata($ok ? 'success' : 'error', $ok ? 'Stock received.' : 'Could not receive stock.');
            }
        } else {
            session()->setFlashdata('error', 'Invalid receive request.');
        }

        return redirect()->to('vendor/products/' . $productId . '/inventory');
    }

    public function adjust(int $productId): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if ($this->ownProduct($productId) === null) {
            return redirect()->to('vendor/products')->with('error', 'Product not found.');
        }
        $variantId = (int) $this->request->getPost('variant_id');
        $shopId    = (int) $this->request->getPost('shop_id');
        $delta     = (float) $this->request->getPost('qty_delta');
        if ($this->ownVariantShop($variantId, $productId, $shopId) && $delta != 0.0) {
            $payload = [
                'variant_id' => $variantId, 'product_id' => $productId, 'shop_id' => $shopId, 'delta' => $delta,
                'reason' => (string) $this->request->getPost('reason'), 'notes' => (string) $this->request->getPost('notes'),
            ];
            // S2: a staff stock adjustment needs the vendor's approval; the owner adjusts directly.
            if (! $this->isOwner()) {
                [$type, $msg] = $this->requestFlash($this->submitChangeRequest('inventory', 'adjust', $variantId, null, $payload, 'adjust-' . $shopId));
                session()->setFlashdata($type, $msg);
            } else {
                $ok = service('inventoryService')->adjust($variantId, $shopId, $delta, $payload['reason'], $payload['notes'], (int) session()->get('user_id'));
                session()->setFlashdata($ok ? 'success' : 'error', $ok ? 'Stock adjusted.' : 'Could not adjust stock.');
            }
        } else {
            session()->setFlashdata('error', 'Invalid adjustment.');
        }

        return redirect()->to('vendor/products/' . $productId . '/inventory');
    }

    /** Variant must be this product's, and the shop must be one the user is assigned to. */
    private function ownVariantShop(int $variantId, int $productId, int $shopId): bool
    {
        $v = service('productVariantRepository')->findVariant($variantId);
        if ($v === null || (int) $v['product_id'] !== $productId || (int) $v['vendor_id'] !== $this->vendorId()) {
            return false;
        }
        // S0: shop-level isolation — a branch manager moves stock only in their
        // assigned shop(s), not every shop of the vendor.
        if (! in_array($shopId, $this->allowedShopIds(), true)) {
            return false;
        }

        return service('vendorShopRepository')->findById($shopId, $this->vendorId()) !== null;
    }
}
