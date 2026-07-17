<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php $tone = ['on_hold' => 'warning', 'accrued' => 'info', 'settled' => 'success', 'reversed' => 'secondary', 'cancelled' => 'danger']; ?>
<div class="row g-3 mb-3">
    <?php foreach (['on_hold', 'accrued', 'settled', 'cancelled'] as $s): ?>
        <div class="col"><div class="card text-center"><div class="card-body py-3">
            <div class="text-secondary small text-uppercase"><?= esc(str_replace('_', ' ', $s)) ?></div>
            <div class="fs-5 fw-semibold">₹<?= esc(number_format((float) ($totals[$s] ?? 0), 2)) ?></div>
        </div></div></div>
    <?php endforeach; ?>
</div>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Commission by Order</span>
        <div class="d-flex gap-1">
            <?php foreach (array_keys($tone) as $s): ?>
                <a class="btn btn-sm btn-outline-secondary <?= $status === $s ? 'active' : '' ?>" href="<?= site_url('vendor/commission-holds?status=' . $s) ?>"><?= esc(str_replace('_', ' ', $s)) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Sub-order</th><th>Lines</th><th>Commission</th><th>Return window ends</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="fw-semibold small"><?= esc($r['sub_order_no'] ?? ('#' . $r['sub_order_id'])) ?></td>
                <td class="small"><?= (int) $r['line_count'] ?></td>
                <td>₹<?= esc(number_format((float) $r['commission'], 2)) ?></td>
                <td class="small text-secondary"><?= esc(substr((string) ($r['window_ends_at'] ?? '—'), 0, 16)) ?></td>
                <td><span class="badge text-bg-<?= esc($tone[$r['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $r['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?><tr><td colspan="5" class="text-center text-secondary py-4">Nothing in this state. Commission accrues only after the return window closes.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
