<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$statusBadge = ['active' => 'success', 'inactive' => 'secondary', 'closed_temp' => 'warning'];
$gstBadge    = ['unverified' => 'secondary', 'pending' => 'info', 'verified' => 'success', 'failed' => 'danger'];
$fStatus     = $filters['status'] ?? '';
$fQ          = $filters['q'] ?? '';
$base        = array_filter(['status' => $fStatus, 'q' => $fQ, 'per_page' => $perPage], static fn ($v) => $v !== '');
$qs          = $base ? http_build_query($base) : '';
$mk          = static fn (int $n) => site_url('admin/shops') . '?' . http_build_query($base + ['page' => $n]);
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <span class="fw-semibold">Shops <span class="text-secondary small fw-normal">(<?= number_format((int) $total) ?>)</span></span>
            <a href="<?= site_url('admin/shops/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Shop</a>
        </div>
        <form method="get" action="<?= site_url('admin/shops') ?>" class="row g-2">
            <div class="col-12 col-md">
                <input type="search" name="q" value="<?= esc($fQ, 'attr') ?>" class="form-control form-control-sm" placeholder="Search name, code, pincode or vendor…">
            </div>
            <div class="col-6 col-md-auto">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $fStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="closed_temp" <?= $fStatus === 'closed_temp' ? 'selected' : '' ?>>Closed (temp)</option>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ([25, 50, 100, 200] as $pp): ?>
                        <option value="<?= $pp ?>" <?= (int) $perPage === $pp ? 'selected' : '' ?>><?= $pp ?>/page</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-auto d-flex gap-1">
                <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="<?= site_url('admin/shops') ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Shop</th><th>Vendor</th><th>Pincode</th><th>GST</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($shops as $s): ?>
                <tr>
                    <td><div class="fw-semibold"><a href="<?= site_url('admin/shops/' . $s['id']) ?>" class="text-reset text-decoration-none"><?= esc($s['name']) ?></a></div><div class="text-secondary small"><?= esc($s['code']) ?></div></td>
                    <td><?= esc($s['vendor'] ?? '—') ?></td>
                    <td><?= esc($s['pincode'] ?? '—') ?></td>
                    <td><span class="badge text-bg-<?= esc($gstBadge[$s['gstin_status']] ?? 'secondary', 'attr') ?>"><?= esc($s['gstin_status']) ?></span></td>
                    <td><span class="badge text-bg-<?= esc($statusBadge[$s['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $s['status'])) ?></span></td>
                    <td class="text-end">
                        <a href="<?= site_url('admin/shops/' . $s['id']) ?>" class="btn btn-sm btn-outline-primary" title="View details"><i class="bi bi-eye"></i></a>
                        <a href="<?= site_url('admin/shops/' . $s['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                        <?php if ($s['status'] !== 'active'): ?>
                            <form method="post" action="<?= site_url('admin/shops/' . $s['id'] . '/activate') ?>" class="d-inline">
                                <?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Activate</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= site_url('admin/shops/' . $s['id'] . '/deactivate') ?>" class="d-inline">
                                <?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pause"></i> Deactivate</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($shops)): ?>
                <tr><td colspan="6" class="text-center text-secondary py-4"><?= $qs ? 'No shops match these filters.' : 'No shops yet.' ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    $pages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
    $from  = ($page - 1) * $perPage + 1;
    $to    = min((int) $total, $page * $perPage);
    $win   = 2; $start = max(1, $page - $win); $end = min($pages, $page + $win);
    ?>
    <?php if ($total > $perPage): ?>
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
<?= $this->endSection() ?>
