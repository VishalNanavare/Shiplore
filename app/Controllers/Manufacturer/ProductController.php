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
        $imgWarn = $this->maybeImage((int) $res['id']);

        return redirect()->to('manufacturer/products/' . $res['id'] . '/edit')->with('success', 'Product created.' . ($imgWarn ? ' ⚠ ' . $imgWarn : ''));
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
        $imgWarn = $this->maybeImage($id);

        return redirect()->to('manufacturer/products/' . $id . '/edit')->with('success', 'Product saved.' . ($imgWarn ? ' ⚠ ' . $imgWarn : ''));
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

    /**
     * Render the shared product shell (partials/_product_form_body) — the same one the
     * vendor and admin panels use, so this screen is the vendor screen rather than a
     * thinner lookalike. It was previously a bespoke 6-field form, which is why it did
     * not match.
     *
     * Two things the partial is told rather than left to assume:
     *  - the location picker is a manufacturing unit and posts mshop_id, not shop_id;
     *  - price/SKU/stock are NOT on this page for any panel — they live on Variants.
     *
     * adminProductRepository is party-agnostic (it reads the shared products tables by
     * id), so all the rich sections — content, SEO, FAQ, specs, relations, videos — are
     * reused rather than reimplemented.
     *
     * @param array<string,mixed>|null $product
     */
    private function form(?array $product): string
    {
        $repo    = service('adminProductRepository');
        $isOwner = $this->isOwner();
        $pid     = $product !== null ? (int) $product['id'] : 0;

        // Unit rows in the shape the partial's picker expects ([id, name]).
        $units = [];
        foreach ($this->mshopOptions() as $id => $name) {
            $units[] = ['id' => (int) $id, 'name' => $name];
        }

        $current  = $product !== null ? (int) ($product['mshop_id'] ?? 0) : 0;
        $selected = $current > 0
            ? $current
            : (! $isOwner ? $this->effectiveMshopId() : (count($units) === 1 ? (int) $units[0]['id'] : null));

        return $this->render(
            'manufacturer/products/form',
            'products',
            $product === null ? 'New Product' : 'Edit Product',
            [
                'product'  => $product,
                'vendorId' => $this->manufacturerId(),
                'ctx'      => $isOwner ? 'manufacturer_owner' : 'manufacturer_unit_manager',
                'vendors'  => [],

                // The location picker, relabelled for manufacturing units.
                'shops'         => $units,
                'selectedShops' => $selected ? [(int) $selected] : [],
                'lockShops'     => ! $isOwner,
                'locField'      => 'mshop_id',
                'locLabel'      => 'Manufacturing unit',
                'locPick'       => 'Choose a unit…',
                'locHelp'       => $isOwner
                    ? 'The unit that manufactures this product. It decides which catalogue the item appears in on monline.'
                    : 'This product will be added to your unit.',

                'categories' => $repo->allowedCategories((int) $this->manufacturerId()),
                'masters'    => $repo->formMasters(),
                'images'     => $pid ? service('mediaRepository')->forProduct($pid) : [],
                'content'    => $pid ? $repo->content($pid) : [],
                'seo'        => $pid ? $repo->seo($pid) : [],
                'tagsCsv'    => $pid ? $repo->tagsCsv($pid) : '',
                'labelIds'   => $pid ? $repo->labelIds($pid) : [],
                'faqs'       => $pid ? $repo->faqs($pid) : [],
                'cattr'      => $pid ? $repo->customAttributes($pid) : [],
                'videos'     => $pid ? $repo->videos($pid) : [],
                'relations'  => $pid ? $repo->relations($pid) : [],
                'barcodes'   => $pid ? service('productBarcodeRepository')->forProduct($pid) : [],
                'documents'  => $pid ? service('mediaRepository')->documents($pid) : [],
                'shopLevels' => [],
                'mediaBase'  => '',

                'actionUrl' => $pid
                    ? site_url('manufacturer/products/' . $pid . '/update')
                    : site_url('manufacturer/products/store'),
                'backUrl'   => site_url('manufacturer/products'),
            ],
        );
    }

    /**
     * Store any uploaded images; returns a human message for files that were rejected.
     * Copied from Vendor\ProductController::maybeImage() — it is tenant-agnostic (works
     * off $productId/$uid alone), so reuse rather than reinvent. Only a plain
     * upload-and-attach is offered here; the vendor gallery's reorder/primary-toggle/
     * remove/media-library-picker is a much larger subsystem this fix does not add.
     */
    private function maybeImage(int $productId): string
    {
        $files = $this->request->getFileMultiple('image');
        if ($files === null) {
            $one   = $this->request->getFile('image');
            $files = $one !== null ? [$one] : [];
        }
        $uid    = (int) session()->get('user_id');
        $failed = [];
        foreach ((array) $files as $file) {
            if ($file === null) {
                continue;
            }
            if (! $file->isValid()) {
                if ($file->getError() !== UPLOAD_ERR_NO_FILE) {
                    $failed[] = ($file->getName() ?: 'image') . ' — ' . $file->getErrorString();
                }
                continue;
            }
            $res = service('mediaService')->store($file, 'product', $productId, $uid, 'public');
            if (! empty($res['ok'])) {
                service('mediaRepository')->attachToProduct($productId, (int) $res['id'], ! service('mediaRepository')->hasPrimary($productId), $uid);
            } else {
                $failed[] = ($file->getName() ?: 'image') . ' — ' . ($res['reason'] ?? 'rejected');
            }
        }

        return $failed === [] ? '' : count($failed) . ' image(s) not uploaded: ' . implode('; ', array_slice($failed, 0, 3));
    }
}
