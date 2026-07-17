<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $cb = ['pending' => 'secondary', 'collected' => 'warning', 'deposited' => 'info', 'reconciled' => 'success', 'short' => 'danger']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="text-secondary small">Cash a rider collected on delivery vs what they have deposited.</div>
    <a href="<?= site_url('admin/rider-finance/payouts') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-cash-coin me-1"></i>Rider payouts</a>
</div>

<?php
$totOut = array_sum(array_map(static fn ($s) => (float) $s['outstanding'], $summary));
?>
<div class="card mb-3"><div class="card-header fw-semibold d-flex justify-content-between">
    <span><i class="bi bi-wallet2 me-1"></i>COD position by rider</span>
    <span class="text-danger">Total outstanding ₹<?= esc(number_format($totOut, 2)) ?></span>
</div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Rider</th><th>Vendor</th><th class="text-end">Trips</th><th class="text-end">Collected</th><th class="text-end">Deposited</th><th class="text-end">Outstanding</th><th>Last</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($summary as $s): $uid = (int) $s['rider_user_id']; $out = (float) $s['outstanding']; ?>
        <tr class="<?= $riderId === $uid ? 'table-active' : '' ?>">
            <td class="fw-medium"><?= esc($s['rider'] ?? ('#' . $uid)) ?></td>
            <td class="small text-secondary"><?= esc($s['vendor'] ?? '—') ?></td>
            <td class="text-end"><?= (int) $s['trips'] ?></td>
            <td class="text-end">₹<?= esc(number_format((float) $s['collected'], 2)) ?></td>
            <td class="text-end">₹<?= esc(number_format((float) $s['deposited'], 2)) ?></td>
            <td class="text-end fw-semibold <?= $out > 0 ? 'text-danger' : 'text-success' ?>">₹<?= esc(number_format($out, 2)) ?></td>
            <td class="small text-secondary"><?= esc($s['last_collected'] ? substr((string) $s['last_collected'], 0, 10) : '—') ?></td>
            <td class="text-end text-nowrap">
                <a href="<?= site_url('admin/rider-finance/cod?rider=' . $uid) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list-ul"></i></a>
                <a href="<?= site_url('admin/rider-finance/statement/' . $uid) ?>" class="btn btn-sm btn-outline-secondary" title="Statement"><i class="bi bi-file-earmark-text"></i></a>
                <?php if ($out > 0): ?>
                    <form method="post" action="<?= site_url('admin/rider-finance/cod/rider/' . $uid . '/deposit-all') ?>" class="d-inline" data-confirm="Mark all outstanding COD for this rider as deposited?">
                        <?= csrf_field() ?><button class="btn btn-sm btn-outline-success"><i class="bi bi-bank me-1"></i>Deposit all</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($summary)): ?><tr><td colspan="8" class="text-center text-secondary py-4">No COD cash has been collected yet.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>

<?php if ($riderId !== null): ?>
<div class="card"><div class="card-header fw-semibold"><i class="bi bi-receipt me-1"></i>Collection lines — rider #<?= (int) $riderId ?></div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Order</th><th>Delivery</th><th class="text-end">Amount</th><th>Status</th><th>Collected</th><th>Deposited</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $l): ?>
        <tr>
            <td><?= esc($l['order_no'] ?? '—') ?></td>
            <td>#<?= (int) $l['delivery_id'] ?></td>
            <td class="text-end">₹<?= esc(number_format((float) $l['amount'], 2)) ?><?php if ((float) $l['variance'] != 0.0): ?> <span class="text-danger small">(var <?= esc(number_format((float) $l['variance'], 2)) ?>)</span><?php endif; ?></td>
            <td><span class="badge text-bg-<?= esc($cb[$l['status']] ?? 'secondary', 'attr') ?>"><?= esc($l['status']) ?></span></td>
            <td class="small text-secondary"><?= esc($l['collected_at'] ? substr((string) $l['collected_at'], 0, 16) : '—') ?></td>
            <td class="small text-secondary"><?= esc($l['deposited_at'] ? substr((string) $l['deposited_at'], 0, 16) : '—') ?></td>
            <td class="text-end text-nowrap">
                <?php if (in_array($l['status'], ['pending', 'collected', 'short'], true)): ?>
                    <form method="post" action="<?= site_url('admin/rider-finance/cod/' . $l['id'] . '/deposit') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-info" title="Mark deposited"><i class="bi bi-bank"></i></button></form>
                <?php endif; ?>
                <?php if ($l['status'] !== 'reconciled'): ?>
                    <form method="post" action="<?= site_url('admin/rider-finance/cod/' . $l['id'] . '/reconcile') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-success" title="Mark reconciled"><i class="bi bi-check2-circle"></i></button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($lines)): ?><tr><td colspan="7" class="text-center text-secondary py-4">No collections for this rider.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?php endif; ?>
<?= $this->endSection() ?>
