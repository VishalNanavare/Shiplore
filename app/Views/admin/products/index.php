<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $sb = ['draft' => 'secondary', 'submitted' => 'info', 'under_review' => 'info', 'approved' => 'primary', 'published' => 'success', 'rejected' => 'danger', 'unpublished' => 'dark']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
<?php
    // Current filter values + a query string (minus page) for export & pagination links.
    $fq   = ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? '', 'category_id' => $filters['category_id'] ?? '', 'type' => $filters['product_type'] ?? '', 'vendor_id' => $vendorId ?? '', 'per_page' => $perPage];
    $base = array_filter($fq, static fn ($v) => $v !== '' && $v !== null);
    $qs   = http_build_query($base);
    ?>
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <span class="fw-semibold">Products <span class="text-secondary small fw-normal">(<?= number_format((int) $total) ?>)</span></span>
            <div class="d-flex gap-2 flex-wrap">
                <form id="bulkForm" method="post" action="<?= site_url('admin/products/bulk') ?>" class="d-flex gap-1 align-items-center" data-confirm="Apply this action to the selected products?">
                    <?= csrf_field() ?>
                    <select name="bulk_action" class="form-select form-select-sm" style="width:auto" required>
                        <option value="">Bulk action…</option>
                        <option value="approve">Approve</option>
                        <option value="publish">Publish</option>
                        <option value="unpublish">Unpublish</option>
                        <option value="delete">Delete drafts</option>
                    </select>
                    <button class="btn btn-sm btn-outline-primary">Apply</button>
                </form>
                <a href="<?= site_url('admin/products/trash') . ($vendorId ? '?vendor_id=' . (int) $vendorId : '') ?>" class="btn btn-sm btn-outline-secondary" title="Deleted drafts"><i class="bi bi-trash"></i></a>
                <a href="<?= site_url('admin/imports') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-upload me-1"></i>Bulk import</a>
                <a href="<?= site_url('admin/products/export') . ($qs ? '?' . $qs : '') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download me-1"></i>Export</a>
                <a href="<?= site_url('admin/products/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Product</a>
            </div>
        </div>
        <!-- Filter bar -->
        <form method="get" action="<?= site_url('admin/products') ?>" class="row g-2">
            <div class="col-12 col-md">
                <input type="search" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>" class="form-control form-control-sm" placeholder="Search title, SKU or slug…">
            </div>
            <div class="col-6 col-md-auto">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <?php foreach ($statuses as $s): ?><option value="<?= esc($s, 'attr') ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= esc(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All types</option>
                    <?php foreach ($productTypes as $t): ?><option value="<?= esc($t, 'attr') ?>" <?= ($filters['product_type'] ?? '') === $t ? 'selected' : '' ?>><?= esc(ucfirst($t)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <select name="category_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $c): ?><option value="<?= esc($c['id'], 'attr') ?>" <?= (int) ($filters['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= esc(str_repeat('— ', (int) $c['level']) . $c['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <select name="vendor_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All vendors</option>
                    <?php foreach ($vendors as $v): ?><option value="<?= esc($v['id'], 'attr') ?>" <?= (int) $vendorId === (int) $v['id'] ? 'selected' : '' ?>><?= esc($v['display_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ([25, 50, 100, 200, 500] as $pp): ?><option value="<?= $pp ?>" <?= (string) $perPage === (string) $pp ? 'selected' : '' ?>><?= $pp ?>/page</option><?php endforeach; ?>
                    <option value="all" <?= $perPage === 'all' ? 'selected' : '' ?>>Show all</option>
                </select>
            </div>
            <div class="col-12 col-md-auto d-flex gap-1">
                <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="<?= site_url('admin/products') ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th style="width:28px"><input type="checkbox" id="selAll" class="form-check-input"></th><th>Product</th><th>Vendor</th><th>Category</th><th>SKU</th><th>Price</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td><input type="checkbox" name="ids[]" value="<?= esc($p['id'], 'attr') ?>" form="bulkForm" class="form-check-input rowchk"></td>
                <td class="fw-medium"><?= esc($p['title']) ?></td>
                <td class="small"><?= esc($p['vendor'] ?? '—') ?></td>
                <td class="small"><?= esc($p['category'] ?? '—') ?></td>
                <td><code><?= esc($p['sku'] ?? '—') ?></code></td>
                <td>₹<?= esc(number_format((float) ($p['base_price'] ?? 0), 0)) ?></td>
                <td><span class="badge text-bg-<?= esc($sb[$p['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $p['status'])) ?></span></td>
                <td class="text-end">
                    <?php if (in_array($p['status'], ['submitted', 'under_review'], true)): ?>
                        <span class="btn-group btn-group-sm me-1">
                            <button form="appr<?= $p['id'] ?>" class="btn btn-outline-success" title="Approve"><i class="bi bi-check2"></i></button>
                            <button form="rej<?= $p['id'] ?>" class="btn btn-outline-danger" title="Reject"><i class="bi bi-x-lg"></i></button>
                        </span>
                        <form id="appr<?= $p['id'] ?>" method="post" action="<?= site_url('admin/product-approvals/' . $p['id'] . '/approve') ?>" class="d-none"><?= csrf_field() ?></form>
                        <form id="rej<?= $p['id'] ?>" method="post" action="<?= site_url('admin/product-approvals/' . $p['id'] . '/reject') ?>" class="d-none"><?= csrf_field() ?></form>
                    <?php endif; ?>
                    <?php if (in_array($p['status'], ['approved', 'unpublished'], true)): ?>
                        <button form="pub<?= $p['id'] ?>" class="btn btn-sm btn-success me-1" title="Publish"><i class="bi bi-cloud-arrow-up me-1"></i>Publish</button>
                        <form id="pub<?= $p['id'] ?>" method="post" action="<?= site_url('admin/product-approvals/' . $p['id'] . '/publish') ?>" class="d-none" data-confirm="Publish this product to the storefront?"><?= csrf_field() ?></form>
                    <?php elseif ($p['status'] === 'published'): ?>
                        <button form="unpub<?= $p['id'] ?>" class="btn btn-sm btn-outline-dark me-1" title="Unpublish"><i class="bi bi-cloud-slash me-1"></i>Unpublish</button>
                        <form id="unpub<?= $p['id'] ?>" method="post" action="<?= site_url('admin/product-approvals/' . $p['id'] . '/unpublish') ?>" class="d-none" data-confirm="Hide this product from the storefront?"><?= csrf_field() ?></form>
                    <?php endif; ?>
                    <div class="btn-group btn-group-sm"><a href="<?= site_url('admin/products/' . $p['id'] . '/variants') ?>" class="btn btn-outline-secondary" title="Variants"><i class="bi bi-grid-3x3-gap"></i></a><a href="<?= site_url('admin/products/' . $p['id'] . '/inventory') ?>" class="btn btn-outline-secondary" title="Stock"><i class="bi bi-boxes"></i></a><a href="<?= site_url('admin/products/' . $p['id'] . '/pricing') ?>" class="btn btn-outline-secondary" title="Pricing"><i class="bi bi-tag"></i></a><button type="button" class="btn btn-outline-secondary js-type-setup" data-url="<?= site_url('admin/products/' . $p['id'] . '/type') ?>" data-title="<?= esc($p['title'], 'attr') ?>" title="Type setup"><i class="bi bi-collection"></i></button><a href="<?= site_url('admin/products/' . $p['id'] . '/edit') ?>" class="btn btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a><button class="btn btn-outline-secondary js-dup" data-bs-toggle="modal" data-bs-target="#dupModal" data-id="<?= $p['id'] ?>" data-title="<?= esc($p['title'], 'attr') ?>" title="Duplicate"><i class="bi bi-files"></i></button><?php if ($p['status'] === 'draft'): ?><button form="del<?= $p['id'] ?>" class="btn btn-outline-danger" title="Delete draft"><i class="bi bi-trash"></i></button><?php endif; ?></div><?php if ($p['status'] === 'draft'): ?><form id="del<?= $p['id'] ?>" method="post" action="<?= site_url('admin/products/' . $p['id'] . '/delete') ?>" class="d-none" data-confirm="Delete this draft product? This cannot be undone."><?= csrf_field() ?></form><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($products)): ?><tr><td colspan="8" class="text-center text-secondary py-4"><?= $qs !== '' ? 'No products match these filters.' : 'No products yet. Click "Add Product".' ?></td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?php if ($perPage !== 'all' && (int) $total > (int) $perPage): ?>
        <?php
        $pp    = (int) $perPage;
        $pages = (int) ceil($total / $pp);
        $from  = ($page - 1) * $pp + 1;
        $to    = min((int) $total, $page * $pp);
        $mk    = static fn ($n) => site_url('admin/products') . '?' . http_build_query($base + ['page' => $n]);
        $win   = 2;
        $start = max(1, $page - $win);
        $end   = min($pages, $page + $win);
        ?>
        <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="small text-secondary">Showing <?= number_format($from) ?>–<?= number_format($to) ?> of <?= number_format((int) $total) ?></span>
            <nav><ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $mk(max(1, $page - 1)) ?>">«</a></li>
                <?php if ($start > 1): ?><li class="page-item"><a class="page-link" href="<?= $mk(1) ?>">1</a></li><?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; endif; ?>
                <?php for ($i = $start; $i <= $end; $i++): ?><li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= $mk($i) ?>"><?= $i ?></a></li><?php endfor; ?>
                <?php if ($end < $pages): ?><?php if ($end < $pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?><li class="page-item"><a class="page-link" href="<?= $mk($pages) ?>"><?= $pages ?></a></li><?php endif; ?>
                <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $mk(min($pages, $page + 1)) ?>">»</a></li>
            </ul></nav>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="dupModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="post" id="dupForm"><?= csrf_field() ?>
        <div class="modal-header"><h5 class="modal-title">Duplicate "<span id="dupTitle"></span>"</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body text-start">
            <label class="form-label">Copy to vendor</label>
            <select name="target_vendor_id" class="form-select"><option value="">Same vendor (copy in place)</option><?php foreach ($vendors as $v): ?><option value="<?= esc($v['id'], 'attr') ?>"><?= esc($v['display_name']) ?></option><?php endforeach; ?></select>
            <div class="form-text">A new <strong>draft</strong> copy (variants &amp; content included; barcodes excluded). Cross-vendor copy needs the category allowed for that vendor's business type.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-files me-1"></i>Duplicate</button></div>
    </form>
</div></div></div>
<script>
document.addEventListener('show.bs.modal', function (e) {
    var btn = e.relatedTarget; if (!btn || !btn.classList.contains('js-dup')) { return; }
    document.getElementById('dupForm').action = '<?= site_url('admin/products') ?>/' + btn.getAttribute('data-id') + '/duplicate';
    document.getElementById('dupTitle').textContent = btn.getAttribute('data-title');
});
document.getElementById('selAll') && document.getElementById('selAll').addEventListener('change', function () {
    var on = this.checked;
    document.querySelectorAll('.rowchk').forEach(function (c) { c.checked = on; });
});
</script>

<!-- Type setup in a modal (no full page for what's often a one-line note) -->
<div class="modal fade" id="typeModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Type setup</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="typeModalBody"></div>
</div></div></div>
<script>
(function () {
    var el = document.getElementById('typeModal');
    if (!el || !window.bootstrap) { return; }
    var modal = new bootstrap.Modal(el), body = document.getElementById('typeModalBody'), title = el.querySelector('.modal-title');
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-type-setup'); if (!btn) { return; }
        e.preventDefault();
        title.textContent = (btn.getAttribute('data-title') || 'Product') + ' — type setup';
        body.innerHTML = '<div class="text-center text-secondary py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';
        modal.show();
        fetch(btn.getAttribute('data-url'), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(function (html) { body.innerHTML = html; })
            .catch(function () { body.innerHTML = '<div class="alert alert-danger mb-0">Could not load type setup.</div>'; });
    });
})();
</script>
<?= $this->endSection() ?>
