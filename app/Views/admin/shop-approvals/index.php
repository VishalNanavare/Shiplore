<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$fq    = array_filter(['q' => $filters['q'] ?? '', 'per_page' => $perPage], static fn ($v) => $v !== '' && $v !== null);
$qs    = http_build_query($fq);
$pp    = (int) $perPage;
$pages = max(1, (int) ceil((int) $total / max(1, $pp)));
$mk    = static fn (int $n) => site_url('admin/shop-approvals') . '?' . http_build_query($fq + ['page' => $n]);
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
<div id="shopApprovalRegion" data-ajax-region>
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <span class="fw-semibold">Shop Approval <span class="text-secondary small fw-normal">(<?= number_format((int) $total) ?> awaiting review)</span></span>
        </div>
        <p class="text-secondary small mb-2"><i class="bi bi-info-circle me-1"></i>A vendor's first shop goes live automatically at registration. Every shop after that needs approval here before it can go live.</p>
        <form method="get" action="<?= site_url('admin/shop-approvals') ?>" class="row g-2">
            <div class="col-12 col-md"><input type="search" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>" class="form-control form-control-sm" placeholder="Search shop, code or vendor…"></div>
            <div class="col-6 col-md-auto">
                <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ([10, 20, 50, 100] as $opt): ?><option value="<?= $opt ?>" <?= $pp === $opt ? 'selected' : '' ?>><?= $opt ?>/page</option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-auto d-flex gap-1">
                <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="<?= site_url('admin/shop-approvals') ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Shop</th><th>Vendor</th><th>Pincode</th><th>Submitted</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($shops as $s): ?>
                <tr>
                    <td>
                        <a href="<?= site_url('admin/shops/' . $s['id']) ?>" class="text-decoration-none fw-semibold"><?= esc($s['name']) ?></a>
                        <div class="text-secondary small"><?= esc($s['code'] ?? '') ?></div>
                    </td>
                    <td class="small">
                        <?php if (! empty($s['vendor_id'])): ?><a href="<?= site_url('admin/vendors/' . $s['vendor_id']) ?>" class="text-decoration-none"><?= esc($s['vendor'] ?? '—') ?></a><?php else: ?><?= esc($s['vendor'] ?? '—') ?><?php endif; ?>
                    </td>
                    <td class="small"><?= esc($s['pincode'] ?? '—') ?></td>
                    <td><span class="text-secondary small"><?= esc(substr((string) ($s['created_at'] ?? ''), 0, 10)) ?></span></td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1">
                            <a href="<?= site_url('admin/shops/' . $s['id']) ?>" class="btn btn-sm btn-outline-primary" title="View details"><i class="bi bi-eye"></i></a>
                            <form method="post" action="<?= site_url('admin/shop-approvals/' . $s['id'] . '/approve') ?>" data-ajax-refresh="#shopApprovalRegion"><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Approve</button></form>
                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectShop<?= $s['id'] ?>"><i class="bi bi-x-lg"></i> Reject</button>
                        </div>
                        <div class="modal fade" id="rejectShop<?= $s['id'] ?>" tabindex="-1">
                            <div class="modal-dialog"><div class="modal-content">
                                <form method="post" action="<?= site_url('admin/shop-approvals/' . $s['id'] . '/reject') ?>" data-ajax-refresh="#shopApprovalRegion">
                                    <?= csrf_field() ?>
                                    <div class="modal-header"><h5 class="modal-title">Reject "<?= esc($s['name']) ?>"</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                    <div class="modal-body text-start"><label class="form-label">Reason (optional)</label><textarea name="reason" class="form-control" rows="3" placeholder="Tell the vendor what to fix…"></textarea></div>
                                    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Reject shop</button></div>
                                </form>
                            </div></div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($shops)): ?><tr><td colspan="5" class="text-center text-secondary py-5"><i class="bi bi-check2-circle display-6 d-block mb-2"></i><?= $qs !== '' ? 'No pending shops match this search.' : 'No shops are awaiting review.' ?></td></tr><?php endif; ?>
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
