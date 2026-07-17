<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/select2/select2.min.css') ?>">
<link rel="stylesheet" href="<?= asset('plugins/select2/select2-bootstrap-5-theme.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php
$statusBadge = [
    'active'    => 'success',
    'disabled'  => 'secondary',
    'scheduled' => 'info',
    'expired'   => 'danger',
];
$activeCount = count(array_filter($banners, static fn ($b) => $b['status'] === 'active' && $b['placement'] === 'home'));
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-0 fw-bold">Home Banners</h5>
        <small class="text-secondary">App carousel · max 6 active · recommended 1200×600 px (2:1)</small>
    </div>
    <button class="btn btn-primary btn-sm" onclick="openAdd()">
        <i class="bi bi-plus-lg me-1"></i>Add Banner
        <?php if ($activeCount >= 6): ?>
            <span class="badge text-bg-warning ms-1">6/6</span>
        <?php else: ?>
            <span class="badge text-bg-light text-dark ms-1"><?= $activeCount ?>/6</span>
        <?php endif; ?>
    </button>
</div>

<div id="alertBox"></div>

<?php if (empty($banners)): ?>
<div class="card">
    <div class="card-body text-center py-5 text-secondary">
        <i class="bi bi-images display-5 d-block mb-3"></i>
        <p class="mb-1 fw-semibold">No banners yet</p>
        <p class="small mb-3">Add up to 6 active banners to show in the app's home carousel.</p>
        <button class="btn btn-primary btn-sm" onclick="openAdd()"><i class="bi bi-plus-lg me-1"></i>Add First Banner</button>
    </div>
</div>
<?php else: ?>
<div class="row g-3" id="bannerGrid">
<?php foreach ($banners as $b): ?>
    <div class="col-12 col-md-6 col-xl-4" id="card-<?= $b['id'] ?>">
        <div class="card h-100 shadow-sm border-0">
            <!-- Image preview -->
            <div class="position-relative bg-secondary" style="height:140px;border-radius:.5rem .5rem 0 0;overflow:hidden;background:#e9ecef">
                <?php if (!empty($b['image_url'])): ?>
                    <img src="<?= esc($b['image_url'], 'attr') ?>" alt="" style="width:100%;height:100%;object-fit:cover">
                <?php else: ?>
                    <div class="d-flex align-items-center justify-content-center h-100 text-secondary">
                        <i class="bi bi-image fs-1"></i>
                    </div>
                <?php endif; ?>
                <!-- Status pill -->
                <span class="badge text-bg-<?= esc($statusBadge[$b['status']] ?? 'secondary', 'attr') ?> position-absolute top-0 end-0 m-2">
                    <?= esc($b['status']) ?>
                </span>
            </div>
            <div class="card-body py-2 px-3">
                <div class="fw-semibold text-truncate"><?= esc($b['title']) ?></div>
                <?php if (!empty($b['subtitle'])): ?>
                    <div class="small text-secondary text-truncate"><?= esc($b['subtitle']) ?></div>
                <?php endif; ?>
                <?php if (!empty($b['target_url'])): ?>
                    <div class="small text-secondary mt-1"><i class="bi bi-link-45deg"></i> <?= esc($b['target_url']) ?></div>
                <?php endif; ?>
                <div class="small text-secondary">Sort: <?= (int)$b['sort_order'] ?></div>
            </div>
            <div class="card-footer bg-transparent border-top-0 py-2 px-3 d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary flex-fill" onclick='openEdit(<?= json_encode($b) ?>)'>
                    <i class="bi bi-pencil"></i> Edit
                </button>
                <button class="btn btn-sm <?= $b['status'] === 'active' ? 'btn-outline-warning' : 'btn-outline-success' ?> flex-fill"
                        onclick="toggleBanner(<?= $b['id'] ?>, this)">
                    <?php if ($b['status'] === 'active'): ?>
                        <i class="bi bi-pause-circle"></i> Disable
                    <?php else: ?>
                        <i class="bi bi-play-circle"></i> Enable
                    <?php endif; ?>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteBanner(<?= $b['id'] ?>, '<?= esc($b['title'], 'js') ?>')">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Add / Edit Modal ──────────────────────────────────────────── -->
