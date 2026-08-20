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
$fType    = $filters['type'] ?? '';
$base     = array_filter(['status' => $fStatus, 'q' => $fQ, 'type' => $fType, 'per_page' => $perPage], static fn ($v) => $v !== '');
$qs       = $base ? http_build_query($base) : '';
$mk       = static fn (int $n) => site_url('admin/vendors') . '?' . http_build_query($base + ['page' => $n]);
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <span class="fw-semibold">Vendors <span class="text-secondary small fw-normal">(<?= number_format((int) $total) ?>)</span></span>
            <a href="<?= site_url('admin/vendors/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Vendor</a>
        </div>
        <form method="get" action="<?= site_url('admin/vendors') ?>" class="row g-2">
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
                <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All types</option>
                    <option value="unassigned" <?= $fType === 'unassigned' ? 'selected' : '' ?>>⚠ No type assigned</option>
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
                <a href="<?= site_url('admin/vendors') ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        <table id="vendorsTable" class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Vendor</th><th>Type</th><th>GSTIN</th><th>GST</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($vendors as $v): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><a href="<?= site_url('admin/vendors/' . $v['id']) ?>" class="text-reset text-decoration-none"><?= esc($v['display_name']) ?></a></div>
                        <div class="text-secondary small"><?= esc($v['slug']) ?></div>
                    </td>
                    <td>
                        <?php if (! empty($v['business_type'])): ?>
                            <span class="badge text-bg-info text-dark"><?= esc($v['business_type']) ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-warning text-dark">None</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?= esc($v['gstin'] ?? '—') ?></code></td>
                    <td><span class="badge text-bg-<?= esc($gstBadge[$v['gstin_status']] ?? 'secondary', 'attr') ?>"><?= esc($v['gstin_status']) ?></span></td>
                    <td><span class="badge text-bg-<?= esc($statusBadge[$v['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $v['status'])) ?></span></td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1">
                            <a href="<?= site_url('admin/vendors/' . $v['id']) ?>" class="btn btn-sm btn-outline-primary" title="View details"><i class="bi bi-eye"></i></a>
                            <a href="<?= site_url('admin/vendors/' . $v['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <a href="<?= site_url('admin/vendors/' . $v['id'] . '/documents') ?>" class="btn btn-sm btn-outline-secondary" title="KYC documents"><i class="bi bi-file-earmark-text"></i></a>
                            <a href="<?= site_url('admin/vendors/' . $v['id'] . '/statement') ?>" class="btn btn-sm btn-outline-secondary" title="Financial statement"><i class="bi bi-receipt"></i></a>
                            <?php // Approve/Reject moved to the dedicated "Pending Approval → Vendor
                                  // Approval" queue (admin/vendor-approvals) — this list no longer shows
                                  // them at all, regardless of status, so there is no disabled-state logic
                                  // left to get wrong here (Reject used to stay clickable on an
                                  // already-approved vendor; the queue's own query only ever lists
                                  // submitted/under_review rows, so the question does not arise there). ?>
                            <?php // 'terminated' has no button here at all — VendorController refuses both
                                  // directions server-side for it; a disabled button that still POSTs
                                  // wrong would be worse than none. ?>
                            <?php if ($v['status'] !== 'terminated'): ?>
                                <?php if ($v['status'] === 'active'): ?>
                                    <form method="post" action="<?= site_url('admin/vendors/' . $v['id'] . '/deactivate') ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-warning"><i class="bi bi-pause-circle"></i> Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= site_url('admin/vendors/' . $v['id'] . '/activate') ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-success"><i class="bi bi-play-circle"></i> Activate</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($vendors)): ?>
                <tr><td colspan="6" class="text-center text-secondary py-4"><?= $qs ? 'No vendors match these filters.' : 'No vendors yet.' ?></td></tr>
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
