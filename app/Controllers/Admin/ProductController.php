<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AdminProductRepository;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\ProductController — create/edit products (with a default variant) for any
 * vendor. Enforces category∈vendor-business-type gating, unique SKU, image upload
 * via MediaService, and audit logging. Guarded by product.* permissions; POSTs
 * CSRF-protected.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md
 */
final class ProductController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('product.view')) {
            return $denied;
        }
        $req      = $this->request;
        $vendorId = is_numeric($req->getGet('vendor_id')) ? (int) $req->getGet('vendor_id') : null;
        $catId    = is_numeric($req->getGet('category_id')) ? (int) $req->getGet('category_id') : null;

        $f = [
            'vendor_id'    => $vendorId,
            'status'       => trim((string) $req->getGet('status')),
            'q'            => trim((string) $req->getGet('q')),
            'category_id'  => $catId,
            'product_type' => trim((string) $req->getGet('type')),
        ];

        // Pagination — replaces the old hard 200-row cap. "all" shows everything.
        $perRaw  = (string) $req->getGet('per_page');
        $all     = $perRaw === 'all';
        $perPage = in_array((int) $perRaw, [25, 50, 100, 200, 500], true) ? (int) $perRaw : 50;
        $page    = max(1, (int) $req->getGet('page'));

        $repo  = service('adminProductRepository');
        $total = $repo->countList($f);

        $f['limit']  = $all ? 0 : $perPage;
        $f['offset'] = $all ? 0 : ($page - 1) * $perPage;

        return view('admin/products/index', [
            'title' => 'Products · Admin', 'pageTitle' => 'Products', 'active' => 'products',
            'userName'     => session()->get('user_name') ?: 'User',
            'products'     => $repo->list($f),
            'vendors'      => service('shopRepository')->vendorsForSelect(),
            'categories'   => service('categoryRepository')->options(),
            'productTypes' => ['simple', 'variant', 'bundle', 'combo', 'digital', 'service', 'subscription', 'giftcard', 'downloadable', 'rental'],
            'statuses'     => ['draft', 'submitted', 'under_review', 'approved', 'published', 'rejected', 'unpublished'],
            'vendorId'     => $vendorId,
            'filters'      => $f,
            'perPage'      => $all ? 'all' : $perPage,
            'page'         => $page,
            'total'        => $total,
        ]);
    }

    public function export()
    {
        if ($denied = $this->guard('product.view')) {
            return $denied;
        }
        $req  = $this->request;
        $rows = service('adminProductRepository')->list([
            'vendor_id'    => is_numeric($req->getGet('vendor_id')) ? (int) $req->getGet('vendor_id') : null,
            'status'       => trim((string) $req->getGet('status')),
            'q'            => trim((string) $req->getGet('q')),
            'category_id'  => is_numeric($req->getGet('category_id')) ? (int) $req->getGet('category_id') : null,
            'product_type' => trim((string) $req->getGet('type')),
            'limit'        => 0, // export everything that matches the filters
        ]);

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['id', 'title', 'vendor', 'category', 'type', 'sku', 'mrp', 'selling_price', 'status']);
        foreach ($rows as $r) {
            fputcsv($out, array_map([self::class, 'csvCell'], [$r['id'], $r['title'], $r['vendor'] ?? '', $r['category'] ?? '', $r['product_type'] ?? 'simple', $r['sku'] ?? '', $r['mrp'] ?? '', $r['base_price'] ?? '', $r['status']]));
        }
        if (count($rows) >= AdminProductRepository::MAX_ROWS) {
            // list() now caps at MAX_ROWS instead of attempting every matching row —
            // make a truncated export visible rather than silently handing back a
            // partial file that looks complete.
            fputcsv($out, ['# export truncated at ' . AdminProductRepository::MAX_ROWS . ' rows — narrow your filters for the rest']);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        unset($rows);

        return $this->response->setHeader('Content-Type', 'text/csv')
            ->setHeader('Content-Disposition', 'attachment; filename="products-' . date('Ymd') . '.csv"')
            ->setBody($csv);
    }

    public function draft()
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), 'product.create')) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }
        $vendorId   = (int) $this->request->getPost('vendor_id');
        $categoryId = (int) $this->request->getPost('category_id');
        $repo       = service('adminProductRepository');
        $allowed    = array_column($repo->allowedCategories($vendorId), 'id');
        if ($vendorId <= 0 || ! AdminProductRepository::isCategoryAllowed($allowed, $categoryId)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'message' => 'Pick a vendor and an allowed category first.']);
        }
        $pid = $repo->draftCreate([
            'vendor_id' => $vendorId, 'category_id' => $categoryId, 'title' => $this->request->getPost('title'),
            'tax_class_id' => $this->request->getPost('tax_class_id'), 'unit_id' => $this->request->getPost('unit_id'),
        ], (int) session()->get('user_id'));

        return $this->response->setJSON(['ok' => $pid !== null, 'product_id' => $pid, 'csrf' => csrf_hash()]);
    }

    public function autosaveSection(int $id, string $section)
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), 'product.update')) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }
        if (service('adminProductRepository')->findById($id) === null) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false]);
        }
        service('adminProductRepository')->autosave($id, $section, (array) $this->request->getPost(), (int) session()->get('user_id'));

        return $this->response->setJSON(['ok' => true, 'saved_at' => date('H:i:s'), 'csrf' => csrf_hash()]);
    }

    public function aiSuggest()
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), 'product.create') && ! service('policyEngine')->canPlatform(service('scopeContext')->all(), 'product.update')) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        return $this->response->setJSON(['ok' => true, 'data' => service('aiProductAssistant')->suggest(
            (string) $this->request->getPost('kind'),
            ['title' => $this->request->getPost('title'), 'category' => $this->request->getPost('category'), 'brand' => $this->request->getPost('brand')],
        )]);
    }

    public function new()
    {
        if ($denied = $this->guard('product.create')) {
            return $denied;
        }
        // Vendor is now the first field ON the form (it cascades categories +
        // shops via AJAX), so there's no separate pick-vendor step. A ?vendor_id
        // still pre-selects the vendor (e.g. when arriving from a vendor"s page).
        return $this->form(null, (int) $this->request->getGet('vendor_id'));
    }

    public function store(): RedirectResponse
    {
        if ($denied = $this->guard('product.create')) {
            return $denied;
        }
        $in  = $this->productInput();
        $err = $this->validateProduct($in, true);
        if ($err !== '') {
            return redirect()->back()->withInput()->with('error', $err);
        }
        $in['shop_ids'] = $this->resolveShopIds($in['vendor_id']);
        if ($in['shop_ids'] === []) {
            return redirect()->back()->withInput()->with('error', 'Select a shop for this product.');
        }

        $repo    = service('adminProductRepository');
        $allowed = array_column($repo->allowedCategories($in['vendor_id']), 'id');
        if (! AdminProductRepository::isCategoryAllowed($allowed, $in['category_id'])) {
            return redirect()->back()->withInput()->with('error', "That category is not allowed for this vendor\'s business type.");
        }
        if ($in['sku'] !== '' && $repo->skuExists($in['sku'])) {
            return redirect()->back()->withInput()->with('error', 'SKU already exists.');
        }

        $productId = $repo->createWithVariant($in, (int) session()->get('user_id'));
        if ($productId === null) {
            return redirect()->back()->withInput()->with('error', 'Could not create product.');
        }

        $imgWarn = $this->maybeAttachImage($productId);

        // Admin-created products start as a DRAFT owned by the chosen vendor; set
        // price/stock per item, then it must be Submitted → Approved → Published.
        return redirect()->to('admin/products/' . $productId . '/variants')->with('success', 'Product created as a DRAFT for the selected vendor. Set price & stock per item, then Submit → Approve → Publish to make it live.' . ($imgWarn ? ' ⚠ ' . $imgWarn : ''));
    }

    public function edit(int $id)
    {
        if ($denied = $this->guard('product.update')) {
            return $denied;
        }
        $product = service('adminProductRepository')->findById($id);
        if ($product === null) {
            return redirect()->to('admin/products')->with('error', 'Product not found.');
        }
        if (($product['status'] ?? '') === 'published') {
            return redirect()->to('admin/products')->with('error', 'Unpublish this product before editing it.');
        }

        return $this->form($product, (int) $product['vendor_id']);
    }

    public function update(int $id): RedirectResponse
    {
        if ($denied = $this->guard('product.update')) {
            return $denied;
        }
        $repo    = service('adminProductRepository');
        $product = $repo->findById($id);
        if ($product === null) {
            return redirect()->to('admin/products')->with('error', 'Product not found.');
        }
        if (($product['status'] ?? '') === 'published') {
            return redirect()->to('admin/products')->with('error', 'Unpublish this product before editing it.');
        }
        $in  = $this->productInput();
        $in['vendor_id'] = (int) $product['vendor_id'];
        $err = $this->validateProduct($in, false);
        if ($err !== '') {
            return redirect()->back()->withInput()->with('error', $err);
        }
        $allowed = array_column($repo->allowedCategories((int) $product['vendor_id']), 'id');
        if (! AdminProductRepository::isCategoryAllowed($allowed, $in['category_id'])) {
            return redirect()->back()->withInput()->with('error', "That category is not allowed for this vendor\'s business type.");
        }
        $in['uuid'] = $product['uuid'];
        $in['shop_ids'] = $this->resolveShopIds((int) $product['vendor_id']);
        if ($in['shop_ids'] === []) {
            return redirect()->back()->withInput()->with('error', 'Select a shop for this product.');
        }
        $repo->update($id, $in, (int) session()->get('user_id'));
        $imgWarn = $this->maybeAttachImage($id);

        // Item 3: editing a non-draft product sends it back to draft for re-approval.
        $reverted = ($product['status'] ?? 'draft') !== 'draft';
        if ($reverted) {
            $repo->revertToDraft($id, (int) session()->get('user_id'));
        }

        return redirect()->to('admin/products')->with('success', ($reverted
            ? 'Product updated and moved back to draft — it must be re-approved and published.'
            : 'Product updated.') . ($imgWarn ? ' ⚠ ' . $imgWarn : ''));
    }

    public function delete(int $id): RedirectResponse
    {
        if ($denied = $this->guard('product.update')) {
            return $denied;
        }
        $repo    = service('adminProductRepository');
        $product = $repo->findById($id);
        if ($product === null) {
            return redirect()->to('admin/products')->with('error', 'Product not found.');
        }
        if (($product['status'] ?? '') !== 'draft') {
            return redirect()->to('admin/products')->with('error', 'Only a draft product can be deleted.');
        }
        $repo->softDeleteDraft($id, (int) session()->get('user_id'));

        return redirect()->to('admin/products')->with('success', 'Draft product deleted.');
    }

    /** Apply one action (submit/publish/unpublish/delete) to many selected products. */
    public function bulk(): RedirectResponse
    {
        if ($denied = $this->guard('product.update')) {
            return $denied;
        }
        $action = (string) $this->request->getPost('bulk_action');
        $ids    = array_values(array_filter(array_map('intval', (array) $this->request->getPost('ids'))));
        if ($action === '' || $ids === []) {
            return redirect()->to('admin/products')->with('error', 'Select one or more products and an action.');
        }
        $repo = service('adminProductRepository');
        $appr = service('productApprovalRepository');
        $uid  = (int) session()->get('user_id');
        $ok   = 0;
        $skip = 0;
        foreach ($ids as $id) {
            $p = $repo->findById($id);
            if ($p === null) {
                $skip++;
                continue;
            }
            $status = (string) ($p['status'] ?? '');
            $done   = match ($action) {
                'approve'   => in_array($status, ['submitted', 'under_review'], true) && $appr->approve($id, $uid),
                'publish'   => in_array($status, ['approved', 'unpublished'], true) && $appr->publish($id, $uid),
                'unpublish' => $status === 'published' && $appr->unpublish($id, $uid, 'Bulk hide'),
                'delete'    => $status === 'draft' && $repo->softDeleteDraft($id, $uid),
                default     => false,
            };
            $done ? $ok++ : $skip++;
        }
        $verb = ['approve' => 'approved', 'publish' => 'published', 'unpublish' => 'hidden', 'delete' => 'deleted'][$action] ?? 'updated';

        return redirect()->to('admin/products')->with($ok > 0 ? 'success' : 'error',
            $ok . ' product(s) ' . $verb . '.' . ($skip > 0 ? ' ' . $skip . ' skipped (not eligible for this action).' : ''));
    }

    /** Trash — soft-deleted drafts that can be restored. */
    public function trash()
    {
        if ($denied = $this->guard('product.view')) {
            return $denied;
        }
        $vendorId = $this->request->getGet('vendor_id');

        return view('admin/products/trash', [
            'title' => 'Deleted drafts · Admin', 'pageTitle' => 'Deleted drafts', 'active' => 'products',
            'userName' => session()->get('user_name') ?: 'User',
            'products' => service('adminProductRepository')->listDeleted(is_numeric($vendorId) ? (int) $vendorId : null),
        ]);
    }

    public function restore(int $id): RedirectResponse
    {
        if ($denied = $this->guard('product.update')) {
            return $denied;
        }
        if (service('adminProductRepository')->findTrashed($id) === null) {
            return redirect()->to('admin/products/trash')->with('error', 'Product not found.');
        }
        $ok = service('adminProductRepository')->restoreDraft($id, (int) session()->get('user_id'));

        return redirect()->to('admin/products' . ($ok ? '' : '/trash'))->with($ok ? 'success' : 'error', $ok ? 'Draft restored.' : 'Could not restore the product.');
    }

    public function duplicate(int $id): RedirectResponse
    {
        if ($denied = $this->guard('product.create')) {
            return $denied;
        }
        $repo    = service('adminProductRepository');
        $product = $repo->findById($id);
        if ($product === null) {
            return redirect()->to('admin/products')->with('error', 'Product not found.');
        }
        // optional cross-vendor copy: the target vendor's business type must allow the category
        $target = (int) $this->request->getPost('target_vendor_id');
        if ($target > 0 && $target !== (int) $product['vendor_id']) {
            $allowed = array_column($repo->allowedCategories($target), 'id');
            if (! AdminProductRepository::isCategoryAllowed($allowed, (int) $product['category_id'])) {
                return redirect()->to('admin/products')->with('error', "That category isn't allowed for the target vendor's business type.");
            }
        } else {
            $target = 0;
        }
        $newId = $repo->duplicate($id, (int) session()->get('user_id'), $target ?: null);

        return $newId !== null
            ? redirect()->to('admin/products/' . $newId . '/edit')->with('success', $target ? 'Product copied to the selected vendor — edit the copy.' : 'Product duplicated — edit the copy.')
            : redirect()->to('admin/products')->with('error', 'Could not duplicate the product.');
    }

    /** @param array<string,mixed>|null $product */
    private function form(?array $product, int $vendorId): string
    {
        $repo = service('adminProductRepository');
        $pid  = $product ? (int) $product['id'] : 0;

        // S2 — shops for the chosen vendor (empty until a vendor is picked on a
        // new product; the form\'s vendor cascade fills them in via AJAX).
        $shops    = $vendorId > 0 ? service('productShopRepository')->shopsForVendor($vendorId) : [];
        $shopIds  = array_map(static fn ($s) => (int) $s['id'], $shops);
        $selected = $pid ? service('productShopRepository')->forProduct($pid) : [];

        return view('admin/products/form', [
            'title' => ($product ? 'Edit' : 'New') . ' Product · Admin',
            'pageTitle' => $product ? 'Edit Product' : 'New Product', 'active' => 'products',
            'userName' => session()->get('user_name') ?: 'User',
            'product' => $product, 'vendorId' => $vendorId,
            'ctx' => 'admin', 'vendors' => service('shopRepository')->vendorsForSelect(),
            'shops' => $shops, 'selectedShops' => array_map('intval', $selected),
            'shopLevels' => $pid ? $repo->shopStockLevels($pid, $shopIds) : [], 'lockShops' => false,
            'categories' => $vendorId > 0 ? $repo->allowedCategories($vendorId) : [],
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
            'mediaBase' => $pid ? site_url('admin/products/' . $pid . '/media/') : '',
            'actionUrl' => $product ? site_url('admin/products/' . $pid . '/update') : site_url('admin/products/store'),
            'backUrl'   => site_url('admin/products'),
        ]);
    }

    /**
     * Typed essentials (validation/gating) merged over the raw POST, so the rich
     * product-module fields (content, SEO, tags, labels, FAQs, custom specs,
     * relations, videos, rules…) flow straight through to the repository.
     * @return array<string,mixed>
     */
    private function productInput(): array
    {
        $p = static fn (string $k): string => trim((string) service('request')->getPost($k));

        return array_merge((array) service('request')->getPost(), [
            'vendor_id' => (int) service('request')->getPost('vendor_id'),
            'category_id' => (int) service('request')->getPost('category_id'),
            'brand_id' => (int) service('request')->getPost('brand_id') ?: null,
            'tax_class_id' => (int) service('request')->getPost('tax_class_id'),
            'unit_id' => (int) service('request')->getPost('unit_id'),
            'title' => $p('title'), 'description' => $p('description'),
            'sku' => $p('sku'), 'barcode' => $p('barcode') ?: null,
            'mrp' => $p('mrp') !== '' ? $p('mrp') : '0',
            'base_price' => $p('base_price') !== '' ? $p('base_price') : '0',
        ]);
    }

    /** The single submitted shop, if it belongs to the chosen vendor. @return list<int> zero or one id */
    private function resolveShopIds(int $vendorId): array
    {
        if ($vendorId <= 0) {
            return [];
        }
        $shopId  = (int) service('request')->getPost('shop_id');
        $allowed = array_map(static fn ($s) => (int) $s['id'], service('productShopRepository')->shopsForVendor($vendorId));

        return $shopId > 0 && in_array($shopId, $allowed, true) ? [$shopId] : [];
    }

    /** @param array<string,mixed> $in  SKU/price/barcode are set on the Variants page, not here. */
    private function validateProduct(array $in, bool $isCreate): string
    {
        if ($in['vendor_id'] <= 0) { return 'Vendor is required.'; }
        if ($in['title'] === '') { return 'Title is required.'; }
        if ($in['category_id'] <= 0) { return 'Category is required.'; }
        if ($in['tax_class_id'] <= 0 || $in['unit_id'] <= 0) { return 'Tax class and unit are required.'; }

        // Numeric sanity for pricing + purchase-rule fields (typed DECIMAL/INT
        // columns must never receive negative/zero-step/inverted values, which
        // would silently break PurchaseRules + storefront pricing).
        foreach (['min_purchase_qty', 'max_purchase_qty', 'qty_step', 'mrp', 'base_price'] as $k) {
            if (isset($in[$k]) && $in[$k] !== '' && ! is_numeric($in[$k])) {
                return ucfirst(str_replace('_', ' ', $k)) . ' must be a number.';
            }
        }
        $num  = static fn (string $k): ?float => isset($in[$k]) && $in[$k] !== '' && is_numeric($in[$k]) ? (float) $in[$k] : null;
        $min  = $num('min_purchase_qty');
        $max  = $num('max_purchase_qty');
        $step = $num('qty_step');
        if ($min !== null && $min < 0) { return 'Minimum quantity cannot be negative.'; }
        if ($max !== null && $max < 0) { return 'Maximum quantity cannot be negative.'; }
        if ($step !== null && $step <= 0) { return 'Quantity step must be greater than zero.'; }
        if ($min !== null && $max !== null && $max > 0 && $max < $min) {
            return 'Maximum quantity must be greater than or equal to minimum quantity.';
        }
        if (($mrp = $num('mrp')) !== null && $mrp < 0) { return 'MRP cannot be negative.'; }
        if (($base = $num('base_price')) !== null && $base < 0) { return 'Selling price cannot be negative.'; }

        return '';
    }

    /** Store any uploaded images; returns a human message for files that were rejected. */
    private function maybeAttachImage(int $productId): string
    {
        $files = $this->request->getFileMultiple('image');         // name='image[]" (multi)
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

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }

    /**
     * Neutralise spreadsheet formula injection in an exported cell.
     *
     * Excel/Calc/Sheets evaluate any cell whose text begins with = + - @ TAB or CR as a
     * formula; fputcsv()'s quoting does not stop that, because the CSV parser consumes
     * the quotes before the value is interpreted. The columns exported here carry
     * vendor- and customer-supplied free text, so this is the one place their input
     * crosses out of the browser sandbox onto an operator's machine. A leading
     * apostrophe is the standard text marker and is not displayed by the reader.
     */
    private static function csvCell(mixed $v): string
    {
        $s = (string) $v;

        return $s !== '' && str_contains("=+-@\t\r", $s[0]) ? "'" . $s : $s;
    }
}
