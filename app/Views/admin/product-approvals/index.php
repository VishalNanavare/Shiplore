<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$statusBadge = ['draft' => 'secondary', 'submitted' => 'info', 'under_review' => 'warning', 'approved' => 'success', 'published' => 'primary', 'rejected' => 'danger', 'unpublished' => 'dark'];
$fq   = array_filter([
    'q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? '',
    'vendor_id' => $filters['vendor_id'] ?? '', 'category_id' => $filters['category_id'] ?? '', 'per_page' => $perPage,
], static fn ($v) => $v !== '' && $v !== null);
$qs   = http_build_query($fq);
$pp   = (int) $perPage;
$pages = max(1, (int) ceil((int) $total / max(1, $pp)));
$mk    = static fn (int $n) => site_url('admin/product-approvals') . '?' . http_build_query($fq + ['page' => $n]);
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
<div id="approvalsRegion" data-ajax-region>
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <span class="fw-semibold">Product Approvals <span class="text-secondary small fw-normal">(<?= number_format((int) $total) ?>)</span></span>
            <form id="bulkApprForm" method="post" action="<?= site_url('admin/product-approvals/bulk') ?>" class="d-flex gap-1 align-items-center flex-wrap" data-confirm="Apply this action to the selected products?" data-ajax-refresh="#approvalsRegion">
                <?= csrf_field() ?>
                <select name="bulk_action" class="form-select form-select-sm" style="width:auto" required>
                    <option value="">Bulk action…</option>
                    <option value="approve">Approve</option>
                    <option value="reject">Reject</option>
                    <option value="publish">Publish</option>
                    <option value="unpublish">Unpublish</option>
                </select>
                <input type="text" name="reason" class="form-control form-control-sm" style="width:180px" placeholder="Reason (for reject)">
                <button class="btn btn-sm btn-outline-primary">Apply</button>
            </form>
        </div>
        <!-- Filters -->
        <form method="get" action="<?= site_url('admin/product-approvals') ?>" class="row g-2">
            <div class="col-12 col-md"><input type="search" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>" class="form-control form-control-sm" placeholder="Search title or slug…"></div>
            <div class="col-6 col-md-auto">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Pending (default)</option>
                    <?php foreach ($statuses as $s): ?><option value="<?= esc($s, 'attr') ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= esc(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <select name="vendor_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All vendors</option>
                    <?php foreach ($vendors as $v): ?><option value="<?= esc($v['id'], 'attr') ?>" <?= (int) ($filters['vendor_id'] ?? 0) === (int) $v['id'] ? 'selected' : '' ?>><?= esc($v['display_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <select name="category_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $c): ?><option value="<?= esc($c['id'], 'attr') ?>" <?= (int) ($filters['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= esc(str_repeat('— ', (int) $c['level']) . $c['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ([10, 20, 50, 100] as $opt): ?><option value="<?= $opt ?>" <?= $pp === $opt ? 'selected' : '' ?>><?= $opt ?>/page</option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-auto d-flex gap-1">
                <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="<?= site_url('admin/product-approvals') ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th style="width:28px"><input type="checkbox" id="apprSelAll" class="form-check-input"></th><th>Product</th><th>Vendor</th><th>Category</th><th>Status</th><th>Submitted</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><input type="checkbox" name="ids[]" value="<?= esc($p['id'], 'attr') ?>" form="bulkApprForm" class="form-check-input apprChk"></td>
                    <td><div class="fw-semibold"><?= esc($p['title']) ?></div><div class="text-secondary small"><?= esc($p['slug']) ?></div></td>
                    <td class="small"><?= esc($p['vendor'] ?? '—') ?></td>
                    <td class="small"><?= esc($p['category'] ?? '—') ?></td>
                    <td><span class="badge text-bg-<?= esc($statusBadge[$p['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $p['status'])) ?></span></td>
                    <td><span class="text-secondary small"><?= esc(substr((string) ($p['created_at'] ?? ''), 0, 10)) ?></span></td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1">
                            <?php if (in_array($p['status'], ['submitted', 'under_review'], true)): ?>
                                <form method="post" action="<?= site_url('admin/product-approvals/' . $p['id'] . '/approve') ?>" data-ajax-refresh="#approvalsRegion"><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Approve</button></form>
                                <form method="post" action="<?= site_url('admin/product-approvals/' . $p['id'] . '/request-changes') ?>" data-ajax-refresh="#approvalsRegion" title="Send back to vendor"><?= csrf_field() ?><button class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-counterclockwise"></i></button></form>
                                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $p['id'] ?>"><i class="bi bi-x-lg"></i></button>
                            <?php elseif (in_array($p['status'], ['approved', 'unpublished'], true)): ?>
                                <form method="post" action="<?= site_url('admin/product-approvals/' . $p['id'] . '/publish') ?>" data-ajax-refresh="#approvalsRegion"><?= csrf_field() ?><button class="btn btn-sm btn-primary"><i class="bi bi-globe"></i> Publish</button></form>
                            <?php elseif ($p['status'] === 'published'): ?>
                                <form method="post" action="<?= site_url('admin/product-approvals/' . $p['id'] . '/unpublish') ?>" data-ajax-refresh="#approvalsRegion"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye-slash"></i> Unpublish</button></form>
                            <?php endif; ?>
                            <a href="<?= site_url('store/product/' . $p['slug']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Preview"><i class="bi bi-box-arrow-up-right"></i></a>
                        </div>
                        <div class="modal fade" id="rejectModal<?= $p['id'] ?>" tabindex="-1">
                            <div class="modal-dialog"><div class="modal-content">
                                <form method="post" action="<?= site_url('admin/product-approvals/' . $p['id'] . '/reject') ?>" data-ajax-refresh="#approvalsRegion">
                                    <?= csrf_field() ?>
                                    <div class="modal-header"><h5 class="modal-title">Reject "<?= esc($p['title']) ?>"</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                    <div class="modal-body text-start"><label class="form-label">Reason (optional)</label><textarea name="reason" class="form-control" rows="3" placeholder="Tell the vendor what to fix…"></textarea></div>
                                    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Reject product</button></div>
                                </form>
                            </div></div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($products)): ?><tr><td colspan="7" class="text-center text-secondary py-5"><i class="bi bi-patch-check display-6 d-block mb-2"></i><?= $qs !== '' ? 'No products match these filters.' : 'No products are awaiting review.' ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="small text-secondary">Showing <?= number_format(($page - 1) * $pp + 1) ?>–<?= number_format(min((int) $total, $page * $pp)) ?> of <?= number_format((int) $total) ?></span>
            <nav><ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= esc($mk(max(1, $page - 1)), 'attr') ?>">«</a></li>
                <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= esc($mk($i), 'attr') ?>"><?= $i ?></a></li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= esc($mk(min($pages, $page + 1)), 'attr') ?>">»</a></li>
            </ul></nav>
        </div>
    <?php endif; ?>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
// Delegated select-all — survives partial-refresh region swaps.
document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'apprSelAll') {
        document.querySelectorAll('.apprChk').forEach(function (c) { c.checked = e.target.checked; });
    }
});
</script>
<?= $this->endSection() ?>
