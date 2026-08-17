<?php
/**
 * Redesigned product form SHELL (Admin + Vendor) — sticky bar + context rail +
 * accordion + live preview, replacing the old 11-tab layout. Field names are
 * unchanged so the existing store/update POST and autosave both work; the
 * accordion + cascading loads + completeness meter are progressive enhancements.
 *
 * Expects: $product, $vendorId, $categories, $masters, $images, $content, $seo,
 * $tagsCsv, $labelIds, $faqs, $cattr, $videos, $relations, $actionUrl, $backUrl.
 */
$isEdit = ($product ?? null) !== null;
$pid    = $isEdit ? (int) $product['id'] : 0;
$g  = static fn (string $k, $d = '') => $isEdit ? ($product[$k] ?? $d) : (old($k) ?? $d);
$gc = static fn (string $k, $d = '') => ($content[$k] ?? $d);
$gs = static fn (string $k, $d = '') => ($seo[$k] ?? $d);
$sel = static fn ($a, $b): string => (string) $a === (string) $b ? 'selected' : '';
$chk = static fn ($cond): string => $cond ? 'checked' : '';
$enum = static fn (string $cur, string $v): string => $cur === $v ? 'selected' : '';
$relNow = $relations ?? [];
// Sibling URLs (ai-suggest, autosave, variants) derived from $actionUrl so callers
// need not pass them. Manufacturer joined vendor and admin here; the order matters
// only in that each panel's own segment appears in its own action URL.
$panelBase = str_contains((string) $actionUrl, '/manufacturer/')
    ? 'manufacturer/products/'
    : (str_contains((string) $actionUrl, '/vendor/') ? 'vendor/products/' : 'admin/products/');
$aiUrl  = site_url($panelBase . 'ai-suggest');
$asBase = site_url($panelBase);
// S2 shop-assignment context (defaulted so the partial stays safe if a caller omits them).
// One shop per product (owner's confirmed add flow): vendor -> shop -> type -> info.
$ctx           = $ctx ?? 'admin';
$vendorId      = $vendorId ?? 0;
$vendors       = $vendors ?? [];
$shops         = $shops ?? [];
$selectedShops = array_map('intval', $selectedShops ?? []);
$lockShops     = $lockShops ?? false;
$shopId        = $selectedShops[0] ?? 0;
// The "where is this sold/made" picker. Vendors and admins pick a shop; manufacturers
// pick a manufacturing unit, which is an `mshops` row and posts as mshop_id. Only the
// field name and the words differ — the control is identical — so it is parameterised
// rather than duplicated, with defaults that leave vendor/admin output unchanged.
$locField = $locField ?? 'shop_id';
$locLabel = $locLabel ?? 'Shop';
$locPick  = $locPick ?? 'Choose a shop…';
$locHelp  = $locHelp ?? ($lockShops
    ? 'This product will be added to your shop.'
    : 'The shop whose catalogue &amp; POS will carry this product.');
