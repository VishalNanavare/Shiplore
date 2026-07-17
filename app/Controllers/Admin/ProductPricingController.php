<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\ProductPricingController — per-product pricing for any vendor: special
 * overlays (offer/deal/flash), quantity tiers and customer-group prices, with a
 * live effective-price preview via the PriceResolver. Guarded by product.update.
 */
final class ProductPricingController extends BaseController
{
    public function index(int $productId)
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $product = service('adminProductRepository')->findById($productId);
        if ($product === null) {
            return redirect()->to('admin/products')->with('error', 'Product not found.');
        }

        return view('admin/products/pricing', [
            'title' => 'Pricing · Admin', 'pageTitle' => 'Pricing', 'active' => 'products',
            'userName' => session()->get('user_name') ?: 'User',
            'product' => $product,
            'rows' => $this->rows($productId),
            'groups' => service('pricingRepository')->customerGroups(),
            'specialUrl' => site_url('admin/products/' . $productId . '/pricing/special'),
            'tierUrl' => site_url('admin/products/' . $productId . '/pricing/tier'),
            'groupUrl' => site_url('admin/products/' . $productId . '/pricing/group'),
            'delSpecialBase' => site_url('admin/products/' . $productId . '/pricing/special-delete/'),
            'delTierBase' => site_url('admin/products/' . $productId . '/pricing/tier-delete/'),
            'backUrl' => site_url('admin/products'),
        ]);
    }

    public function addSpecial(int $productId): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $vid = $this->variantInProduct($productId);
        if ($vid > 0) {
            service('pricingRepository')->addSpecial($vid, (array) $this->request->getPost(), (int) session()->get('user_id'));
        }

        return redirect()->to('admin/products/' . $productId . '/pricing')->with($vid > 0 ? 'success' : 'error', $vid > 0 ? 'Special price added.' : 'Invalid variant.');
    }

    public function addTier(int $productId): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $vid = $this->variantInProduct($productId);
        $ok  = $vid > 0 && service('pricingRepository')->addTier($vid, (float) $this->request->getPost('min_qty'), (float) $this->request->getPost('price'), (int) session()->get('user_id'));

        return redirect()->to('admin/products/' . $productId . '/pricing')->with($ok ? 'success' : 'error', $ok ? 'Tier added.' : 'Invalid tier.');
    }

    public function setGroup(int $productId): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $vid = $this->variantInProduct($productId);
        $ok  = $vid > 0 && service('pricingRepository')->setGroupPrice($vid, (int) $this->request->getPost('customer_group_id'), (float) $this->request->getPost('price'), (int) session()->get('user_id'));

        return redirect()->to('admin/products/' . $productId . '/pricing')->with($ok ? 'success' : 'error', $ok ? 'Group price set.' : 'Invalid group price.');
    }

    public function deleteSpecial(int $productId, int $listId): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        service('pricingRepository')->deleteSpecial($listId);

        return redirect()->to('admin/products/' . $productId . '/pricing')->with('success', 'Removed.');
    }

    public function deleteTier(int $productId, int $tierId): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        service('pricingRepository')->deleteTier($tierId);

        return redirect()->to('admin/products/' . $productId . '/pricing')->with('success', 'Removed.');
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

    private function variantInProduct(int $productId): int
    {
        $vid = (int) $this->request->getPost('variant_id');
        $v   = service('productVariantRepository')->findVariant($vid);

        return ($v !== null && (int) $v['product_id'] === $productId) ? $vid : 0;
    }

    protected function guard(): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'product.update')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
