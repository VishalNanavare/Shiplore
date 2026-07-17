<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * ProductTypeConfig — shared specialized-type (bundle/combo/digital/subscription/
 * giftcard) controller logic for both the Admin and Vendor panels. The host
 * controller supplies product resolution (scoped or not), the URL prefix, the
 * render call and the access guard; everything else is identical.
 */
trait ProductTypeConfig
{
    /** @return array<string,mixed>|null product if the caller may manage it, else null */
    abstract protected function resolveProduct(int $productId): ?array;

    /** 'admin' or 'vendor' */
    abstract protected function prefix(): string;

    /** @param array<string,mixed> $data */
    abstract protected function renderType(array $product, array $data);

    abstract protected function typeGuard(): ?RedirectResponse;

    public function index(int $productId)
    {
        if ($denied = $this->typeGuard()) {
            return $denied;
        }
        $product = $this->resolveProduct($productId);
        if ($product === null) {
            return redirect()->to($this->prefix() . '/products')->with('error', 'Product not found.');
        }
        $type = (string) ($product['product_type'] ?? 'simple');
        $repo = service('productTypeRepository');
        $p    = $this->prefix();
        $base = site_url($p . '/products/' . $productId . '/type/');

        $data = [
            'product' => $product, 'type' => $type,
            'backUrl' => site_url($p . '/products'),
            'bundleAddUrl' => $base . 'bundle-add', 'bundleDelBase' => $base . 'bundle-del/',
            'downloadAddUrl' => $base . 'download-add', 'downloadDelBase' => $base . 'download-del/',
            'licenseAddUrl' => $base . 'license-add',
            'planAddUrl' => $base . 'plan-add', 'planDelBase' => $base . 'plan-del/',
            'giftUrl' => $base . 'gift-save', 'rentalUrl' => $base . 'rental-save',
        ];
        if (in_array($type, ['bundle', 'combo'], true)) {
            $data['bundleItems'] = $repo->bundleItems($productId);
            $data['bundleEff']   = $repo->bundleEffective($productId, $type, (float) ($product['base_price'] ?? 0));
            $data['vendorVariants'] = $repo->vendorVariants((int) $product['vendor_id'], $productId);
        } elseif (in_array($type, ['digital', 'downloadable'], true)) {
            $data['downloads'] = $repo->downloads($productId);
            $data['licenseStats'] = $repo->licenseStats($productId);
        } elseif ($type === 'subscription') {
            $data['plans'] = $repo->plans($productId);
        } elseif ($type === 'giftcard') {
            $data['gift'] = $repo->giftConfig($productId);
        } elseif ($type === 'rental') {
            $data['rental'] = $repo->rentalTerms($productId);
        }

        return $this->renderType($product, $data);
    }

    public function bundleAdd(int $productId): RedirectResponse
    {
        return $this->guarded($productId, function (array $product) use ($productId) {
            $vid = (int) $this->request->getPost('item_variant_id');
            // component must belong to the same vendor (tenant safety)
            $v = service('productVariantRepository')->findVariant($vid);
            if ($v !== null && (int) $v['vendor_id'] === (int) $product['vendor_id']) {
                service('productTypeRepository')->addBundleItem($productId, $vid, (float) $this->request->getPost('qty'), $this->numOrNull('price_contribution'), (bool) $this->request->getPost('is_optional'), (int) session()->get('user_id'));
            }
        });
    }

    public function bundleDel(int $productId, int $id): RedirectResponse
    {
        return $this->guarded($productId, fn () => service('productTypeRepository')->deleteBundleItem($id, $productId));
    }

    public function downloadAdd(int $productId): RedirectResponse
    {
        return $this->guarded($productId, fn () => service('productTypeRepository')->addDownload($productId, (array) $this->request->getPost(), (int) session()->get('user_id')));
    }

    public function downloadDel(int $productId, int $id): RedirectResponse
    {
        return $this->guarded($productId, fn () => service('productTypeRepository')->deleteDownload($id, $productId));
    }

    public function licenseAdd(int $productId): RedirectResponse
    {
        return $this->guarded($productId, fn () => service('productTypeRepository')->addLicenseKeys($productId, (string) $this->request->getPost('keys'), (int) session()->get('user_id')));
    }

    public function planAdd(int $productId): RedirectResponse
    {
        return $this->guarded($productId, fn () => service('productTypeRepository')->addPlan($productId, (array) $this->request->getPost(), (int) session()->get('user_id')));
    }

    public function planDel(int $productId, int $id): RedirectResponse
    {
        return $this->guarded($productId, fn () => service('productTypeRepository')->deletePlan($id, $productId));
    }

    public function giftSave(int $productId): RedirectResponse
    {
        return $this->guarded($productId, fn () => service('productTypeRepository')->setGiftConfig($productId, (array) $this->request->getPost(), (int) session()->get('user_id')));
    }

    public function rentalSave(int $productId): RedirectResponse
    {
        return $this->guarded($productId, fn () => service('productTypeRepository')->setRentalTerms($productId, (array) $this->request->getPost(), (int) session()->get('user_id')));
    }

    /** Run $fn($product) if guard + ownership pass, then redirect to the type page. */
    private function guarded(int $productId, callable $fn): RedirectResponse
    {
        if ($denied = $this->typeGuard()) {
            return $denied;
        }
        $product = $this->resolveProduct($productId);
        if ($product === null) {
            return redirect()->to($this->prefix() . '/products')->with('error', 'Product not found.');
        }
        $fn($product);

        return redirect()->to($this->prefix() . '/products/' . $productId . '/type')->with('success', 'Saved.');
    }

    private function numOrNull(string $k): ?float
    {
        $v = $this->request->getPost($k);

        return ($v === null || $v === '') ? null : (float) $v;
    }
}
