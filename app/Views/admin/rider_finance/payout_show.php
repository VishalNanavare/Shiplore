<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$sb  = ['created' => 'info', 'completed' => 'success', 'partial' => 'warning', 'failed' => 'danger'];
$psb = ['pending' => 'secondary', 'paid' => 'success', 'failed' => 'danger', 'held' => 'warning'];
$open = $batch['status'] !== 'completed' && $batch['status'] !== 'failed';
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="<?= site_url('admin/rider-finance/payouts') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>All batches</a>
    <?php if ($open): ?>
    <div class="d-flex gap-2">
        <form method="post" action="<?= site_url('admin/rider-finance/payouts/' . $batch['id'] . '/paid') ?>" data-confirm="Mark the whole batch paid?"><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-check2-circle me-1"></i>Mark batch paid</button></form>
        <form method="post" action="<?= site_url('admin/rider-finance/payouts/' . $batch['id'] . '/failed') ?>" data-confirm="Mark the batch failed? Entries return to the accrued pool."><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Mark failed</button></form>
    </div>
    <?php endif; ?>
</div>

<div class="card mb-3"><div class="card-body d-flex justify-content-between flex-wrap">
    <div><div class="text-secondary small">Batch</div><div class="h5 mb-0">#<?= (int) $batch['id'] ?> <span class="badge text-bg-<?= esc($sb[$batch['status']] ?? 'secondary', 'attr') ?>"><?= esc($batch['status']) ?></span></div></div>
    <div class="text-end"><div class="text-secondary small">Total</div><div class="h5 mb-0 text-primary">₹<?= esc(number_format((float) $batch['total_amount'], 2)) ?></div></div>
    <div class="text-end"><div class="text-secondary small">Riders</div><div class="h5 mb-0"><?= (int) $batch['rider_count'] ?></div></div>
</div></div>

<div class="card"><div class="card-header fw-semibold">Rider payouts</div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Rider</th><th class="text-end">Deliveries</th><th class="text-end">Earnings</th><th class="text-end">COD held</th><th class="text-end">Net pay</th><th>Status</th><th class="text-end"></th></tr></thead>
    <tbody>
    <?php foreach ($payouts as $p): ?>
        <tr>
            <td class="fw-medium"><?= esc($p['rider'] ?? ('#' . $p['rider_user_id'])) ?></td>
            <td class="text-end"><?= (int) $p['entries_count'] ?></td>
            <td class="text-end">₹<?= esc(number_format((float) $p['gross_amount'], 2)) ?></td>
            <td class="text-end <?= (float) $p['cod_outstanding'] > 0 ? 'text-danger' : 'text-secondary' ?>">₹<?= esc(number_format((float) $p['cod_outstanding'], 2)) ?></td>
            <td class="text-end fw-semibold">₹<?= esc(number_format((float) $p['net_amount'], 2)) ?></td>
            <td><span class="badge text-bg-<?= esc($psb[$p['status']] ?? 'secondary', 'attr') ?>"><?= esc($p['status']) ?></span></td>
            <td class="text-end">
                <a href="<?= site_url('admin/rider-finance/statement/' . $p['rider_user_id']) ?>" class="btn btn-sm btn-outline-secondary" title="Statement"><i class="bi bi-file-earmark-text"></i></a>
                <?php if ($p['status'] === 'pending'): ?>
                    <form method="post" action="<?= site_url('admin/rider-finance/payout-rider/' . $p['id'] . '/paid') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-success" title="Mark this rider paid"><i class="bi bi-check2"></i></button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($payouts)): ?><tr><td colspan="7" class="text-center text-secondary py-4">No payouts in this batch.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= $this->endSection() ?>