$variantsUrl   = $pid ? $asBase . $pid . '/variants' : '';
// accordion section meta: id => [title, icon, section-key for autosave].
// SKU / price / MRP / barcode / stock live on the Variants page, not here.
$sections = [
    'basics'   => ['Basics', 'card-text', 'basics'],
    'pricing'  => ['Tax & Units', 'tag', null],
    'content'  => ['Content', 'file-text', 'content'],
    'media'    => ['Media', 'images', null],
    'rules'    => ['Purchase Policy', 'sliders', 'rules'],
    'shipping' => ['Shipping', 'truck', null],
    'seo'      => ['SEO', 'search', 'seo'],
    'tags'     => ['Visibility & Tags', 'eye', 'tags'],
    'faq'      => ['FAQ', 'question-circle', null],
    'specs'    => ['Specs', 'list-check', null],
    'relations' => ['Relations', 'diagram-3', null],
];
?>
<link rel="stylesheet" href='<?= asset('plugins/quill/quill.snow.css') ?>'>
<link rel="stylesheet" href='<?= asset('plugins/select2/select2.min.css') ?>'>
<link rel="stylesheet" href='<?= asset('plugins/select2/select2-bootstrap-5-theme.min.css') ?>'>
<style>
.pf-rail{position:sticky;top:84px}.pf-bar{position:sticky;top:0;z-index:1020}
.pf-meter{height:6px;border-radius:3px;background:#eef0f6;overflow:hidden}.pf-meter>span{display:block;height:100%;background:#0d6efd;transition:width .3s}
.pf-acc .accordion-button{font-weight:600}.pf-ctx-row{display:flex;justify-content:space-between;font-size:.85rem;padding:.2rem 0;border-bottom:1px dashed #eef0f6}
.js-quill.ql-container{min-height:140px}.js-quill .ql-editor{min-height:140px;max-height:340px;overflow-y:auto}
.pf-dz{border:2px dashed #ced4da;border-radius:.5rem;background:#f8f9fa;cursor:pointer;transition:border-color .15s,background .15s}.pf-dz.drag{border-color:#0d6efd;background:#eef4ff}
</style>

<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" id="productForm" action="<?= $actionUrl ?>"
      data-ai-url="<?= esc($aiUrl, 'attr') ?>" data-pid="<?= $pid ?>" data-autosave-base="<?= esc($asBase, 'attr') ?>" data-media-base="<?= esc($mediaBase ?? '', 'attr') ?>">
    <?= csrf_field() ?>
    <?php if ($ctx !== 'admin'): ?><input type="hidden" name="vendor_id" value="<?= esc($vendorId, 'attr') ?>"><?php endif; ?>

    <!-- STICKY TOP BAR -->
    <div class="pf-bar bg-white border-bottom py-2 mb-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <a href="<?= $backUrl ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i></a>
            <span class="fw-semibold" id="pfName"><?= esc($g('title')) ?: 'New product' ?></span>
            <?php if ($isEdit): ?><span class="badge text-bg-secondary text-capitalize"><?= esc($g('status')) ?></span><?php endif; ?>
            <span class="text-secondary small" id="pfSaved"></span>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-light" id="pfPreviewToggle"><i class="bi bi-eye me-1"></i>Preview</button>
            <button class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i><?= $isEdit ? 'Save changes' : 'Create product' ?></button>
        </div>
    </div>

    <div class="row g-3">
        <!-- CONTEXT RAIL -->
        <div class="col-lg-3 order-lg-1 order-2">
            <div class="pf-rail">
                <div class="card mb-3"><div class="card-body">
                    <div class="text-secondary text-uppercase small fw-semibold mb-2">Context</div>
                    <div class="pf-ctx-row"><span class="text-secondary">Category</span><span class="fw-medium text-end" id="pfCtxCat">—</span></div>
                    <div class="pf-ctx-row"><span class="text-secondary">Type</span><span class="fw-medium" id="pfCtxType">—</span></div>
                    <div class="pf-ctx-row border-0"><span class="text-secondary">Tax / GST</span><span class="fw-medium" id="pfCtxTax">—</span></div>
                </div></div>
                <div class="card mb-3"><div class="card-body">
                    <div class="d-flex justify-content-between small mb-1"><span class="text-secondary text-uppercase fw-semibold">Completeness</span><span id="pfPct">0%</span></div>
                    <div class="pf-meter mb-2"><span id="pfMeter" style="width:0"></span></div>
                    <ul class="list-unstyled small mb-0 text-secondary" id="pfChecklist"></ul>
                </div></div>
                <div class="card" id="pfPreview"><div class="card-body">
                    <div class="text-secondary text-uppercase small fw-semibold mb-2">Live preview</div>
                    <div class="border rounded p-2">
                        <?php $primaryImg = ''; foreach (($images ?? []) as $img) { if (! empty($img['is_primary'])) { $primaryImg = site_url('media/' . $img['uuid']); } } if ($primaryImg === '' && ! empty($images)) { $primaryImg = site_url('media/' . $images[0]['uuid']); } ?>
                        <div class="bg-light rounded mb-2 d-grid" style="height:120px;place-items:center;overflow:hidden"><img id="pfPvImg" src="<?= esc($primaryImg, 'attr') ?>" alt="" style="max-height:120px;max-width:100%;object-fit:cover;<?= $primaryImg === '' ? 'display:none' : '' ?>"><i class="bi bi-image text-secondary fs-3" id="pfPvNoImg" style="<?= $primaryImg !== '' ? 'display:none' : '' ?>"></i></div>
                        <div class="fw-semibold small" id="pfPvTitle"><?= esc($g('title')) ?: 'Product name' ?></div>
                        <div class="text-secondary small" id="pfPvCat">—</div>
                        <div class="mt-1"><span class="text-primary fw-semibold" id="pfPvPrice">₹0</span> <span class="text-secondary text-decoration-line-through small" id="pfPvMrp"></span></div>
                    </div>
                </div></div>
            </div>
        </div>

        <!-- MAIN ACCORDION -->
        <div class="col-lg-9 order-lg-2 order-1">
            <?php if ($ctx === 'admin'): ?>
            <div class="card mb-3 border-primary-subtle"><div class="card-body py-2">
                <label class="form-label fw-semibold mb-1" for="fVendor"><i class="bi bi-shop me-1"></i>Vendor <span class="text-danger">*</span></label>
                <select name="vendor_id" id="fVendor" class="form-select js-select" required <?= $isEdit ? 'disabled' : '' ?>>
                    <option value="">Choose a vendor…</option>
                    <?php foreach ($vendors as $v): ?><option value="<?= esc($v['id'], 'attr') ?>" <?= (int) $vendorId === (int) $v['id'] ? 'selected' : '' ?>><?= esc($v['display_name']) ?></option><?php endforeach; ?>
                </select>
                <?php if ($isEdit): ?><input type="hidden" name="vendor_id" value="<?= esc($vendorId, 'attr') ?>"><?php endif; ?>
                <div class="form-text"><?= $isEdit ? "Vendor can't be changed after creation." : 'Pick the vendor first — it sets the allowed categories and shops.' ?></div>
            </div></div>
            <?php endif; ?>

            <!-- The single location whose catalogue this product belongs to: a shop for
                 vendors/admins, a manufacturing unit for manufacturers. -->
            <div class="card mb-3"><div class="card-body py-2">
                <label class="form-label fw-semibold mb-1" for="fShop"><i class="bi bi-shop-window me-1"></i><?= esc($locLabel) ?> <span class="text-danger">*</span></label>
                <select name="<?= esc($locField, 'attr') ?>" id="fShop" class="form-select js-select" required <?= $lockShops ? 'disabled' : '' ?>>
                    <option value=""><?= $ctx === 'admin' && ! $isEdit ? 'Pick a vendor first…' : esc($locPick) ?></option>
                    <?php foreach ($shops as $s): ?><option value="<?= esc($s['id'], 'attr') ?>" <?= (int) $shopId === (int) $s['id'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option><?php endforeach; ?>
                </select>
                <?php if ($lockShops): ?><input type="hidden" name="<?= esc($locField, 'attr') ?>" value="<?= esc($shopId, 'attr') ?>"><?php endif; ?>
                <div class="form-text"><?= $locHelp ?></div>
            </div></div>

            <!-- Product type — compact: Simple/Variant toggle (or another type). -->
            <div class="card mb-3"><div class="card-body py-2 d-flex align-items-center gap-2 flex-wrap">
                <label class="form-label fw-semibold mb-0 me-1">Product type</label>
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-primary js-type-card" data-type="simple"><i class="bi bi-box-seam me-1"></i>Simple</button>
                    <button type="button" class="btn btn-outline-primary js-type-card" data-type="variant"><i class="bi bi-grid-3x3-gap me-1"></i>Variant</button>
                </div>
                <select name="product_type" id="fType" class="form-select form-select-sm w-auto"><?php foreach (['simple', 'variant', 'bundle', 'combo', 'digital', 'service', 'subscription', 'giftcard', 'downloadable', 'rental'] as $t): ?><option value="<?= $t ?>" <?= $enum((string) $g('product_type', 'simple'), $t) ?>><?= ucfirst($t) ?></option><?php endforeach; ?></select>
                <span class="form-text mb-0 ms-auto">Variant = sizes/colours; price &amp; stock are set per item on the Variants page.</span>
            </div></div>
            <div class="accordion pf-acc" id="pfAcc">
                <?php foreach ($sections as $key => [$label, $icon, $autosave]): ?>
                    <div class="accordion-item" <?= $autosave ? 'data-section="' . $autosave . '"' : '' ?>>
                        <h2 class="accordion-header"><button class="accordion-button <?= $key === 'basics' ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#sec-<?= $key ?>"><i class="bi bi-<?= $icon ?> me-2 text-secondary"></i><?= $label ?></button></h2>
                        <div id="sec-<?= $key ?>" class="accordion-collapse collapse <?= $key === 'basics' ? 'show' : '' ?>" data-bs-parent="#pfAcc"><div class="accordion-body">
                        <?php if ($key === 'basics'): ?>
                            <div class="row g-3">
                                <div class="col-md-7"><label class="form-label">Product Name <span class="text-danger">*</span></label><input name="title" id="fTitle" class="form-control" value="<?= esc($g('title'), 'attr') ?>" required></div>
                                <div class="col-md-2"><label class="form-label">Short Name</label><input name="print_short_name" maxlength="20" class="form-control" value="<?= esc($g('print_short_name'), 'attr') ?>"></div>
                                <div class="col-md-3"><label class="form-label">Slug</label><input name="slug" class="form-control" value="<?= esc($g('slug'), 'attr') ?>" placeholder="auto"></div>
                                <div class="col-md-5"><label class="form-label">Category <span class="text-danger">*</span></label>
                                    <select name="category_id" id="fCategory" class="form-select js-select" required><option value="">Choose…</option>
                                        <?php foreach ($categories as $c): ?><option value="<?= esc($c['id'], 'attr') ?>" <?= $sel($g('category_id'), $c['id']) ?>><?= esc($c['name']) ?></option><?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Categories are business-type gated to this vendor.</div>
                                </div>
                                <div class="col-md-2"><label class="form-label">Condition</label><select name="product_condition" class="form-select"><?php foreach (['new', 'refurbished', 'used'] as $t): ?><option value="<?= $t ?>" <?= $enum((string) $g('product_condition', 'new'), $t) ?>><?= ucfirst($t) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-2"><label class="form-label">Brand</label><select name="brand_id" id="fBrand" class="form-select js-select"><option value="">—</option><?php foreach ($masters['brands'] as $b): ?><option value="<?= esc($b['id'], 'attr') ?>" <?= $sel($g('brand_id'), $b['id']) ?>><?= esc($b['name']) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-4"><label class="form-label">Manufacturer</label><input name="manufacturer" class="form-control" value="<?= esc($g('manufacturer'), 'attr') ?>"></div>
                                <div class="col-md-3"><label class="form-label">Country of Origin</label>
                                    <select name="country_of_origin" class="form-select js-select"><option value="">—</option>
                                        <?php foreach (($masters['countries'] ?? []) as $cn): ?><option value="<?= esc($cn, 'attr') ?>" <?= $sel($g('country_of_origin'), $cn) ?>><?= esc($cn) ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3"><label class="form-label">Made In</label>
                                    <select name="made_in" class="form-select js-select"><option value="">—</option>
                                        <?php foreach (($masters['countries'] ?? []) as $cn): ?><option value="<?= esc($cn, 'attr') ?>" <?= $sel($g('made_in'), $cn) ?>><?= esc($cn) ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3"><label class="form-label">Vendor Product Code</label><input name="vendor_product_code" class="form-control" value="<?= esc($g('vendor_product_code'), 'attr') ?>"></div>
                                <div class="col-md-3"><label class="form-label">Internal Code</label><input name="internal_product_code" class="form-control" value="<?= esc($g('internal_product_code'), 'attr') ?>"></div>
                            </div>
                        <?php elseif ($key === 'pricing'): ?>
                            <div class="form-text mb-2">SKU, price, MRP, stock and barcodes are set per item on the <?= $pid ? '<a href="' . esc($variantsUrl, 'attr') . '">Variants page</a>' : 'Variants page after you save' ?>. The tax &amp; unit below apply to the whole product.</div>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label">Tax Class <span class="text-danger">*</span></label><select name="tax_class_id" id="fTax" class="form-select" required><?php foreach ($masters['tax'] as $t): ?><option value="<?= esc($t['id'], 'attr') ?>" <?= $sel($g('tax_class_id'), $t['id']) ?>><?= esc($t['name']) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-4"><label class="form-label">Base Unit <span class="text-danger">*</span></label><select name="unit_id" class="form-select" required <?= $isEdit ? 'disabled' : '' ?>><?php foreach ($masters['units'] as $u): ?><option value="<?= esc($u['id'], 'attr') ?>" <?= $sel($g('base_unit_id'), $u['id']) ?>><?= esc($u['name']) ?></option><?php endforeach; ?></select><?php if ($isEdit): ?><input type="hidden" name="unit_id" value="<?= esc($g('base_unit_id'), 'attr') ?>"><?php endif; ?><div class="form-text">How this product is measured (kg, pcs, ltr…). Locked after creation — all variants inherit this unit.</div></div>
                                <div class="col-md-2"><label class="form-label">GST Type</label><select name="gst_type" class="form-select"><?php foreach (['inclusive', 'exclusive'] as $t): ?><option value="<?= $t ?>" <?= $enum((string) $g('gst_type', 'inclusive'), $t) ?>><?= ucfirst($t) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-2"><label class="form-label">Inventory Mode</label><select name="inventory_mode" class="form-select"><?php foreach (['managed', 'unlimited'] as $t): ?><option value="<?= $t ?>" <?= $enum((string) $g('inventory_mode', 'managed'), $t) ?>><?= ucfirst($t) ?></option><?php endforeach; ?></select></div>
                            </div>
                        <?php elseif ($key === 'content'): ?>
                            <div class="row g-3">
                                <div class="col-12"><label class="form-label">Short Description</label><input name="short_description" maxlength="500" class="form-control" value="<?= esc($gc('short_description'), 'attr') ?>"></div>
                                <div class="col-12"><label class="form-label d-flex justify-content-between">Full Description <button type="button" class="btn btn-sm btn-outline-primary js-ai" data-ai="description" data-fill="full_description"><i class="bi bi-stars me-1"></i>AI generate</button></label>
                                    <div class="js-quill mb-3" data-target="full_description"><?= $gc('full_description') ?: ($g('description') ?: '') ?></div>
                                    <textarea name="full_description" class="d-none"><?= esc($gc('full_description') ?: $g('description')) ?></textarea>
                                </div>
                                <div class="col-md-6"><label class="form-label">Ingredients</label><textarea name="ingredients" class="form-control" rows="2"><?= esc($gc('ingredients')) ?></textarea></div>
                                <div class="col-md-6"><label class="form-label">Additional Info</label><textarea name="additional_info" class="form-control" rows="2"><?= esc($gc('additional_info')) ?></textarea></div>
                                <div class="col-md-4"><label class="form-label">Usage</label><textarea name="usage_instructions" class="form-control" rows="2"><?= esc($gc('usage_instructions')) ?></textarea></div>
                                <div class="col-md-4"><label class="form-label">Safety</label><textarea name="safety_instructions" class="form-control" rows="2"><?= esc($gc('safety_instructions')) ?></textarea></div>
                                <div class="col-md-4"><label class="form-label">Storage</label><textarea name="storage_instructions" class="form-control" rows="2"><?= esc($gc('storage_instructions')) ?></textarea></div>
                                <div class="col-12"><label class="form-label">Highlights</label><div id="highlightRows">
                                    <?php foreach ((($content['highlights'] ?? []) ?: ['']) as $h): ?><div class="input-group mb-1"><input name="highlights[]" class="form-control" value="<?= esc($h, 'attr') ?>" placeholder="Key highlight"><button type="button" class="btn btn-outline-danger js-rm">&times;</button></div><?php endforeach; ?>
                                </div><button type="button" class="btn btn-sm btn-outline-secondary" data-add="#highlightRows" data-tpl="hl">+ Add highlight</button></div>
                            </div>
                        <?php elseif ($key === 'media'): ?>
                            <label class="form-label">Add Image</label>
                            <div id="pfDropzone" class="pf-dz text-center p-4 mb-2">
                                <i class="bi bi-cloud-arrow-up fs-2 text-secondary d-block mb-1"></i>
                                <span class="text-secondary">Drag images here or <u>click to browse</u> — you can select several</span>
                                <input type="file" id="pfImageInput" name="image[]" accept="image/*" class="d-none" multiple>
                            </div>
                            <div id="pfPreviewStrip" class="d-flex gap-2 flex-wrap mb-2"></div>
                            <?php if ($pid): ?>
                                <button type="button" id="pfLibOpen" class="btn btn-outline-primary btn-sm mb-2"><i class="bi bi-images me-1"></i>Choose from media library</button>
                                <span class="text-secondary small ms-1">Reuse images you already uploaded — no need to upload again.</span>
                            <?php endif; ?>
                            <?php if ($pid && ! empty($images)): ?>
                                <div class="form-text mt-2">Drag to reorder · ★ set primary · ✕ remove</div>
                                <div id="pfGallery" class="d-flex gap-2 mt-2 flex-wrap">
                                    <?php foreach ($images as $img): ?>
                                        <div class="pf-tile position-relative" draggable="true" data-media-id="<?= esc($img['media_id'] ?? '', 'attr') ?>" style="border:1px solid #e7eaf3;border-radius:.4rem;overflow:hidden;cursor:grab">
                                            <img src='<?= site_url('media/' . $img['uuid']) ?>' alt="" style="height:72px;width:72px;object-fit:cover;display:block">
                                            <?php if (! empty($img['is_primary'])): ?><span class="badge text-bg-warning position-absolute top-0 start-0">★</span><?php endif; ?>
                                            <div class="position-absolute bottom-0 end-0 d-flex">
                                                <button type="button" class="btn btn-sm btn-light py-0 px-1 js-media-primary" data-id="<?= esc($img['media_id'] ?? '', 'attr') ?>" title="Set primary">★</button>
                                                <button type="button" class="btn btn-sm btn-light py-0 px-1 text-danger js-media-remove" data-id="<?= esc($img['media_id'] ?? '', 'attr') ?>" title="Remove">&times;</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($pid): ?>
                            <!-- Media library picker modal -->
                            <div class="modal fade" id="pfLibModal" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title"><i class="bi bi-images me-1"></i>Add images</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <ul class="nav nav-tabs mb-3">
                                                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#pfLibTab">From library</button></li>
                                                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#pfUpTab">Upload new</button></li>
                                            </ul>
                                            <div class="tab-content">
                                                <div class="tab-pane fade show active" id="pfLibTab">
                                                    <div id="pfLibGrid" class="row g-2"><div class="col-12 text-center text-secondary py-4"><span class="spinner-border spinner-border-sm me-1"></span>Loading…</div></div>
                                                </div>
                                                <div class="tab-pane fade" id="pfUpTab">
                                                    <div id="pfLibDrop" class="pf-dz text-center p-4"><i class="bi bi-cloud-arrow-up fs-2 text-secondary d-block mb-1"></i><span class="text-secondary">Drag images here or <u>click to browse</u> — they're added to this product and your library</span><input type="file" id="pfLibUpInput" accept="image/*" class="d-none" multiple></div>
                                                    <div id="pfUpMsg" class="small mt-2"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <span id="pfLibCount" class="me-auto small text-secondary"></span>
                                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                                            <button type="button" id="pfLibAdd" class="btn btn-primary" disabled><i class="bi bi-plus-lg me-1"></i>Add selected</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <hr>
                            <?php if ($pid): ?>
                                <label class="form-label">Documents <span class="text-secondary small">(manual / brochure / certificate / warranty — PDF, DOC, ≤10 MB)</span></label>
                                <?php if (! empty($documents)): ?>
                                    <ul class="list-group list-group-flush mb-2"><?php foreach ($documents as $d): ?><li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1"><span><span class="badge text-bg-light text-capitalize me-1"><?= esc($d['doc_type']) ?></span><a href="<?= site_url('media/' . $d['uuid']) ?>" target="_blank"><?= esc($d['title'] ?: $d['doc_type']) ?></a></span><button type="button" class="btn btn-sm btn-link text-danger p-0 js-doc-delete" data-id="<?= esc($d['id'], 'attr') ?>">&times;</button></li><?php endforeach; ?></ul>
                                <?php endif; ?>
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-3"><select id="docType" class="form-select form-select-sm"><?php foreach (['manual', 'brochure', 'catalog', 'certificate', 'warranty', 'spec_sheet'] as $dt): ?><option value="<?= $dt ?>"><?= ucfirst(str_replace('_', ' ', $dt)) ?></option><?php endforeach; ?></select></div>
                                    <div class="col-md-4"><input id="docTitle" class="form-control form-control-sm" placeholder="Title (optional)"></div>
                                    <div class="col-md-3"><input type="file" id="docFile" class="form-control form-control-sm"></div>
                                    <div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-primary w-100 js-doc-upload">Upload</button></div>
                                </div>
                            <?php else: ?>
                                <div class="form-text">Save the product first, then attach documents and reorder the gallery.</div>
                            <?php endif; ?>
                            <hr><label class="form-label">Videos</label><div id="videoRows">
                                <?php foreach ((($videos ?? []) ?: [['provider' => 'youtube', 'url' => '']]) as $v): ?><div class="input-group mb-1"><select name="video_provider[]" class="form-select" style="max-width:120px"><?php foreach (['youtube', 'vimeo', 'direct'] as $pv): ?><option value="<?= $pv ?>" <?= ($v['provider'] ?? '') === $pv ? 'selected' : '' ?>><?= ucfirst($pv) ?></option><?php endforeach; ?></select><input name="video_url[]" class="form-control" value="<?= esc($v['url'] ?? '', 'attr') ?>" placeholder="https://…"><button type="button" class="btn btn-outline-danger js-rm">&times;</button></div><?php endforeach; ?>
                            </div><button type="button" class="btn btn-sm btn-outline-secondary" data-add="#videoRows" data-tpl="video">+ Add video</button>
                        <?php elseif ($key === 'rules'): ?>
                            <div class="row g-3">
                                <div class="col-md-3"><label class="form-label">Min Qty</label><input name="min_purchase_qty" type="number" step="0.001" class="form-control" value="<?= esc($g('min_purchase_qty', '1'), 'attr') ?>"></div>
                                <div class="col-md-3"><label class="form-label">Max Qty</label><input name="max_purchase_qty" type="number" step="0.001" class="form-control" value="<?= esc($g('max_purchase_qty', '10'), 'attr') ?>"></div>
                                <div class="col-md-3"><label class="form-label">Qty Step</label><input name="qty_step" type="number" step="0.001" class="form-control" value="<?= esc($g('qty_step', '1'), 'attr') ?>"></div>
                                <div class="col-md-3"><label class="form-label">Payment</label><select name="payment_restriction" class="form-select"><?php foreach (['both' => 'Online + COD', 'online' => 'Online only', 'cod' => 'COD only'] as $v => $l): ?><option value="<?= $v ?>" <?= $enum((string) $g('payment_restriction', 'both'), $v) ?>><?= $l ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-3"><label class="form-label">Refund window (days)</label><input name="refund_window_days" type="number" class="form-control" value="<?= esc($g('refund_window_days', '7'), 'attr') ?>"></div>
                                <div class="col-md-3"><label class="form-label">Return window (days)</label><input name="return_window_days" type="number" class="form-control" value="<?= esc($g('return_window_days', '7'), 'attr') ?>"></div>
                                <div class="col-md-3"><label class="form-label">Available From</label><input name="available_from" class="form-control js-date" value="<?= esc($g('available_from'), 'attr') ?>"></div>
                                <div class="col-md-3"><label class="form-label">Out-of-stock</label><select name="out_of_stock_handling" class="form-select"><?php foreach (['disable' => 'Show but disable', 'hide' => 'Hide', 'backorder' => 'Backorder'] as $v => $l): ?><option value="<?= $v ?>" <?= $enum((string) $g('out_of_stock_handling', 'disable'), $v) ?>><?= $l ?></option><?php endforeach; ?></select></div>
                                <div class="col-12 d-flex gap-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="refund_allowed" id="ra" value="1" <?= $chk($g('refund_allowed', 1)) ?>><label class="form-check-label" for="ra">Refund allowed</label></div><div class="form-check"><input class="form-check-input" type="checkbox" name="preorder_enabled" id="pre" value="1" <?= $chk($g('preorder_enabled')) ?>><label class="form-check-label" for="pre">Pre-order</label></div><div class="form-check"><input class="form-check-input" type="checkbox" name="backorder_enabled" id="bk" value="1" <?= $chk($g('backorder_enabled')) ?>><label class="form-check-label" for="bk">Backorder</label></div></div>
                            </div>
                        <?php elseif ($key === 'shipping'): ?>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label">Shipping Type</label><select name="shipping_type" class="form-select"><?php foreach (['paid' => 'Paid', 'free' => 'Free', 'vendor' => 'Vendor ships', 'pickup' => 'Self-pickup'] as $v => $l): ?><option value="<?= $v ?>" <?= $enum((string) $g('shipping_type', 'paid'), $v) ?>><?= $l ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-8 d-flex align-items-end gap-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="ships_local" id="sl" value="1" <?= $chk($g('ships_local', 1)) ?>><label class="form-check-label" for="sl">Local</label></div><div class="form-check"><input class="form-check-input" type="checkbox" name="ships_national" id="sn" value="1" <?= $chk($g('ships_national', 1)) ?>><label class="form-check-label" for="sn">National</label></div><div class="form-check"><input class="form-check-input" type="checkbox" name="ships_international" id="si" value="1" <?= $chk($g('ships_international')) ?>><label class="form-check-label" for="si">International</label></div></div>
                            </div>
                        <?php elseif ($key === 'seo'): ?>
                            <div class="row g-3">
                                <div class="col-12"><button type="button" class="btn btn-sm btn-outline-primary js-ai" data-ai="seo"><i class="bi bi-stars me-1"></i>AI auto-SEO</button></div>
                                <div class="col-md-6"><label class="form-label">Meta Title</label><input name="meta_title" class="form-control" value="<?= esc($gs('meta_title'), 'attr') ?>"></div>
                                <div class="col-md-6"><label class="form-label">Canonical URL</label><input name="canonical_url" class="form-control" value="<?= esc($gs('canonical_url'), 'attr') ?>"></div>
                                <div class="col-12"><label class="form-label">Meta Description</label><textarea name="meta_description" class="form-control" rows="2" maxlength="300"><?= esc($gs('meta_description')) ?></textarea></div>
                                <div class="col-12"><label class="form-label">Meta Keywords</label><input name="meta_keywords" class="form-control" value="<?= esc($gs('meta_keywords'), 'attr') ?>"></div>
                            </div>
                        <?php elseif ($key === 'tags'): ?>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label">Visibility</label><select name="visibility" class="form-select"><?php foreach (['public' => 'Public', 'logged_in' => 'Logged-in', 'group' => 'Customer group', 'vendor' => 'Vendor only', 'hidden' => 'Hidden'] as $v => $l): ?><option value="<?= $v ?>" <?= $enum((string) $g('visibility', 'public'), $v) ?>><?= $l ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-4"><label class="form-label">Search Boost</label><select name="search_boost" class="form-select"><?php foreach (['normal' => 'Normal', 'high' => 'High', 'featured' => 'Featured'] as $v => $l): ?><option value="<?= $v ?>" <?= $enum((string) $g('search_boost', 'normal'), $v) ?>><?= $l ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-4"><label class="form-label">Channels</label><div class="d-flex gap-2 flex-wrap pt-1"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_online_enabled" id="ce1" value="1" <?= $chk($g('is_online_enabled', 1)) ?>><label class="form-check-label small" for="ce1">Web</label></div><div class="form-check"><input class="form-check-input" type="checkbox" name="is_app_enabled" id="ce2" value="1" <?= $chk($g('is_app_enabled', 1)) ?>><label class="form-check-label small" for="ce2">App</label></div><div class="form-check"><input class="form-check-input" type="checkbox" name="is_pos_enabled" id="ce3" value="1" <?= $chk($g('is_pos_enabled', 1)) ?>><label class="form-check-label small" for="ce3">POS</label></div><div class="form-check"><input class="form-check-input" type="checkbox" name="is_marketplace_enabled" id="ce4" value="1" <?= $chk($g('is_marketplace_enabled')) ?>><label class="form-check-label small" for="ce4">Market</label></div></div></div>
                                <div class="col-md-6"><label class="form-label d-flex justify-content-between">Tags <button type="button" class="btn btn-sm btn-outline-primary js-ai" data-ai="tags"><i class="bi bi-stars me-1"></i>Suggest</button></label><select name="tags[]" class="form-select js-tags" multiple><?php foreach (array_filter(array_map('trim', explode(',', (string) ($tagsCsv ?? '')))) as $t): ?><option value="<?= esc($t, 'attr') ?>" selected><?= esc($t) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-6"><label class="form-label">Labels</label><select name="labels[]" class="form-select js-select" multiple><?php foreach (($masters['labels'] ?? []) as $lab): ?><option value="<?= esc($lab['id'], 'attr') ?>" <?= in_array((int) $lab['id'], $labelIds ?? [], true) ? 'selected' : '' ?>><?= esc($lab['name']) ?></option><?php endforeach; ?></select></div>
                            </div>
                        <?php elseif ($key === 'faq'): ?>
                            <div id="faqRows"><?php foreach ((($faqs ?? []) ?: [['question' => '', 'answer' => '']]) as $f): ?><div class="row g-2 mb-2"><div class="col-md-4"><input name="faq_q[]" class="form-control" value="<?= esc($f['question'] ?? '', 'attr') ?>" placeholder="Question"></div><div class="col-md-7"><input name="faq_a[]" class="form-control" value="<?= esc($f['answer'] ?? '', 'attr') ?>" placeholder="Answer"></div><div class="col-md-1"><button type="button" class="btn btn-outline-danger js-rm w-100">&times;</button></div></div><?php endforeach; ?></div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-add="#faqRows" data-tpl="faq">+ Add FAQ</button>
                        <?php elseif ($key === 'specs'): ?>
                            <div class="row g-3"><?php if (empty($masters['attributes'])): ?><div class="col-12 text-secondary">No custom attributes for this category. Add non-variant attributes under Masters → Attributes.</div><?php else: foreach (($masters['attributes'] ?? []) as $a): ?><div class="col-md-4"><label class="form-label"><?= esc($a['name']) ?></label><input name="cattr[<?= esc($a['id'], 'attr') ?>]" class="form-control" value="<?= esc(($cattr[(int) $a['id']] ?? ''), 'attr') ?>"></div><?php endforeach; endif; ?></div>
                        <?php elseif ($key === 'relations'): ?>
                            <div class="row g-3"><?php foreach (['related' => 'Related', 'upsell' => 'Upsell', 'cross_sell' => 'Cross-sell', 'fbt' => 'Frequently Bought Together', 'alternative' => 'Alternative', 'similar' => 'Similar', 'accessory' => 'Accessory'] as $rt => $rl): ?><div class="col-md-6"><label class="form-label"><?= $rl ?> <span class="text-secondary small">(comma product IDs)</span></label><input name="relations[<?= $rt ?>]" class="form-control" value='<?= esc(implode(',', $relNow[$rt] ?? []), 'attr') ?>'></div><?php endforeach; ?></div>
                        <?php endif; ?>
                        </div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</form>

<script>window.__pfAllBrands = <?= json_encode(array_values($masters['brands']), JSON_HEX_TAG) ?>;</script>
<template id="tpl-hl"><div class="input-group mb-1"><input name="highlights[]" class="form-control" placeholder="Key highlight"><button type="button" class="btn btn-outline-danger js-rm">&times;</button></div></template>
<template id="tpl-video"><div class="input-group mb-1"><select name="video_provider[]" class="form-select" style="max-width:120px"><option value="youtube">Youtube</option><option value="vimeo">Vimeo</option><option value="direct">Direct</option></select><input name="video_url[]" class="form-control" placeholder="https://…"><button type="button" class="btn btn-outline-danger js-rm">&times;</button></div></template>
<template id="tpl-faq"><div class="row g-2 mb-2"><div class="col-md-4"><input name="faq_q[]" class="form-control" placeholder="Question"></div><div class="col-md-7"><input name="faq_a[]" class="form-control" placeholder="Answer"></div><div class="col-md-1"><button type="button" class="btn btn-outline-danger js-rm w-100">&times;</button></div></div></template>