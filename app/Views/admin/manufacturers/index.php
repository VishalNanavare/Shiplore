<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$statusBadge = [
    'draft' => 'secondary', 'submitted' => 'info', 'under_review' => 'info',
    'approved' => 'primary', 'active' => 'success',
    'suspended' => 'warning', 'terminated' => 'dark', 'rejected' => 'danger',
];
$gstBadge = ['unverified' => 'secondary', 'pending' => 'info', 'verified' => 'success', 'failed' => 'danger'];
$fStatus  = $filters['status'] ?? '';
$fQ       = $filters['q'] ?? '';
$base     = array_filter(['status' => $fStatus, 'q' => $fQ, 'per_page' => $perPage], static fn ($v) => $v !== '');
$qs       = $base ? http_build_query($base) : '';
$mk       = static fn (int $n) => site_url('admin/manufacturers') . '?' . http_build_query($base + ['page' => $n]);
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <span class="fw-semibold">Manufacturers <span class="text-secondary small fw-normal">(<?= number_format((int) $total) ?>)</span></span>
            <span class="text-secondary small">Manufacturers self-register at <code>manufacturer-register</code>.</span>
        </div>
        <form method="get" action="<?= site_url('admin/manufacturers') ?>" class="row g-2">
            <div class="col-12 col-md">
                <input type="search" name="q" value="<?= esc($fQ, 'attr') ?>" class="form-control form-control-sm" placeholder="Search name, slug or GSTIN…">
            </div>
            <div class="col-6 col-md-auto">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <?php foreach (['draft', 'submitted', 'under_review', 'approved', 'active', 'suspended', 'rejected', 'terminated'] as $s): ?>
                        <option value="<?= esc($s, 'attr') ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= esc(ucwords(str_replace('_', ' ', $s))) ?></option>
                    <?php endforeach; ?>
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
                <a href="<?= site_url('admin/manufacturers') ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Manufacturer</th><th>GSTIN</th><th>GST</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($manufacturers as $m): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><a href="<?= site_url('admin/manufacturers/' . $m['id']) ?>" class="text-reset text-decoration-none"><?= esc($m['display_name']) ?></a></div>
                        <div class="text-secondary small"><?= esc($m['slug']) ?></div>
                    </td>
                    <td><code><?= esc($m['gstin'] ?? '—') ?></code></td>
                    <td><span class="badge text-bg-<?= esc($gstBadge[$m['gstin_status']] ?? 'secondary', 'attr') ?>"><?= esc($m['gstin_status']) ?></span></td>
                    <td><span class="badge text-bg-<?= esc($statusBadge[$m['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $m['status'])) ?></span></td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1">
                            <a href="<?= site_url('admin/manufacturers/' . $m['id']) ?>" class="btn btn-sm btn-outline-primary" title="View details"><i class="bi bi-eye"></i></a>
                            <form method="post" action="<?= site_url('admin/manufacturers/' . $m['id'] . '/approve') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-success" <?= in_array($m['status'], ['approved', 'active'], true) ? 'disabled' : '' ?>>
                                    <i class="bi bi-check2"></i> Approve
                                </button>
                            </form>
                            <form method="post" action="<?= site_url('admin/manufacturers/' . $m['id'] . '/reject') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" <?= $m['status'] === 'rejected' ? 'disabled' : '' ?>>
                                    <i class="bi bi-x"></i> Reject
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($manufacturers)): ?>
                <tr><td colspan="5" class="text-center text-secondary py-4"><?= $qs ? 'No manufacturers match these filters.' : 'No manufacturers yet.' ?></td></tr>
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
            <?php for ($i = $start; $i <= $end; $i++): ?><li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= $mk($i) ?>"><?= $i ?></a></li><?php endfor; ?>
            <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $mk(min($pages, $page + 1)) ?>">»</a></li>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
