<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use App\Libraries\Catalog\VendorPricing;
use App\Models\AdminProductRepository;
use CodeIgniter\HTTP\RedirectResponse;

/** Vendor\ProductController — the vendor's own products (list + create/edit, tenant-scoped). */
final class ProductController extends BaseVendorController
{
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $status = $this->request->getGet('status');
        // Branch managers see only their shop's products; owners see all.
        $shopId = $this->isOwner() ? null : $this->effectiveShopId();

        return $this->render('vendor/products/index', 'products', 'Products', [
            'products' => service('vendorProductRepository')->list($this->vendorId(), is_string($status) ? $status : null, $shopId),
        ]);
    }

    public function new()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (($block = $this->requireShopForManager()) !== null) {
            return $block;
        }

        return $this->form(null);
    }

    public function edit(int $id)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (($block = $this->requireShopForManager()) !== null) {
            return $block;
        }
        $product = service('adminProductRepository')->findById($id);
        if ($product === null || (int) $product['vendor_id'] !== $this->vendorId() || ! $this->managerOwnsShopFor($id)) {
            return redirect()->to('vendor/products')->with('error', 'Product not found.');
        }
        if (($product['status'] ?? '') === 'published') {
            return redirect()->to('vendor/products')->with('error', 'Unpublish this product before editing it.');
        }

        return $this->form($product);
    }

    public function store(): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $repo = service('adminProductRepository');
        $in   = $this->productInput();
        if (($err = $this->validateRow($in, true)) !== '') {
            return redirect()->back()->withInput()->with('error', $err);
        }
        $in['shop_ids'] = $this->resolveShopIds();
        if ($in['shop_ids'] === []) {
            return redirect()->back()->withInput()->with('error', 'Select a shop for this product.');
        }
        $allowed = array_column($repo->allowedCategories($this->vendorId()), 'id');
        if (! AdminProductRepository::isCategoryAllowed($allowed, $in['category_id'])) {
            return redirect()->back()->withInput()->with('error', 'That category is not allowed for your business type.');
        }
        if ($in['sku'] !== '' && $repo->skuExists($in['sku'])) {
            return redirect()->back()->withInput()->with('error', 'SKU already exists.');
        }
        $pid = $repo->createWithVariant($in, (int) session()->get('user_id'));
        if ($pid === null) {
            return redirect()->to('vendor/products')->with('error', 'Could not create product.');
        }
        $imgWarn = $this->maybeImage($pid);

        // Price / SKU / barcode / stock are set per item next, on the Variants page.
        return redirect()->to('vendor/products/' . $pid . '/variants')->with('success', 'Product created (draft). Now set price, stock & barcodes per item, then submit for approval.' . ($imgWarn ? ' ⚠ ' . $imgWarn : ''));
    }

    public function update(int $id): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $repo    = service('adminProductRepository');
        $product = $repo->findById($id);
        if ($product === null || (int) $product['vendor_id'] !== $this->vendorId() || ! $this->managerOwnsShopFor($id)) {
            return redirect()->to('vendor/products')->with('error', 'Product not found.');
        }
        if (($product['status'] ?? '') === 'published') {
            return redirect()->to('vendor/products')->with('error', 'Unpublish this product before editing it.');
        }
        $in = $this->productInput();
        if (($err = $this->validateRow($in, false)) !== '') {
            return redirect()->back()->withInput()->with('error', $err);
        }
        $allowed = array_column($repo->allowedCategories($this->vendorId()), 'id');
        if (! AdminProductRepository::isCategoryAllowed($allowed, $in['category_id'])) {
            return redirect()->back()->withInput()->with('error', 'That category is not allowed for your business type.');
        }
        $in['shop_ids'] = $this->resolveShopIds();
        if ($in['shop_ids'] === []) {
            return redirect()->back()->withInput()->with('error', 'Select a shop for this product.');
        }
        // S2: a staff member editing a LIVE product (anything past draft) routes
        // the change to the vendor (L1) then admin (L2). shop_ids is resolved above,
        // so the change request carries it. Draft edits stay direct.
        $isDraft = ($product['status'] ?? 'draft') === 'draft';
        if (! $this->isOwner() && ! $isDraft) {
            [$type, $msg] = $this->requestFlash($this->submitChangeRequest('product', 'update', $id, null, ['fields' => $in], 'fields'));

            return redirect()->to('vendor/products')->with($type, $msg);
        }

        $in['uuid'] = $product['uuid'];
        $repo->update($id, $in, (int) session()->get('user_id'));
        $imgWarn = $this->maybeImage($id);

        // Item 3: editing a non-draft (approved/unpublished/etc.) product sends it
        // back to draft, so it re-enters the submit → approval → publish flow.
        $reverted = ($product['status'] ?? 'draft') !== 'draft';
        if ($reverted) {
            $repo->revertToDraft($id, (int) session()->get('user_id'));
        }

        return redirect()->to('vendor/products')->with('success', ($reverted
            ? 'Product updated and moved back to draft — submit it for approval again.'
            : 'Product updated.') . ($imgWarn ? ' ⚠ ' . $imgWarn : ''));
    }

    public function delete(int $id): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $repo    = service('adminProductRepository');
        $product = $repo->findById($id);
        if ($product === null || (int) $product['vendor_id'] !== $this->vendorId() || ! $this->managerOwnsShopFor($id)) {
            return redirect()->to('vendor/products')->with('error', 'Product not found.');
        }
        if (! $this->isOwner() && ! $this->can('product.update')) {
            return redirect()->to('vendor/products')->with('error', "You don't have permission to delete products.");
        }
        if (($product['status'] ?? '') !== 'draft') {
            return redirect()->to('vendor/products')->with('error', 'Only a draft product can be deleted. Unpublish and move it back to draft first.');
        }
        $repo->softDeleteDraft($id, (int) session()->get('user_id'));

        return redirect()->to('vendor/products')->with('success', 'Draft product deleted.');
    }

    /** Apply one action (submit/publish/unpublish/delete) to many selected products. */
    public function bulk(): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $action = (string) $this->request->getPost('bulk_action');
        $ids    = array_values(array_filter(array_map('intval', (array) $this->request->getPost('ids'))));
        if ($action === '' || $ids === []) {
            return redirect()->to('vendor/products')->with('error', 'Select one or more products and an action.');
        }
        if (! $this->isOwner() && ! $this->can('product.update')) {
            return redirect()->to('vendor/products')->with('error', "You don't have permission for bulk actions.");
        }
        $repo = service('adminProductRepository');
        $appr = service('productApprovalRepository');
        $uid  = (int) session()->get('user_id');
        $ok   = 0;
        $skip = 0;
        foreach ($ids as $id) {
            $p = $repo->findById($id);
            if ($p === null || (int) $p['vendor_id'] !== $this->vendorId() || ! $this->managerOwnsShopFor($id)) {
                $skip++;
                continue;
            }
            $status = (string) ($p['status'] ?? '');
            $done   = match ($action) {
                'submit'    => in_array($status, ['draft', 'rejected'], true) && $appr->submit($id, $uid),
                'publish'   => in_array($status, ['approved', 'unpublished'], true) && $appr->publish($id, $uid),
                'unpublish' => $status === 'published' && $appr->unpublish($id, $uid, 'Bulk hide'),
                'delete'    => $status === 'draft' && $repo->softDeleteDraft($id, $uid),
                default     => false,
            };
            $done ? $ok++ : $skip++;
        }

        return redirect()->to('vendor/products')->with($ok > 0 ? 'success' : 'error',
            $ok . ' product(s) ' . $this->bulkVerb($action) . '.' . ($skip > 0 ? ' ' . $skip . ' skipped (not eligible for this action).' : ''));
    }

    private function bulkVerb(string $a): string
    {
        return ['submit' => 'submitted for approval', 'publish' => 'published', 'unpublish' => 'hidden', 'delete' => 'deleted'][$a] ?? 'updated';
    }

    /** Trash — soft-deleted drafts that can be restored. */
    public function trash()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        return $this->render('vendor/products/trash', 'products', 'Deleted drafts', [
            'products' => service('adminProductRepository')->listDeleted($this->vendorId()),
        ]);
    }

    public function restore(int $id): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $p = service('adminProductRepository')->findTrashed($id);
        if ($p === null || (int) $p['vendor_id'] !== $this->vendorId()) {
            return redirect()->to('vendor/products/trash')->with('error', 'Product not found.');
        }
        if (! $this->isOwner() && ! $this->can('product.update')) {
            return redirect()->to('vendor/products/trash')->with('error', "You don't have permission to restore products.");
        }
        $ok = service('adminProductRepository')->restoreDraft($id, (int) session()->get('user_id'));

        return redirect()->to('vendor/products' . ($ok ? '' : '/trash'))->with($ok ? 'success' : 'error', $ok ? 'Draft restored.' : 'Could not restore the product.');
    }

    public function draft()
    {
        if ($this->requireVendor() !== null) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }
        $categoryId = (int) $this->request->getPost('category_id');
        $repo       = service('adminProductRepository');
        $allowed    = array_column($repo->allowedCategories($this->vendorId()), 'id');
        if (! AdminProductRepository::isCategoryAllowed($allowed, $categoryId)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'message' => 'Pick an allowed category first.']);
        }
        $pid = $repo->draftCreate([
            'vendor_id' => $this->vendorId(), 'category_id' => $categoryId, 'title' => $this->request->getPost('title'),
            'tax_class_id' => $this->request->getPost('tax_class_id'), 'unit_id' => $this->request->getPost('unit_id'),
        ], (int) session()->get('user_id'));

        return $this->response->setJSON(['ok' => $pid !== null, 'product_id' => $pid, 'csrf' => csrf_hash()]);
    }

    public function autosaveSection(int $id, string $section)
    {
        if ($this->requireVendor() !== null) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }
        $product = service('adminProductRepository')->findById($id);
        if ($product === null || (int) $product['vendor_id'] !== $this->vendorId()) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false]);
        }
        $d = (array) $this->request->getPost();
        $d['vendor_id'] = $this->vendorId();   // tags saver scopes by vendor
        service('adminProductRepository')->autosave($id, $section, $d, (int) session()->get('user_id'));

        return $this->response->setJSON(['ok' => true, 'saved_at' => date('H:i:s'), 'csrf' => csrf_hash()]);
    }

    public function aiSuggest()
    {
        if ($this->vendorId() <= 0) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        return $this->response->setJSON(['ok' => true, 'data' => service('aiProductAssistant')->suggest(
            (string) $this->request->getPost('kind'),
            ['title' => $this->request->getPost('title'), 'category' => $this->request->getPost('category'), 'brand' => $this->request->getPost('brand')],
        )]);
    }

    public function duplicate(int $id): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $product = service('adminProductRepository')->findById($id);
        if ($product === null || (int) $product['vendor_id'] !== $this->vendorId()) {
            return redirect()->to('vendor/products')->with('error', 'Product not found.');
        }
        $newId = service('adminProductRepository')->duplicate($id, (int) session()->get('user_id'));

        return $newId !== null
            ? redirect()->to('vendor/products/' . $newId . '/edit')->with('success', 'Product duplicated — edit the copy.')
            : redirect()->to('vendor/products')->with('error', 'Could not duplicate the product.');
    }

    public function submit(int $id): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $product = service('adminProductRepository')->findById($id);
        if ($product === null || (int) $product['vendor_id'] !== $this->vendorId()) {
            return redirect()->to('vendor/products')->with('error', 'Product not found.');
        }
        // S2: a staff member cannot put a product live on their own — submit goes
        // to the vendor (L1) then admin (L2). The owner submits straight into the
        // existing product-approval gate (which already routes to admin).
        if (! $this->isOwner()) {
            [$type, $msg] = $this->requestFlash($this->submitChangeRequest('product', 'submit', $id, null, ['product_id' => $id, 'title' => $product['title'] ?? ''], 'submit'));

            return redirect()->to('vendor/products')->with($type, $msg);
        }
        $ok = service('productApprovalRepository')->submit($id, (int) session()->get('user_id'));

        return redirect()->to('vendor/products')->with($ok ? 'success' : 'error', $ok ? 'Submitted for approval.' : "This product can't be submitted from its current status.");
    }

    /** Vendor makes an APPROVED (or previously hidden) product live on the storefront. */
    public function publish(int $id): RedirectResponse
    {
        if ($denied = $this->guardPublish($id)) {
            return $denied;
        }
        $ok = service('productApprovalRepository')->publish($id, (int) session()->get('user_id'));

        return redirect()->to('vendor/products')->with($ok ? 'success' : 'error', $ok ? 'Product is now live on the store.' : 'Only an approved product can be published.');
    }

    /** Vendor hides a published product from the storefront (kept, not deleted). */
    public function unpublish(int $id): RedirectResponse
    {
        if ($denied = $this->guardPublish($id)) {
            return $denied;
        }
        $ok = service('productApprovalRepository')->unpublish($id, (int) session()->get('user_id'), 'Hidden by vendor');

        return redirect()->to('vendor/products')->with($ok ? 'success' : 'error', $ok ? 'Product hidden from the store.' : 'Only a published product can be hidden.');
    }

    /** Owner (or a product.update manager) controls storefront visibility of their own product. */
    private function guardPublish(int $id): ?RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $product = service('adminProductRepository')->findById($id);
        if ($product === null || (int) $product['vendor_id'] !== $this->vendorId()) {
            return redirect()->to('vendor/products')->with('error', 'Product not found.');
        }
        if (! $this->isOwner() && ! $this->can('product.update')) {
            return redirect()->to('vendor/products')->with('error', "You don't have permission to change store visibility.");
        }

        return null;
    }

    /** @param array<string,mixed>|null $product */
    private function form(?array $product)
    {
        $repo = service('adminProductRepository');
        $pid  = $product ? (int) $product['id'] : 0;

        // S2 — which shops this product is (or will be) sold in. A branch manager
        // builds only for their pinned shop; an owner picks among all their shops.
        $isOwner    = $this->isOwner();
        $activeShop = $this->activeShopId();
        $shops      = $this->allowedShops();
        if (! $isOwner) {
            $shops = array_values(array_filter($shops, static fn ($s) => (int) $s['id'] === (int) $activeShop));
        }
        $shopIds  = array_map(static fn ($s) => (int) $s['id'], $shops);
        $selected = $pid
            ? service('productShopRepository')->forProduct($pid)
            : ($activeShop ? [(int) $activeShop] : ($isOwner && count($shopIds) === 1 ? $shopIds : []));

        return $this->render('vendor/products/form', 'products', $product ? 'Edit Product' : 'New Product', [
            'product' => $product, 'vendorId' => $this->vendorId(),
            'ctx' => $isOwner ? 'vendor_owner' : 'vendor_shop_manager', 'vendors' => [],
            'shops' => $shops, 'selectedShops' => array_map('intval', $selected),
            'shopLevels' => $pid ? $repo->shopStockLevels($pid, $shopIds) : [], 'lockShops' => ! $isOwner,
            'categories' => $repo->allowedCategories($this->vendorId()),
            'masters' => $repo->formMasters(),
            'images' => $pid ? service('mediaRepository')->forProduct($pid) : [],
            'content' => $pid ? $repo->content($pid) : [],
            'seo'     => $pid ? $repo->seo($pid) : [],
            'tagsCsv' => $pid ? $repo->tagsCsv($pid) : '',
            'labelIds' => $pid ? $repo->labelIds($pid) : [],
            'faqs'    => $pid ? $repo->faqs($pid) : [],
            'cattr'   => $pid ? $repo->customAttributes($pid) : [],
            'videos'  => $pid ? $repo->videos($pid) : [],
            'relations' => $pid ? $repo->relations($pid) : [],
            'barcodes' => $pid ? service('productBarcodeRepository')->forProduct($pid) : [],
            'documents' => $pid ? service('mediaRepository')->documents($pid) : [],
            'mediaBase' => $pid ? site_url('vendor/products/' . $pid . '/media/') : '',
            'actionUrl' => $product ? site_url('vendor/products/' . $pid . '/update') : site_url('vendor/products/store'),
            'backUrl'   => site_url('vendor/products'),
        ]);
    }

    /**
     * Typed essentials merged over the raw POST so the rich product-module fields
     * flow through. vendor_id is ALWAYS the logged-in vendor (never from input).
     * @return array<string,mixed>
     */
    private function productInput(): array
    {
        $p = fn (string $k): string => trim((string) $this->request->getPost($k));

        // MRP / selling-price invariant — LOG-ONLY for now, deliberately.
        //
        // The rule (0 < base_price <= mrp) is not currently enforced anywhere on the
        // vendor side, so an unknown number of existing listings already violate it.
        // Blocking straight away would reject saves that have always been accepted, on
        // a live panel. Per the project's rollout convention this ships log-only first:
        // one traffic day tells us how many real vendors trip it, and whether a data
        // cleanup is needed before it can bite. Promote to a hard failure — the same
        // `if ($err) return redirect()->back()->with('error', $err)` the manufacturer
        // controller uses — only after that review.
        if (($pricingErr = VendorPricing::validate((array) $this->request->getPost(), false)) !== '') {
            log_message('warning', 'vendor pricing invariant [log-only] vendor={vendor} product={product}: {err}', [
                'vendor'  => (string) $this->vendorId(),
                'product' => (string) ($this->request->getPost('id') ?? 'new'),
                'err'     => $pricingErr,
            ]);
        }

        return array_merge((array) $this->request->getPost(), [
            'vendor_id' => $this->vendorId(),
            'category_id' => (int) $this->request->getPost('category_id'),
            'brand_id' => (int) $this->request->getPost('brand_id') ?: null,
            'tax_class_id' => (int) $this->request->getPost('tax_class_id'),
            'unit_id' => (int) $this->request->getPost('unit_id'),
            'title' => $p('title'), 'description' => $p('description'),
            'sku' => $p('sku'), 'barcode' => $p('barcode') ?: null,
            'mrp' => $p('mrp') !== '' ? $p('mrp') : '0', 'base_price' => $p('base_price') !== '' ? $p('base_price') : '0',
        ]);
    }

    /**
     * The single shop this product is added to, scoped to what the user may touch.
     * Owner: the submitted shop_id if it's one of theirs. Branch manager: forced to
     * their pinned shop (so a manager never has to wonder \"which shop\").
     * @return list<int> zero or one shop id
     */
    private function resolveShopIds(): array
    {
        if (! $this->isOwner()) {
            $active = $this->activeShopId();

            return $active ? [(int) $active] : [];
        }
        $shopId = (int) $this->request->getPost('shop_id');

        return $shopId > 0 && in_array($shopId, $this->allowedShopIds(), true) ? [$shopId] : [];
    }

    /** Block a branch manager with no assigned shop from the product builder. */
    private function requireShopForManager(): ?RedirectResponse
    {
        if (! $this->isOwner() && $this->activeShopId() === null) {
            return redirect()->to('vendor/products')->with('error', 'You must be assigned to a shop to add or edit products.');
        }

        return null;
    }

    /** A branch manager may only touch a product listed in their active shop; an owner may touch any of their own. */
    private function managerOwnsShopFor(int $productId): bool
    {
        if ($this->isOwner()) {
            return true;
        }
        $active = $this->activeShopId();

        return $active !== null && in_array((int) $active, service('productShopRepository')->forProduct($productId), true);
    }

    /** @param array<string,mixed> $in  SKU/price/barcode are set on the Variants page, not here. */
    private function validateRow(array $in, bool $isCreate): string
    {
        if ($in['title'] === '') { return 'Title is required.'; }
        if ($in['category_id'] <= 0) { return 'Category is required.'; }
        if ($in['tax_class_id'] <= 0 || $in['unit_id'] <= 0) { return 'Tax class and unit are required.'; }

        return '';
    }

    /** Store any uploaded images; returns a human message for files that were rejected. */
    private function maybeImage(int $productId): string
    {
        $files = $this->request->getFileMultiple('image');         // name="image[]" (multi)
        if ($files === null) {
            $one   = $this->request->getFile('image');             // single-field fallback
            $files = $one !== null ? [$one] : [];
        }
        $uid    = (int) session()->get('user_id');
        $failed = [];
        foreach ((array) $files as $file) {
            if ($file === null) {
                continue;
            }
            if (! $file->isValid()) {
                // skip empty inputs silently; report real upload errors (e.g. too large for PHP)
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
