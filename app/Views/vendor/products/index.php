<?= $this->extend('layouts/vendor') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $badge = ['draft' => 'secondary', 'submitted' => 'info', 'under_review' => 'warning', 'approved' => 'primary', 'rejected' => 'danger', 'published' => 'success', 'unpublished' => 'secondary']; ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold">My Products <span class="text-secondary small fw-normal">(<?= count($products) ?>)</span></span>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <form id="bulkForm" method="post" action="<?= site_url('vendor/products/bulk') ?>" class="d-flex gap-1 align-items-center" data-confirm="Apply this action to the selected products?" data-ajax-refresh>
                <?= csrf_field() ?>
                <select name="bulk_action" class="form-select form-select-sm" style="width:auto" required>
                    <option value="">Bulk action…</option>
                    <option value="submit">Submit for approval</option>
                    <option value="publish">Publish</option>
                    <option value="unpublish">Unpublish</option>
                    <option value="delete">Delete drafts</option>
                </select>
                <button class="btn btn-sm btn-outline-primary">Apply</button>
            </form>
            <a href="<?= site_url('vendor/products/trash') ?>" class="btn btn-sm btn-outline-secondary" title="Deleted drafts"><i class="bi bi-trash"></i></a>
            <a href="<?= site_url('vendor/products/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Product</a>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($products)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-box-seam display-6 d-block mb-2"></i>You have no products yet.</div>
        <?php else: ?>
        <div class="table-responsive" data-ajax-region><table id="vProductsTable" class="table table-hover align-middle w-100">
            <thead class="table-light"><tr><th style="width:28px"><input type="checkbox" id="selAll" class="form-check-input"></th><th>Product</th><th>Category</th><th>SKU</th><th>MRP</th><th>Price</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><input type="checkbox" name="ids[]" value="<?= esc($p['id'], 'attr') ?>" form="bulkForm" class="form-check-input rowchk"></td>
                    <td class="fw-semibold"><?= esc($p['title']) ?></td>
                    <td><?= esc($p['category'] ?? '—') ?></td>
                    <td class="small text-secondary"><?= esc($p['sku'] ?? '—') ?></td>
                    <td><?= $p['mrp'] !== null ? '₹' . esc(number_format((float) $p['mrp'], 2)) : '—' ?></td>
                    <td><?= $p['base_price'] !== null ? '₹' . esc(number_format((float) $p['base_price'], 2)) : '—' ?></td>
                    <td><span class="badge text-bg-<?= esc($badge[$p['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $p['status'])) ?></span></td>
                    <td class="text-end"><div class="btn-group btn-group-sm"><a href="<?= site_url('vendor/products/' . $p['id'] . '/variants') ?>" class="btn btn-outline-secondary" title="Variants"><i class="bi bi-grid-3x3-gap"></i></a><a href="<?= site_url('vendor/products/' . $p['id'] . '/inventory') ?>" class="btn btn-outline-secondary" title="Stock"><i class="bi bi-boxes"></i></a><a href="<?= site_url('vendor/products/' . $p['id'] . '/pricing') ?>" class="btn btn-outline-secondary" title="Pricing"><i class="bi bi-tag"></i></a><button type="button" class="btn btn-outline-secondary js-type-setup" data-url="<?= site_url('vendor/products/' . $p['id'] . '/type') ?>" data-title="<?= esc($p['title'], 'attr') ?>" title="Type setup"><i class="bi bi-collection"></i></button><a href="<?= site_url('vendor/products/' . $p['id'] . '/edit') ?>" class="btn btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a><button form="dup<?= $p['id'] ?>" class="btn btn-outline-secondary" title="Duplicate"><i class="bi bi-files"></i></button><?php if ($p['status'] === 'draft'): ?><button form="del<?= $p['id'] ?>" class="btn btn-outline-danger" title="Delete draft"><i class="bi bi-trash"></i></button><?php endif; ?></div><form id="dup<?= $p['id'] ?>" method="post" action="<?= site_url('vendor/products/' . $p['id'] . '/duplicate') ?>" class="d-none"><?= csrf_field() ?></form><?php if ($p['status'] === 'draft'): ?><form id="del<?= $p['id'] ?>" method="post" action="<?= site_url('vendor/products/' . $p['id'] . '/delete') ?>" class="d-none" data-confirm="Delete this draft product? This cannot be undone." data-ajax-refresh><?= csrf_field() ?></form><?php endif; ?>
                        <?php if (in_array($p['status'], ['draft', 'rejected'], true)): ?>
                            <form method="post" action="<?= site_url('vendor/products/' . $p['id'] . '/submit') ?>" class="d-inline ms-1"><?= csrf_field() ?><button class="btn btn-sm btn-primary" title="Submit for approval"><i class="bi bi-send me-1"></i>Submit</button></form>
                        <?php elseif (in_array($p['status'], ['approved', 'unpublished'], true)): ?>
                            <form method="post" action="<?= site_url('vendor/products/' . $p['id'] . '/publish') ?>" class="d-inline ms-1" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-success" title="Make live on the store"><i class="bi bi-globe me-1"></i>Publish</button></form>
                        <?php elseif ($p['status'] === 'published'): ?>
                            <form method="post" action="<?= site_url('vendor/products/' . $p['id'] . '/unpublish') ?>" class="d-inline ms-1" data-confirm="Hide this product from the store?" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" title="Hide from the store"><i class="bi bi-eye-slash me-1"></i>Unpublish</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<!-- Type setup in a modal (no full page for what's often a one-line note) -->
<div class="modal fade" id="typeModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Type setup</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="typeModalBody"></div>
</div></div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/datatables/jquery.dataTables.min.js') ?>"></script>
<script src="<?= asset('plugins/datatables/dataTables.bootstrap5.min.js') ?>"></script>
<script>$(function(){ if($.fn.DataTable && document.getElementById('vProductsTable')) $('#vProductsTable').DataTable({ pageLength: 10, columnDefs: [{ orderable: false, targets: [0, -1] }] }); });</script>
<script>
document.getElementById('selAll') && document.getElementById('selAll').addEventListener('change', function () {
    var on = this.checked;
    document.querySelectorAll('.rowchk').forEach(function (c) { c.checked = on; });
});
</script>
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