<div class="modal fade" id="bannerModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalTitle">Add Banner</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="bannerForm" enctype="multipart/form-data" onsubmit="submitBanner(event)">
                <?= csrf_field() ?>
                <input type="hidden" id="bannerId" name="_id" value="">
                <div class="modal-body">
                    <div id="modalAlert" class="alert alert-danger d-none"></div>

                    <!-- Image upload -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Banner Image</label>
                        <div id="imgPreviewWrap" class="mb-2 d-none">
                            <img id="imgPreview" src="" alt="" class="img-fluid rounded" style="max-height:160px;object-fit:cover;width:100%">
                        </div>
                        <input type="file" class="form-control" id="imageInput" name="image" accept="image/jpeg,image/png,image/webp,image/gif"
                               onchange="previewImg(this)">
                        <div class="form-text">
                            JPEG / PNG / WebP / GIF · Max 2 MB · Min <strong>600×300 px</strong> ·
                            Recommended <strong>1200×600 px (2:1 ratio)</strong> for best display on all phones.
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" id="fTitle" maxlength="191" required placeholder="e.g. Groceries in minutes">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Subtitle</label>
                            <input type="text" class="form-control" name="subtitle" id="fSubtitle" maxlength="255" placeholder="e.g. Fresh picks delivered fast">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Tap Action</label>
                            <div class="d-flex gap-4 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="tap_mode" id="modeCustom" value="custom" checked onchange="setTapMode('custom')">
                                    <label class="form-check-label" for="modeCustom">Custom URL</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="tap_mode" id="modeFilter" value="filter" onchange="setTapMode('filter')">
                                    <label class="form-check-label" for="modeFilter">Filter Builder</label>
                                </div>
                            </div>
                            <!-- Mode A: Custom URL -->
                            <div id="customUrlWrap">
                                <input type="text" class="form-control" name="target_url" id="fTargetUrl" placeholder="/menu or /search?q=fruits">
                                <div class="form-text">Leave blank for a non-tappable banner.</div>
                            </div>
                            <!-- Mode B: Filter Builder (writes result to fTargetUrl on submit) -->
                            <div id="filterBuilderWrap" class="d-none">
                                <div class="border rounded p-3 bg-light">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label fw-semibold small mb-1">Search Term</label>
                                            <input type="text" class="form-control form-control-sm" id="fbQ" placeholder="e.g. mango, protein shake" oninput="updateFilterUrl()">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold small mb-1">Category</label>
                                            <select class="form-select form-select-sm" id="fbCategory">
                                                <option value="">— Any category —</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold small mb-1">Brand</label>
                                            <select class="form-select form-select-sm" id="fbBrand" onchange="updateFilterUrl();fetchDiscountTiers()">
                                                <option value="">— Any brand —</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold small mb-1">Sort By</label>
                                            <select class="form-select form-select-sm" id="fbSort" onchange="updateFilterUrl()">
                                                <option value="">Default</option>
                                                <option value="featured">Featured First</option>
                                                <option value="price_asc">Price: Low to High</option>
                                                <option value="price_desc">Price: High to Low</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold small mb-1">Product Label</label>
                                            <select class="form-select form-select-sm" id="fbLabel" onchange="updateFilterUrl()">
                                                <option value="">— Any label —</option>
                                                <option value="hot_deal">Hot Deal</option>
                                                <option value="new_arrival">New Arrival</option>
                                                <option value="bestseller">Bestseller</option>
                                                <option value="trending">Trending</option>
                                                <option value="featured">Featured</option>
                                            </select>
                                        </div>
                                        <div class="col-md-5">
                                            <label class="form-label fw-semibold small mb-1">Price Range (₹)</label>
                                            <div class="d-flex gap-2 align-items-center">
                                                <input type="number" class="form-control form-control-sm" id="fbMinPrice" placeholder="Min" min="0" oninput="updateFilterUrl()">
                                                <span class="text-muted small">to</span>
                                                <input type="number" class="form-control form-control-sm" id="fbMaxPrice" placeholder="Max" min="0" oninput="updateFilterUrl()">
                                            </div>
                                        </div>
                                        <div class="col-md-3 d-flex align-items-end pb-1">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="fbOnOffer" onchange="updateFilterUrl()">
                                                <label class="form-check-label fw-semibold small" for="fbOnOffer">On Sale Only</label>
                                            </div>
                                        </div>
                                        <div class="col-12 d-none" id="fbDiscountInfo">
                                            <div class="p-2 bg-white border rounded">
                                                <div class="small text-muted fw-semibold mb-1">Discount breakdown for this selection:</div>
                                                <div id="fbTiersBody"></div>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fw-semibold small mb-1">Generated URL</label>
                                            <div class="p-2 bg-white border rounded small text-break font-monospace text-primary" id="fbUrlPreview" style="min-height:32px;">/search</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Placement</label>
                            <select class="form-select" name="placement" id="fPlacement">
                                <option value="home">Home</option>
                                <option value="category">Category</option>
                                <option value="app_top">App Top</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Sort Order</label>
                            <input type="number" class="form-control" name="sort_order" id="fSortOrder" value="0" min="0" max="99">
                            <div class="form-text">Lower = shown first.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Status</label>
                            <select class="form-select" name="status" id="fStatus">
                                <option value="active">Active</option>
                                <option value="disabled">Disabled</option>
                                <option value="scheduled">Scheduled</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Start date</label>
                            <input type="datetime-local" class="form-control" name="starts_at" id="fStartsAt">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">End date</label>
                            <input type="datetime-local" class="form-control" name="ends_at" id="fEndsAt">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <span id="submitSpinner" class="spinner-border spinner-border-sm d-none me-1"></span>
                        Save Banner
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/select2/select2.min.js') ?>"></script>
<script>
const CSRF_TOKEN = '<?= csrf_token() ?>';
const CSRF_HASH  = '<?= csrf_hash() ?>';
const BASE       = '<?= site_url('admin/banners') ?>';
const CATEGORIES = <?= json_encode(array_values($categories)) ?>;
const BRANDS     = <?= json_encode(array_values($brands)) ?>;
const BRANDS_BASE = '<?= site_url("admin/banners/brands") ?>';
const TIERS_BASE  = '<?= site_url("admin/banners/discount-tiers") ?>';

