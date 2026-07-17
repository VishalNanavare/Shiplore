<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Vendor\ProductVariantController — the variant builder for the vendor's OWN
 * products only. Every product/variant is re-checked against the logged-in
 * vendor (tenant isolation); generation forces the vendor's id.
 */
final class ProductVariantController extends BaseVendorController
{
    public function index(int $productId)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $product = service('adminProductRepository')->findById($productId);
        if ($product === null || (int) $product['vendor_id'] !== $this->vendorId()) {
            return redirect()->to('vendor/products')->with('error', 'Product not found.');
        }
        $vr = service('productVariantRepository');
        $vr->cleanupEmptyDefault($productId);   // retire the redundant empty default
        $variants = $vr->listWithValues($productId);
        $shopId = (int) (service('productShopRepository')->forProduct($productId)[0] ?? 0);
        $inv    = service('inventoryService');
        $bcByVariant = $stockLevels = [];
        foreach ($variants as $v) {
            $vid = (int) $v['id'];
            $bcByVariant[$vid] = service('productBarcodeRepository')->forVariant($vid);
            $stockLevels[$vid] = $shopId > 0 ? (float) ($inv->levels($vid, $shopId)['on_hand'] ?? 0) : 0;
        }

        return $this->render('vendor/products/variants', 'products', 'Variants', [
            'product' => $product,
            'attributes' => $vr->definingAttributes((int) $product['category_id']),
            'variants' => $variants, 'barcodesByVariant' => $bcByVariant,
            'stockLevels' => $stockLevels, 'inventoryMode' => $product['inventory_mode'] ?? 'managed',
            'genUrl' => site_url('vendor/products/' . $productId . '/variants/generate'),
            'variantUpdateBase' => site_url('vendor/variants/'),
            'variantDeleteBase' => site_url('vendor/variants/'),
            'bulkUrl' => site_url('vendor/products/' . $productId . '/variants/bulk'),
            'barcodeBase' => site_url('vendor/variants/'),
            'backUrl' => site_url('vendor/products'),
        ]);
    }

    public function generate(int $productId): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $product = service('adminProductRepository')->findById($productId);
        if ($product === null || (int) $product['vendor_id'] !== $this->vendorId()) {
            return redirect()->to('vendor/products')->with('error', 'Product not found.');
        }
        $n = service('productVariantRepository')->generate(
            $productId,
            $this->vendorId(),
            $this->selections(),
            ['skuPrefix' => $this->request->getPost('sku_prefix'), 'mrp' => $this->request->getPost('mrp'), 'base_price' => $this->request->getPost('base_price')],
            (int) session()->get('user_id'),
        );

        return redirect()->to('vendor/products/' . $productId . '/variants')->with($n > 0 ? 'success' : 'error', $n > 0 ? "{$n} variant(s) generated." : 'No new variants (select values, or all combinations already exist).');
    }

    public function update(int $variantId): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $vr      = service('productVariantRepository');
        $variant = $vr->findVariant($variantId);
        if ($variant === null || (int) $variant['vendor_id'] !== $this->vendorId()) {
            return redirect()->to('vendor/products')->with('error', 'Variant not found.');
        }
        $post = (array) $this->request->getPost();
        $vr->updateVariant($variantId, $this->vendorId(), $post, (int) session()->get('user_id'));
        if (array_key_exists('stock', $post) && $post['stock'] !== '') {
            $shopId = (int) (service('productShopRepository')->forProduct((int) $variant['product_id'])[0] ?? 0);
            $vr->setStock($variantId, $shopId, (float) $post['stock'], (int) session()->get('user_id'));
        }

        return redirect()->to('vendor/products/' . (int) $variant['product_id'] . '/variants')->with('success', 'Variant saved.');
    }

    public function delete(int $variantId): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $vr      = service('productVariantRepository');
        $variant = $vr->findVariant($variantId);
        if ($variant === null || (int) $variant['vendor_id'] !== $this->vendorId()) {
            return redirect()->to('vendor/products')->with('error', 'Variant not found.');
        }
        $ok = $vr->deleteVariant($variantId, $this->vendorId());

        return redirect()->to('vendor/products/' . (int) $variant['product_id'] . '/variants')->with($ok ? 'success' : 'error', $ok ? 'Variant deleted.' : 'Cannot delete the default variant.');
    }

    public function bulkUpdate(int $productId): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $product = service('adminProductRepository')->findById($productId);
        if ($product === null || (int) $product['vendor_id'] !== $this->vendorId()) {
            return redirect()->to('vendor/products')->with('error', 'Product not found.');
        }
        $n = service('productVariantRepository')->bulkUpdate(
            (array) $this->request->getPost('ids'),
            $this->vendorId(),
            (string) $this->request->getPost('field'),
            (string) $this->request->getPost('value'),
            (int) session()->get('user_id'),
        );

        return redirect()->to('vendor/products/' . $productId . '/variants')->with($n > 0 ? 'success' : 'error', $n > 0 ? "{$n} variant(s) updated." : 'Select variants and a field to bulk-update.');
    }

    public function saveBarcodes(int $variantId): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $variant = service('productVariantRepository')->findVariant($variantId);
        if ($variant === null || (int) $variant['vendor_id'] !== $this->vendorId()) {
            return redirect()->to('vendor/products')->with('error', 'Variant not found.');
        }
        service('productBarcodeRepository')->saveFromForm((int) $variant['product_id'], $variantId, (array) $this->request->getPost(), (int) session()->get('user_id'));

        return redirect()->to('vendor/products/' . (int) $variant['product_id'] . '/variants')->with('success', 'Barcodes saved.');
    }

    /** @return array<int,list<int>> */
    private function selections(): array
    {
        $out = [];
        foreach ((array) $this->request->getPost('sel') as $attrId => $vals) {
            $out[(int) $attrId] = array_map('intval', (array) $vals);
        }

        return $out;
    }
}
