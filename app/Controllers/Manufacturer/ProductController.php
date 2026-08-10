<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use App\Libraries\Catalog\ManufacturerPricing;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Manufacturer\ProductController — the manufacturer's own catalogue.
 *
 * The difference from the vendor equivalent is the price shape: making price + selling
 * price, with 0 < making < selling. That invariant is validated HERE for the user-facing
 * message and again in ManufacturerProductRepository so it holds even if a future caller
 * skips this controller.
 *
 * Products created here are B2B-only: is_online_enabled=0 and visibility='vendor', on top
 * of the party_type exclusion in StoreCatalogRepository. Belt and braces, because the
 * cost of a leak is the whole manufacturer catalogue appearing on the consumer storefront.
 */
final class ProductController extends BaseManufacturerController
{
    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.product.view')) {
            return $denied;
        }

        $status = trim((string) $this->request->getGet('status'));
        $unit   = $this->effectiveMshopId();

        return $this->render('manufacturer/products/index', 'products', 'Products', [
            'products' => service('manufacturerProductRepository')->list(
                (int) $this->manufacturerId(),
                $status !== '' ? $status : null,
                // -1 means "staff with no unit" -> show nothing, never everything.
                $unit === -1 ? -1 : $unit,
            ),
            'filters' => ['status' => $status],
        ]);
    }

    public function new()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.product.create')) {
            return $denied;
        }

        return $this->form(null);
    }

    public function store(): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.product.create')) {
            return $denied;
        }

        $in = (array) $this->request->getPost();
        if (($err = ManufacturerPricing::validate($in)) !== '') {
            return redirect()->back()->withInput()->with('error', $err);
        }
        $mshopId = $this->resolveMshopId();
        if ($mshopId === null) {
            return redirect()->back()->withInput()->with('error', 'Select a manufacturing unit for this product.');
        }

        $res = service('manufacturerProductRepository')->create(
            (int) $this->manufacturerId(),
            $in,
            (int) session()->get('user_id'),
            $mshopId,
        );

        if (! $res['ok']) {
            return redirect()->back()->withInput()->with('error', $res['error']);
        }

        return redirect()->to('manufacturer/products/' . $res['id'] . '/edit')->with('success', 'Product created.');
    }

    public function edit(int $id)
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.product.view')) {
            return $denied;
        }

        $product = service('manufacturerProductRepository')->findById($id, (int) $this->manufacturerId());
        if ($product === null) {
            return redirect()->to('manufacturer/products')->with('error', 'Product not found.');
        }

        return $this->form($product);
    }

    public function update(int $id): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.product.update')) {
            return $denied;
        }

        $mshopId = $this->resolveMshopId();
        if ($mshopId === null) {
            return redirect()->back()->withInput()->with('error', 'Select a manufacturing unit for this product.');
        }

        $res = service('manufacturerProductRepository')->update(
            $id,
            (int) $this->manufacturerId(),
            (array) $this->request->getPost(),
            (int) session()->get('user_id'),
            $mshopId,
        );

        if (! $res['ok']) {
            return redirect()->back()->withInput()->with('error', $res['error']);
        }

        return redirect()->to('manufacturer/products/' . $id . '/edit')->with('success', 'Product saved.');
    }

    /**
     * Section autosave (JSON).
     *
     * Unlike the vendor autosave path this validates before writing — otherwise the
     * pricing section is a hole straight through the making<selling invariant.
     */
    public function autosave(int $id, string $section): ResponseInterface
    {
        if ($this->requireManufacturer() || ! $this->can('mfg.product.update')) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'csrf' => csrf_hash()]);
        }

        $res = service('manufacturerProductRepository')->autosave(
            $id,
            (int) $this->manufacturerId(),
            $section,
            (array) $this->request->getPost(),
            (int) session()->get('user_id'),
        );

        return $this->response->setStatusCode($res['ok'] ? 200 : 422)->setJSON([
            'ok'       => $res['ok'],
            'error'    => $res['error'],
            'saved_at' => date('c'),
            'csrf'     => csrf_hash(),
        ]);
    }

    public function submit(int $id): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.product.submit')) {
            return $denied;
        }

        $repo    = service('manufacturerProductRepository');
        $product = $repo->findById($id, (int) $this->manufacturerId());
        if ($product === null) {
            return redirect()->to('manufacturer/products')->with('error', 'Product not found.');
        }

        // Re-check on submit: a draft may have been created before both prices were set.
        $err = ManufacturerPricing::validate([
            'making_price' => $product['making_price'],
            'base_price'   => $product['base_price'],
        ]);
        if ($err !== '') {
            return redirect()->back()->with('error', $err);
        }

        $repo->setStatus($id, (int) $this->manufacturerId(), 'submitted', (int) session()->get('user_id'));

        return redirect()->to('manufacturer/products')->with('success', 'Product submitted for approval.');
    }

    /**
     * The manufacturing unit this product is assigned to, resolved the same way
     * `Vendor\ProductController::resolveShopIds()` resolves a shop: unit-scoped staff
     * (store keeper / unit manager) are forced to their own effective unit rather than
     * trusted to post one, an owner or manufacturer-wide manager may pick any unit they
     * are allowed to act on. Returns null when nothing valid is available/selected —
     * the caller must treat that as a hard stop, not "unassigned is fine".
     */
    private function resolveMshopId(): ?int
    {
        if (! $this->isOwner()) {
            $effective = $this->effectiveMshopId();

            return $effective !== null && $effective > 0 ? $effective : null;
        }
        $posted = (int) $this->request->getPost('mshop_id');

        return $posted > 0 && in_array($posted, $this->allowedMshopIds(), true) ? $posted : null;
    }

    /** @param array<string,mixed>|null $product */
    private function form(?array $product): string
    {
        $isOwner = $this->isOwner();
        $units   = $this->mshopOptions();
        $current = $product !== null ? (int) ($product['mshop_id'] ?? 0) : 0;
        $selectedMshop = $current > 0
            ? $current
            : (! $isOwner ? $this->effectiveMshopId() : (count($units) === 1 ? array_key_first($units) : null));

        return $this->render(
            'manufacturer/products/form',
            'products',
            $product === null ? 'New Product' : 'Edit Product',
            [
                'product'    => $product,
                'categories' => service('adminProductRepository')->allowedCategories((int) $this->manufacturerId()),
                'masters'    => service('adminProductRepository')->formMasters(),
                'units'      => $units,
                'lockUnit'   => ! $isOwner,
                'selectedMshop' => $selectedMshop,
            ],
        );
    }
}