let modal, editId = null, _pendingBrand = '';
document.addEventListener('DOMContentLoaded', () => {
    modal = new bootstrap.Modal('#bannerModal');

    // Populate category Select2/select
    const catSel = document.getElementById('fbCategory');
    CATEGORIES.forEach(c => {
        const indent = '  '.repeat(parseInt(c.level) || 0);
        catSel.add(new Option(indent + c.name, c.slug));
    });

    // Populate brand select with full list
    _populateBrandSelect(BRANDS.map(b => ({ id: b.id, name: b.name, cnt: null })));

    // Activate Select2
    $('#fbCategory').select2({ width: '100%', dropdownParent: $('#bannerModal .modal-dialog'), allowClear: true, placeholder: '— Any category —' });
    $('#fbBrand').select2({ width: '100%', dropdownParent: $('#bannerModal .modal-dialog'), allowClear: true, placeholder: '— Any brand —' });

    // Category change: cascade brands + discount tiers + URL
    $('#fbCategory').on('change', onCatChange);
});

// ── Filter Builder ────────────────────────────────────────────────────────────

function setTapMode(mode) {
    document.getElementById('customUrlWrap').classList.toggle('d-none', mode === 'filter');
    document.getElementById('filterBuilderWrap').classList.toggle('d-none', mode === 'custom');
}

// ── Filter Builder Helpers ─────────────────────────────────────────────────────

function _populateBrandSelect(brands) {
    const sel = document.getElementById('fbBrand');
    const cur = sel.value;
    sel.innerHTML = '<option value="">— Any brand —</option>';
    brands.forEach(b => sel.add(new Option(b.cnt !== null ? b.name + ' (' + b.cnt + ')' : b.name, b.id)));
    $('#fbBrand').val(brands.some(b => String(b.id) === String(cur)) ? cur : '').trigger('change.select2');
}

async function onCatChange() {
    updateFilterUrl();
    const cat = document.getElementById('fbCategory').value;
    await fetchBrandsByCategory(cat);
    fetchDiscountTiers();
}

