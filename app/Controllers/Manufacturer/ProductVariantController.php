<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use App\Libraries\Catalog\ManufacturerPricing;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Manufacturer\ProductVariantController — the variant builder, matching the vendor
 * and admin panels screen for screen.
 *
 * It renders the same partials/_product_variants_body shell those two use; only the
 * two price columns differ. A vendor prices against MRP, a manufacturer against its
 * own making (production) cost — mrp stays 0 for manufacturer products and
 * making_price carries the cost — so the partial is handed
 * priceA = making_price / priceB = base_price instead of mrp / base_price.
 *
 * The 0 < making < selling invariant is enforced on every write here, exactly as
 * Manufacturer\ProductController does for the product itself. Without it the variant
 * grid would be an open side door around the rule: generate() and bulkUpdate() both
 * set prices in bulk and neither goes through ProductController.
 *
 * productVariantRepository is party-agnostic — it works off product/vendor ids on the
 * shared products/product_variants tables — so it is reused rather than forked. The
 * tenant check is done here, against the manufacturer's own product.
 *
 * @see \App\Controllers\Vendor\ProductVariantController — the vendor counterpart
 */
final class ProductVariantController extends BaseManufacturerController
{
    public function index(int $productId)
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.product.view')) {
            return $denied;
        }

        $product = $this->ownedProduct($productId);
        if ($product === null) {
            return redirect()->to('manufacturer/products')->with('error', 'Product not found.');
        }

        $vr = service('productVariantRepository');
        $vr->cleanupEmptyDefault($productId);
        $variants = $vr->listWithValues($productId);

        // -1 means "staff with no unit"; treat it as unscoped rather than querying it.
        $unit   = $this->effectiveMshopId();
        $unitId = $unit !== null && $unit > 0 ? $unit : 0;
        $inv    = service('manufacturerInventoryService');

        $bcByVariant = $stockLevels = [];
        foreach ($variants as $v) {
            $vid               = (int) $v['id'];
            $bcByVariant[$vid] = service('productBarcodeRepository')->forVariant($vid);
            $stockLevels[$vid] = $unitId > 0 ? (float) ($inv->levels($vid, $unitId)['on_hand'] ?? 0) : 0.0;
        }

        return $this->render('manufacturer/products/variants', 'products', 'Variants', [
            'product'           => $product,
            'attributes'        => $vr->definingAttributes((int) $product['category_id']),
            'variants'          => $variants,
            'barcodesByVariant' => $bcByVariant,
            // Stock is shown ONLY when the view is scoped to a single unit. An owner
            // spanning several units has no single on-hand number, and summing them
            // would be a lie — a variant with 10 at one plant and 0 at another is not
            // "10 available" to either. Those users see no column, and use the Stock
            // page (which is per-unit) instead.
            'stockLevels'       => $stockLevels,
            'inventoryMode'     => $unitId > 0 ? 'managed' : 'none',
            // Stock only: the manufacturer panel has no /pricing screen (tiered and
            // customer-group pricing are consumer-segment concepts — B2B price is
            // negotiated per purchase order), and its stock page is /stock.
            'siblingLinks'      => [
                ['Stock', 'bi-boxes', site_url('manufacturer/products/' . $productId . '/stock')],
            ],
            'priceA'            => ['making_price', 'Making price'],
            'priceB'            => ['base_price', 'Selling price'],
            'genUrl'            => site_url('manufacturer/products/' . $productId . '/variants/generate'),
            'variantUpdateBase' => site_url('manufacturer/variants/'),
            'variantDeleteBase' => site_url('manufacturer/variants/'),
            'bulkUrl'           => site_url('manufacturer/products/' . $productId . '/variants/bulk'),
            'barcodeBase'       => site_url('manufacturer/variants/'),
            'backUrl'           => site_url('manufacturer/products'),
        ]);
    }

    public function generate(int $productId): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.product.update')) {
            return $denied;
        }

        $product = $this->ownedProduct($productId);
        if ($product === null) {
            return redirect()->to('manufacturer/products')->with('error', 'Product not found.');
        }

        $post = (array) $this->request->getPost();
        if (($err = ManufacturerPricing::validate($post)) !== '') {
            return redirect()->back()->withInput()->with('error', $err);
        }

        $made = service('productVariantRepository')->generate(
            $productId,
            (int) $this->manufacturerId(),   // forced from the product, never from input
            $this->selections($post),
            [
                'skuPrefix'    => trim((string) ($post['sku_prefix'] ?? 'SKU')),
                'making_price' => $post['making_price'] ?? null,
                'base_price'   => $post['base_price'] ?? null,
            ],
            (int) session()->get('user_id'),
        );

        return redirect()->to('manufacturer/products/' . $productId . '/variants')
            ->with('success', $made . ' variant(s) created.');
    }

    public function update(int $variantId): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.product.update')) {
            return $denied;
        }

        $vr      = service('productVariantRepository');
        $variant = $vr->findVariant($variantId);
        if ($variant === null || $this->ownedProduct((int) $variant['product_id']) === null) {
            return redirect()->to('manufacturer/products')->with('error', 'Variant not found.');
        }

        $post = (array) $this->request->getPost();
        // Merge against what is stored, so posting only one of the two prices cannot
        // step past the invariant one field at a time.
        $check = [
            'making_price' => $post['making_price'] ?? $variant['making_price'] ?? null,
            'base_price'   => $post['base_price'] ?? $variant['base_price'] ?? null,
        ];
        if (($err = ManufacturerPricing::validate($check)) !== '') {
            return redirect()->back()->with('error', $err);
        }

        $vr->updateVariant($variantId, (int) $this->manufacturerId(), $post, (int) session()->get('user_id'));

        // The grid renders an editable stock box whenever the view is unit-scoped, so
        // it has to be handled here or the number is silently discarded.
        //
        // NOT the vendor path: Vendor\ProductVariantController calls
        // productVariantRepository->setStock(), which writes the shop `inventory`
        // tables. Passing an mshop id to that would corrupt a real shop's live stock,
        // since the two id spaces overlap. Manufacturer stock moves only through
        // ManufacturerInventoryService, and only as a delta, so the mfg ledger keeps a
        // complete history instead of an unexplained jump.
        $unit = $this->effectiveMshopId();
        if ($unit !== null && $unit > 0 && array_key_exists('stock', $post) && $post['stock'] !== '') {
            $svc     = service('manufacturerInventoryService');
            $current = (float) ($svc->levels($variantId, $unit)['on_hand'] ?? 0);
            $delta   = (float) $post['stock'] - $current;

            if ($delta !== 0.0) {
                $svc->adjust($variantId, $unit, $delta, 'correction', 'set from the variants grid', (int) session()->get('user_id'));
            }
        }

        return redirect()->to('manufacturer/products/' . (int) $variant['product_id'] . '/variants')
            ->with('success', 'Variant updated.');
    }

    public function delete(int $variantId): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.product.update')) {
            return $denied;
        }

        $vr      = service('productVariantRepository');
        $variant = $vr->findVariant($variantId);
        if ($variant === null || $this->ownedProduct((int) $variant['product_id']) === null) {
            return redirect()->to('manufacturer/products')->with('error', 'Variant not found.');
        }

        $vr->deleteVariant($variantId, (int) $this->manufacturerId(), (int) session()->get('user_id'));

        return redirect()->to('manufacturer/products/' . (int) $variant['product_id'] . '/variants')
            ->with('success', 'Variant removed.');
    }

    public function bulkUpdate(int $productId): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.product.update')) {
            return $denied;
        }

        $product = $this->ownedProduct($productId);
        if ($product === null) {
            return redirect()->to('manufacturer/products')->with('error', 'Product not found.');
        }

        $field = (string) $this->request->getPost('field');
        $value = (string) $this->request->getPost('value');
        $ids   = array_map('intval', (array) $this->request->getPost('ids'));

        // A bulk price set is still a price set: check it against the counterpart price
        // on every affected variant, or this becomes the way around the invariant.
        if ($field === 'making_price' || $field === 'base_price') {
            $vr = service('productVariantRepository');
            foreach ($ids as $vid) {
                $v = $vr->findVariant($vid);
                if ($v === null) {
                    continue;
                }
                $check = [
                    'making_price' => $field === 'making_price' ? $value : ($v['making_price'] ?? null),
                    'base_price'   => $field === 'base_price' ? $value : ($v['base_price'] ?? null),
                ];
                if (($err = ManufacturerPricing::validate($check)) !== '') {
                    return redirect()->back()->with('error', $err);
                }
            }
        }

        $n = service('productVariantRepository')->bulkUpdate(
            $ids,
            (int) $this->manufacturerId(),
            $field,
            $value,
            (int) session()->get('user_id'),
        );

        return redirect()->to('manufacturer/products/' . $productId . '/variants')
            ->with('success', $n . ' variant(s) updated.');
    }

    public function saveBarcodes(int $variantId): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.product.update')) {
            return $denied;
        }

        $variant = service('productVariantRepository')->findVariant($variantId);
        if ($variant === null || $this->ownedProduct((int) $variant['product_id']) === null) {
            return redirect()->to('manufacturer/products')->with('error', 'Variant not found.');
        }

        $res = service('productBarcodeRepository')->saveFromForm(
            (int) $variant['product_id'],
            $variantId,
            (array) $this->request->getPost(),
            (int) session()->get('user_id'),
        );

        return redirect()->to('manufacturer/products/' . (int) $variant['product_id'] . '/variants')
            ->with(empty($res['errors']) ? 'success' : 'error', empty($res['errors'])
                ? (($res['added'] ?? 0) . ' barcode(s) saved.')
                : implode('; ', (array) $res['errors']));
    }

    // ---- internals ---------------------------------------------------------

    /**
     * The product, only if it belongs to THIS manufacturer.
     *
     * productVariantRepository and adminProductRepository are both party-agnostic and
     * look products up by primary key, so this is the only thing standing between a
     * guessed product id and another tenant's catalogue.
     *
     * @return array<string,mixed>|null
     */
    private function ownedProduct(int $productId): ?array
    {
        return service('manufacturerProductRepository')->findById($productId, (int) $this->manufacturerId());
    }

    /**
     * attributeId => [valueId, ...] from the posted sel[] grid, empties dropped.
     *
     * @param array<string,mixed> $post
     * @return array<int,list<int>>
     */
    private function selections(array $post): array
    {
        $out = [];
        foreach ((array) ($post['sel'] ?? []) as $attrId => $valueIds) {
            $ids = array_values(array_filter(array_map('intval', (array) $valueIds)));
            if ($ids !== []) {
                $out[(int) $attrId] = $ids;
            }
        }

        return $out;
    }
}
