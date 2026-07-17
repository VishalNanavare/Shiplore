<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php $badge = ['draft' => 'secondary', 'calculated' => 'info', 'approved' => 'primary', 'paid' => 'success', 'held' => 'warning', 'failed' => 'danger']; ?>
<div class="mb-3"><a href="<?= site_url('vendor/settlements') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back</a></div>

<div class="row g-3">
    <div class="col-lg-8"><div class="card"><div class="card-header fw-semibold">Settlement lines</div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Type</th><th>Sub-order</th><th>Direction</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($lines as $l): ?>
                <tr><td class="text-capitalize"><?= esc($l['ref_type']) ?></td><td><?= esc($l['sub_order_id'] ?? '—') ?></td>
                    <td><span class="badge bg-<?= $l['direction'] === 'credit' ? 'success' : 'danger' ?>-subtle text-<?= $l['direction'] === 'credit' ? 'success' : 'danger' ?>"><?= esc($l['direction']) ?></span></td>
                    <td class="text-end"><?= $l['direction'] === 'debit' ? '−' : '' ?>₹<?= esc(number_format((float) $l['amount'], 2)) ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($lines)): ?><tr><td colspan="4" class="text-center text-secondary py-3">No lines.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div></div>
    <div class="col-lg-4"><div class="card"><div class="card-header fw-semibold">Summary</div><div class="card-body">
        <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Period</span><span class="small"><?= esc($settlement['period_start']) ?> → <?= esc($settlement['period_end']) ?></span></div>
        <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Status</span><span class="badge text-bg-<?= esc($badge[$settlement['status']] ?? 'secondary', 'attr') ?>"><?= esc($settlement['status']) ?></span></div>
        <hr class="my-2">
        <?php foreach (['Gross' => 'gross', 'Commission' => 'commission_total', 'Refunds' => 'refund_total', 'TCS' => 'tcs', 'TDS' => 'tds', 'Fees' => 'fees'] as $lbl => $col): ?>
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary"><?= $lbl ?></span><span>₹<?= esc(number_format((float) ($settlement[$col] ?? 0), 2)) ?></span></div>
        <?php endforeach; ?>
        <hr class="my-2">
        <div class="d-flex justify-content-between fw-semibold"><span>Net payable</span><span class="text-primary">₹<?= esc(number_format((float) $settlement['net_payable'], 2)) ?></span></div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
