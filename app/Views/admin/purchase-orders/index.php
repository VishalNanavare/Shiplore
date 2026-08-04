<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$fStatus = $filters['status'] ?? '';
$fQ      = $filters['q'] ?? '';
$base    = array_filter(['status' => $fStatus, 'q' => $fQ, 'per_page' => $perPage], static fn ($v) => $v !== '');
$qs      = $base ? http_build_query($base) : '';
$mk      = static fn (int $n) => site_url('admin/purchase-orders') . '?' . http_build_query($base + ['page' => $n]);
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <span class="fw-semibold">B2B Purchase Orders <span class="text-secondary small fw-normal">(<?= number_format((int) $total) ?>)</span></span>
            <span class="text-secondary small">monline — vendors and shops buying from manufacturers.</span>
        </div>
        <form method="get" action="<?= site_url('admin/purchase-orders') ?>" class="row g-2">
            <div class="col-12 col-md">
                <input type="search" name="q" value="<?= esc($fQ, 'attr') ?>" class="form-control form-control-sm" placeholder="Search PO number, buyer or manufacturer…">
            </div>
            <div class="col-6 col-md-auto">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <?php foreach (['draft', 'placed', 'accepted', 'rejected', 'packed', 'dispatched', 'received', 'closed', 'cancelled'] as $s): ?>
                        <option value="<?= esc($s, 'attr') ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= esc(ucfirst($s)) ?></option>
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
                <a href="<?= site_url('admin/purchase-orders') ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>PO</th><th>Buyer</th><th>Manufacturer</th><th>Placed</th><th class="text-end">Total</th><th>Status</th><th class="text-end"></th></tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td><a href="<?= site_url('admin/purchase-orders/' . (int) $o['id']) ?>" class="fw-semibold text-decoration-none"><?= esc($o['po_no']) ?></a></td>
                    <td>
                        <div class="small"><?= esc((string) ($o['buyer_name'] ?? '—')) ?></div>
                        <div class="text-secondary" style="font-size:.75rem"><?= esc((string) ($o['buyer_shop_name'] ?? '')) ?></div>
                    </td>
                    <td class="small"><?= esc((string) ($o['seller_name'] ?? '—')) ?></td>
                    <td class="small text-secondary"><?= esc((string) ($o['placed_at'] ?? '—')) ?></td>
                    <td class="text-end">₹<?= esc(number_format((float) $o['grand_total'], 2)) ?></td>
                    <td><span class="badge text-bg-secondary"><?= esc(str_replace('_', ' ', (string) $o['status'])) ?></span></td>
                    <td class="text-end"><a href="<?= site_url('admin/purchase-orders/' . (int) $o['id']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($orders)): ?>
                <tr><td colspan="7" class="text-center text-secondary py-4"><?= $qs ? 'No purchase orders match these filters.' : 'No B2B purchase orders yet.' ?></td></tr>
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
