<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Vendor\ProductPricingController — pricing for the vendor's OWN products only.
 * Special overlays / tiers / group prices, tenant-checked: the product and every
 * targeted variant must belong to the logged-in vendor.
 */
final class ProductPricingController extends BaseVendorController
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

        return $this->render('vendor/products/pricing', 'products', 'Pricing', [
            'product' => $product,
            'rows' => $this->rows($productId),
            'groups' => service('pricingRepository')->customerGroups(),
            'specialUrl' => site_url('vendor/products/' . $productId . '/pricing/special'),
            'tierUrl' => site_url('vendor/products/' . $productId . '/pricing/tier'),
            'groupUrl' => site_url('vendor/products/' . $productId . '/pricing/group'),
            'delSpecialBase' => site_url('vendor/products/' . $productId . '/pricing/special-delete/'),
            'delTierBase' => site_url('vendor/products/' . $productId . '/pricing/tier-delete/'),
            'backUrl' => site_url('vendor/products'),
        ]);
    }

    public function addSpecial(int $productId): RedirectResponse
    {
        return $this->mutate($productId, fn (int $vid) => service('pricingRepository')->addSpecial($vid, (array) $this->request->getPost(), (int) session()->get('user_id')) > 0, 'Special price added.');
    }

    public function addTier(int $productId): RedirectResponse
    {
        return $this->mutate($productId, fn (int $vid) => service('pricingRepository')->addTier($vid, (float) $this->request->getPost('min_qty'), (float) $this->request->getPost('price'), (int) session()->get('user_id')), 'Tier added.');
    }

    public function setGroup(int $productId): RedirectResponse
    {
        return $this->mutate($productId, fn (int $vid) => service('pricingRepository')->setGroupPrice($vid, (int) $this->request->getPost('customer_group_id'), (float) $this->request->getPost('price'), (int) session()->get('user_id')), 'Group price set.');
    }

    public function deleteSpecial(int $productId, int $listId): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        // $productId being ours does not prove $listId is: they are independent POST
        // segments. The repository re-checks every item on the list against $vendorId.
        $ok = $this->ownProduct($productId) !== null && service('pricingRepository')->deleteSpecial($listId, $this->vendorId());

        return redirect()->to('vendor/products/' . $productId . '/pricing')->with($ok ? 'success' : 'error', $ok ? 'Removed.' : 'Price list not found.');
    }

    public function deleteTier(int $productId, int $tierId): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $ok = $this->ownProduct($productId) !== null && service('pricingRepository')->deleteTier($tierId, $this->vendorId());

        return redirect()->to('vendor/products/' . $productId . '/pricing')->with($ok ? 'success' : 'error', $ok ? 'Removed.' : 'Tier not found.');
    }

    /** Shared mutate: tenant-check product + variant, run $fn(vid), flash. */
    private function mutate(int $productId, callable $fn, string $okMsg): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if ($this->ownProduct($productId) === null) {
            return redirect()->to('vendor/products')->with('error', 'Product not found.');
        }
        $vid = (int) $this->request->getPost('variant_id');
        $v   = service('productVariantRepository')->findVariant($vid);
        $ok  = $v !== null && (int) $v['product_id'] === $productId && (int) $v['vendor_id'] === $this->vendorId() && $fn($vid);

        return redirect()->to('vendor/products/' . $productId . '/pricing')->with($ok ? 'success' : 'error', $ok ? $okMsg : 'Invalid request.');
    }

    /** @return list<array<string,mixed>> */
    private function rows(int $productId): array
    {
        $pr   = service('pricingRepository');
        $rows = [];
        foreach (service('productVariantRepository')->listWithValues($productId) as $v) {
            $vid = (int) $v['id'];
            $rows[] = [
                'v' => $v,
                'eff' => $pr->effectivePrice($vid, (float) $v['base_price'], ['now' => date('Y-m-d H:i:s'), 'channel' => 'online', 'customer_group_id' => null, 'shop_id' => null, 'qty' => 1]),
                'specials' => $pr->specials($vid), 'tiers' => $pr->tiers($vid), 'groups' => $pr->groupPrices($vid),
            ];
        }

        return $rows;
    }
}
