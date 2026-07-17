<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$perPage = (int) ($perPage ?? 25);
$page    = (int) ($page ?? 1);
$total   = (int) ($total ?? 0);
$pages   = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
$from    = ($page - 1) * $perPage + 1;
$to      = min($total, $page * $perPage);
$mk      = static fn (int $n) => site_url('admin/vendors/type-mismatches') . '?per_page=' . $perPage . '&page=' . $n;
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('warning')): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <a href="<?= site_url('admin/vendors') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back to vendors</a>
    </div>
    <?php if ($total > 0): ?>
    <form method="post" action="<?= site_url('admin/vendors/auto-fix-type-mismatches') ?>"
          onsubmit="return confirm('Auto-fix will move ALL mismatched products with no orders to the lowest-id matching vendor. This cannot be undone easily. Continue?');">
        <?= csrf_field() ?>
        <button class="btn btn-sm btn-danger"><i class="bi bi-magic me-1"></i>Auto-Fix All</button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
        <span><i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Business-Type Mismatches <span class="text-secondary small fw-normal">(<?= number_format($total) ?> vendor<?= $total !== 1 ? 's' : '' ?>)</span></span>
        <?php if ($total === 0): ?>
            <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>All clear</span>
        <?php endif; ?>
    </div>

    <?php if ($total === 0): ?>
    <div class="card-body text-center text-secondary py-5">
        <i class="bi bi-patch-check fs-1 text-success d-block mb-3"></i>
        <strong>No mismatches found.</strong><br>
        Every product is assigned to a vendor whose business type allows that product's category.
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Vendor</th>
                    <th>Business Type</th>
                    <th class="text-end">Mismatched Products</th>
                    <th>Sample Categories</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($vendors as $v): ?>
                <tr>
                    <td class="fw-medium">
                        <a href="<?= site_url('admin/vendors/' . $v['id']) ?>" class="text-reset text-decoration-none"><?= esc($v['display_name']) ?></a>
                        <div class="text-secondary small">#<?= (int) $v['id'] ?></div>
                    </td>
                    <td><span class="badge text-bg-info text-dark"><?= esc($v['business_type']) ?></span></td>
                    <td class="text-end fw-semibold text-danger"><?= number_format((int) $v['mismatch_count']) ?></td>
                    <td class="small text-secondary text-truncate" style="max-width:260px" title="<?= esc($v['sample_categories'] ?? '') ?>"><?= esc($v['sample_categories'] ?? '—') ?></td>
                    <td class="text-end">
                        <a href="<?= site_url('admin/vendors/' . $v['id'] . '/product-mismatches') ?>" class="btn btn-sm btn-outline-warning">
                            <i class="bi bi-arrow-right me-1"></i>Review &amp; Reassign
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total > $perPage): ?>
    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="small text-secondary">Showing <?= number_format($from) ?>–<?= number_format($to) ?> of <?= number_format($total) ?></span>
        <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $mk(max(1, $page - 1)) ?>">«</a></li>
            <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= $mk($i) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $mk(min($pages, $page + 1)) ?>">»</a></li>
        </ul></nav>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
