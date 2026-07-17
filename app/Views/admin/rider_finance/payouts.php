<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $sb = ['created' => 'info', 'completed' => 'success', 'partial' => 'warning', 'failed' => 'danger']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<?php $totalEligible = array_sum(array_map(static fn ($e) => (float) $e['gross'], $eligible)); ?>
<div class="row g-3 mb-3">
    <div class="col-md-8">
        <div class="card h-100"><div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <div class="fw-semibold">Accrued rider earnings awaiting payout</div>
                <div class="text-secondary small"><?= count($eligible) ?> rider(s) · ₹<?= esc(number_format($totalEligible, 2)) ?></div>
            </div>
            <form method="post" action="<?= site_url('admin/rider-finance/payouts/run') ?>" data-confirm="Create a rider payout batch for all accrued earnings?">
                <?= csrf_field() ?>
                <button class="btn btn-primary" <?= empty($eligible) ? 'disabled' : '' ?>><i class="bi bi-cash-stack me-1"></i>Run payout batch</button>
            </form>
        </div></div>
    </div>
    <div class="col-md-4"><a href="<?= site_url('admin/rider-finance/cod') ?>" class="card h-100 text-decoration-none text-reset"><div class="card-body d-flex align-items-center"><i class="bi bi-wallet2 me-2 text-primary fs-4"></i><span>COD reconciliation</span></div></a></div>
</div>

<?php if (! empty($eligible)): ?>
<div class="card mb-3"><div class="card-header fw-semibold">Eligible riders</div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Rider</th><th class="text-end">Deliveries</th><th class="text-end">Earnings</th></tr></thead>
    <tbody>
    <?php foreach ($eligible as $e): ?>
        <tr><td class="fw-medium"><?= esc($e['rider'] ?? ('#' . $e['rider_user_id'])) ?></td><td class="text-end"><?= (int) $e['entries'] ?></td><td class="text-end fw-semibold">₹<?= esc(number_format((float) $e['gross'], 2)) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<?php endif; ?>

<div class="card"><div class="card-header fw-semibold"><i class="bi bi-collection me-1"></i>Payout batches</div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>#</th><th class="text-end">Riders</th><th class="text-end">Total</th><th>Status</th><th>Created</th><th class="text-end"></th></tr></thead>
    <tbody>
    <?php foreach ($batches as $b): ?>
        <tr>
            <td>#<?= (int) $b['id'] ?></td>
            <td class="text-end"><?= (int) $b['rider_count'] ?></td>
            <td class="text-end fw-semibold">₹<?= esc(number_format((float) $b['total_amount'], 2)) ?></td>
            <td><span class="badge text-bg-<?= esc($sb[$b['status']] ?? 'secondary', 'attr') ?>"><?= esc($b['status']) ?></span></td>
            <td class="small text-secondary"><?= esc(substr((string) $b['created_at'], 0, 16)) ?></td>
            <td class="text-end"><a href="<?= site_url('admin/rider-finance/payouts/' . $b['id']) ?>" class="btn btn-sm btn-outline-secondary">Open</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($batches)): ?><tr><td colspan="6" class="text-center text-secondary py-4">No payout batches yet.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= $this->endSection() ?>