async function fetchBrandsByCategory(catSlug) {
    const targetBrand = _pendingBrand || document.getElementById('fbBrand').value;
    _pendingBrand = '';
    if (!catSlug) {
        _populateBrandSelect(BRANDS.map(b => ({ id: b.id, name: b.name, cnt: null })));
        $('#fbBrand').val(targetBrand).trigger('change.select2');
        return;
    }
    try {
        const res = await fetch(BRANDS_BASE + '?category=' + encodeURIComponent(catSlug), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const j = await res.json();
        if (j.status === 'success') {
            _populateBrandSelect(j.data.map(b => ({ id: b.id, name: b.name, cnt: b.cnt })));
            const valid = j.data.some(b => String(b.id) === String(targetBrand));
            $('#fbBrand').val(valid ? targetBrand : '').trigger('change.select2');
        }
    } catch { _populateBrandSelect(BRANDS.map(b => ({ id: b.id, name: b.name, cnt: null }))); }
}

async function fetchDiscountTiers() {
    const cat   = document.getElementById('fbCategory').value;
    const brand = document.getElementById('fbBrand').value;
    const info  = document.getElementById('fbDiscountInfo');
    const body  = document.getElementById('fbTiersBody');
    if (!cat && !brand) { info.classList.add('d-none'); return; }
    info.classList.remove('d-none');
    body.innerHTML = '<span class="text-muted small">Loading...</span>';
    try {
        const p = new URLSearchParams();
        if (cat) p.set('category', cat);
        if (brand) p.set('brand', brand);
        const res = await fetch(TIERS_BASE + '?' + p, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const j = await res.json();
        if (j.status === 'success' && j.data.length) {
            body.innerHTML = j.data.map(t =>
                '<span class="badge bg-light text-dark border me-1 mb-1">' + t.label + ' <strong class="text-success">(' + t.count.toLocaleString() + ')</strong></span>'
            ).join('');
        } else {
            body.innerHTML = '<span class="text-muted small">No discounted products in this selection.</span>';
        }
    } catch { info.classList.add('d-none'); }
}

function resetFilterBuilder() {
    ['fbQ', 'fbMinPrice', 'fbMaxPrice'].forEach(id => document.getElementById(id).value = '');
    ['fbSort', 'fbLabel'].forEach(id => { document.getElementById(id).value = ''; });
    $('#fbCategory').val('').trigger('change.select2');
    $('#fbBrand').val('').trigger('change.select2');
    _populateBrandSelect(BRANDS.map(b => ({ id: b.id, name: b.name, cnt: null })));
    document.getElementById('fbOnOffer').checked = false;
    document.getElementById('fbDiscountInfo').classList.add('d-none');
    updateFilterUrl();
}

function updateFilterUrl() {
    const p = new URLSearchParams();
    const q       = document.getElementById('fbQ').value.trim();
    const cat     = document.getElementById('fbCategory').value;
    const brand   = document.getElementById('fbBrand').value;
    const sort    = document.getElementById('fbSort').value;
    const label   = document.getElementById('fbLabel').value;
    const onOffer = document.getElementById('fbOnOffer').checked;
    const minP    = document.getElementById('fbMinPrice').value;
    const maxP    = document.getElementById('fbMaxPrice').value;

    if (q)       p.set('q',         q);
    if (cat)     p.set('category',  cat);
    if (brand)   p.set('brand',     brand);
    if (sort)    p.set('sort',      sort);
    if (label)   p.set('label',     label);
    if (onOffer) p.set('on_offer',  '1');
    if (minP)    p.set('min_price', minP);
    if (maxP)    p.set('max_price', maxP);

    const url = '/search' + (p.toString() ? '?' + p.toString() : '');
    document.getElementById('fbUrlPreview').textContent = url;
}

function parseFilterUrl(url) {
    if (!url || !url.startsWith('/search')) return false;
    const qs = url.includes('?') ? url.split('?')[1] : '';
    const p  = new URLSearchParams(qs);

    document.getElementById('modeFilter').checked = true;
    setTapMode('filter');

    document.getElementById('fbQ').value        = p.get('q')         || '';
    document.getElementById('fbCategory').value = p.get('category')  || '';
    document.getElementById('fbBrand').value    = p.get('brand')     || '';
    document.getElementById('fbSort').value     = p.get('sort')      || '';
    document.getElementById('fbLabel').value    = p.get('label')     || '';
    document.getElementById('fbOnOffer').checked = p.get('on_offer') === '1';
    document.getElementById('fbMinPrice').value = p.get('min_price') || '';
    document.getElementById('fbMaxPrice').value = p.get('max_price') || '';

    // Trigger category cascade (fetches matching brands + discount tiers async)
    _pendingBrand = p.get('brand') || '';
    $('#fbCategory').val(p.get('category') || '').trigger('change');

    updateFilterUrl();
    return true;
}

// ── CRUD ──────────────────────────────────────────────────────────────────────

function openAdd() {
    editId = null;
    document.getElementById('modalTitle').textContent = 'Add Banner';
    document.getElementById('bannerForm').reset();
    document.getElementById('bannerId').value = '';
    document.getElementById('imgPreviewWrap').classList.add('d-none');
    document.getElementById('imgPreview').src = '';
    document.getElementById('modalAlert').classList.add('d-none');
    const fpS = document.getElementById('fStartsAt')._flatpickr;
    const fpE = document.getElementById('fEndsAt')._flatpickr;
    if (fpS) fpS.clear(); if (fpE) fpE.clear();
    document.getElementById('modeCustom').checked = true;
    setTapMode('custom');
    resetFilterBuilder();
    modal.show();
}

function openEdit(b) {
    editId = b.id;
    document.getElementById('modalTitle').textContent = 'Edit Banner';
    document.getElementById('bannerId').value = b.id;
    document.getElementById('fTitle').value       = b.title      ?? '';
    document.getElementById('fSubtitle').value    = b.subtitle   ?? '';
    document.getElementById('fTargetUrl').value   = b.target_url ?? '';
    if (!parseFilterUrl(b.target_url ?? '')) {
        document.getElementById('modeCustom').checked = true;
        setTapMode('custom');
        resetFilterBuilder();
    }
    document.getElementById('fPlacement').value   = b.placement  ?? 'home';
    document.getElementById('fSortOrder').value   = b.sort_order ?? 0;
    document.getElementById('fStatus').value      = b.status     ?? 'active';
    const fpStart = document.getElementById('fStartsAt')._flatpickr;
    const fpEnd   = document.getElementById('fEndsAt')._flatpickr;
    fpStart ? fpStart.setDate(b.starts_at || '', false) : (document.getElementById('fStartsAt').value = b.starts_at || '');
    fpEnd   ? fpEnd.setDate(b.ends_at   || '', false)   : (document.getElementById('fEndsAt').value   = b.ends_at   || '');
    // Show existing image preview
    const wrap = document.getElementById('imgPreviewWrap');
    const img  = document.getElementById('imgPreview');
    if (b.image_url) { img.src = b.image_url; wrap.classList.remove('d-none'); }
    else             { img.src = ''; wrap.classList.add('d-none'); }
    document.getElementById('imageInput').value = '';
    document.getElementById('modalAlert').classList.add('d-none');
    modal.show();
}

function previewImg(input) {
    const wrap = document.getElementById('imgPreviewWrap');
    const img  = document.getElementById('imgPreview');
    if (input.files && input.files[0]) {
        img.src = URL.createObjectURL(input.files[0]);
        wrap.classList.remove('d-none');
    }
}

async function submitBanner(e) {
    e.preventDefault();
    const btn     = document.getElementById('submitBtn');
    const spinner = document.getElementById('submitSpinner');
    const alertEl = document.getElementById('modalAlert');
    btn.disabled  = true;
    spinner.classList.remove('d-none');
    alertEl.classList.add('d-none');

    // If filter builder mode is active, write the generated URL to the hidden input.
    if (document.getElementById('modeFilter').checked) {
        document.getElementById('fTargetUrl').value = document.getElementById('fbUrlPreview').textContent;
    }
    const formData = new FormData(e.target);
    formData.set(CSRF_TOKEN, CSRF_HASH);
    const url = editId ? `${BASE}/${editId}/update` : `${BASE}/store`;

    try {
        const res  = await fetch(url, { method: 'POST', body: formData });
        const json = await res.json();
        if (json.success === true) {
            modal.hide();
            showAlert('Banner saved successfully.', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            alertEl.textContent = json.error?.message ?? 'An error occurred.';
            alertEl.classList.remove('d-none');
        }
    } catch (err) {
        alertEl.textContent = 'Network error. Please try again.';
        alertEl.classList.remove('d-none');
    } finally {
        btn.disabled = false;
        spinner.classList.add('d-none');
    }
}

async function toggleBanner(id, btn) {
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.set(CSRF_TOKEN, CSRF_HASH);
        const res  = await fetch(`${BASE}/${id}/toggle`, { method: 'POST', body: fd });
        const json = await res.json();
        if (json.success === true) {
            showAlert(`Banner ${json.data.status === 'active' ? 'enabled' : 'disabled'}.`, 'success');
            setTimeout(() => location.reload(), 600);
        } else {
            showAlert(json.error?.message ?? 'Failed to toggle banner.', 'danger');
            btn.disabled = false;
        }
    } catch {
        showAlert('Network error.', 'danger');
        btn.disabled = false;
    }
}

function deleteBanner(id, title) {
    if (!confirm(`Delete banner "${title}"? This cannot be undone.`)) return;
    const fd = new FormData();
    fd.set(CSRF_TOKEN, CSRF_HASH);
    fetch(`${BASE}/${id}/delete`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(json => {
            if (json.success === true) {
                document.getElementById(`card-${id}`)?.remove();
                showAlert('Banner deleted.', 'success');
            } else {
                showAlert(json.error?.message ?? 'Failed to delete.', 'danger');
            }
        })
        .catch(() => showAlert('Network error.', 'danger'));
}

function showAlert(msg, type = 'success') {
    const el = document.getElementById('alertBox');
    el.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">
        ${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
}
</script>
<?= $this->endSection() ?>
