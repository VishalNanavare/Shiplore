<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$bb = ['created' => 'info', 'processing' => 'warning', 'completed' => 'success', 'partial' => 'warning', 'failed' => 'danger'];
$pb = ['pending' => 'secondary', 'processing' => 'info', 'paid' => 'success', 'failed' => 'danger', 'held' => 'warning'];
$open = ! in_array($batch['status'], ['completed', 'failed'], true);
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="mb-3 d-flex justify-content-between align-items-center">
    <a href="<?= site_url('admin/payouts') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back to payouts</a>
    <?php if ($open): ?>
    <div class="d-flex gap-2">
        <form method="post" action="<?= site_url('admin/payouts/' . $batch['id'] . '/mark-paid') ?>" data-confirm="Mark this batch paid? This settles all its vendors."><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-cash-coin me-1"></i>Mark paid</button></form>
        <form method="post" action="<?= site_url('admin/payouts/' . $batch['id'] . '/mark-failed') ?>" data-confirm="Mark this batch failed?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Mark failed</button></form>
    </div>
    <?php endif; ?>
</div>

<div class="card mb-3"><div class="card-body d-flex flex-wrap gap-4">
    <div><div class="text-secondary small">Batch</div><div class="fw-semibold">#<?= (int) $batch['id'] ?> · <span class="text-uppercase"><?= esc($batch['gateway']) ?></span></div></div>
    <div><div class="text-secondary small">Status</div><span class="badge text-bg-<?= esc($bb[$batch['status']] ?? 'secondary', 'attr') ?>"><?= esc($batch['status']) ?></span></div>
    <div><div class="text-secondary small">Vendors</div><div class="fw-semibold"><?= (int) $batch['count'] ?></div></div>
    <div><div class="text-secondary small">Total</div><div class="fw-semibold text-primary">₹<?= esc(number_format((float) $batch['total_amount'], 2)) ?></div></div>
</div></div>

<div class="card">
    <div class="card-header fw-semibold">Payouts in this batch</div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Vendor</th><th>Period</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($payouts as $p): ?>
            <tr>
                <td class="fw-medium"><?= esc($p['vendor'] ?? '—') ?></td>
                <td class="small text-secondary"><?= esc($p['period_start'] ?? '') ?> → <?= esc($p['period_end'] ?? '') ?></td>
                <td class="text-end">₹<?= esc(number_format((float) $p['amount'], 2)) ?></td>
                <td><span class="badge text-bg-<?= esc($pb[$p['status']] ?? 'secondary', 'attr') ?>"><?= esc($p['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($payouts)): ?><tr><td colspan="4" class="text-center text-secondary py-4">No payouts.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
