<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$badge = ['draft' => 'secondary', 'calculated' => 'info', 'approved' => 'primary', 'paid' => 'success', 'held' => 'warning', 'failed' => 'danger'];
$canApprove = in_array($settlement['status'], ['calculated', 'held'], true);
$canPay     = $settlement['status'] === 'approved';
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="mb-3 d-flex justify-content-between align-items-center">
    <a href="<?= site_url('admin/settlements') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back</a>
    <div class="d-flex gap-2">
        <?php if ($canApprove): ?><form method="post" action="<?= site_url('admin/settlements/' . $settlement['id'] . '/approve') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-primary"><i class="bi bi-check2-circle me-1"></i>Approve</button></form><?php endif; ?>
        <?php if ($canPay): ?><form method="post" action="<?= site_url('admin/settlements/' . $settlement['id'] . '/mark-paid') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-cash me-1"></i>Mark paid</button></form><?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8"><div class="card"><div class="card-header fw-semibold">Settlement lines</div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Type</th><th>Ref</th><th>Sub-order</th><th>Direction</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($lines as $l): ?>
                <tr><td class="text-capitalize"><?= esc($l['ref_type']) ?></td><td><?= esc($l['ref_id'] ?? '—') ?></td><td><?= esc($l['sub_order_id'] ?? '—') ?></td>
                    <td><span class="badge bg-<?= $l['direction'] === 'credit' ? 'success' : 'danger' ?>-subtle text-<?= $l['direction'] === 'credit' ? 'success' : 'danger' ?>"><?= esc($l['direction']) ?></span></td>
                    <td class="text-end"><?= $l['direction'] === 'debit' ? '−' : '' ?>₹<?= esc(number_format((float) $l['amount'], 2)) ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($lines)): ?><tr><td colspan="5" class="text-center text-secondary py-3">No lines.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="card mt-3"><div class="card-header fw-semibold"><i class="bi bi-sliders me-1"></i>Adjustments</div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Type</th><th>Reason</th><th>Status</th><th class="text-end">Amount</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($adjustments as $a): ?>
                <tr class="<?= $a['status'] === 'reversed' ? 'text-secondary' : '' ?>">
                    <td class="text-capitalize"><?= esc(str_replace('_', ' ', $a['type'])) ?></td>
                    <td class="small"><?= esc($a['reason']) ?></td>
                    <td><span class="badge text-bg-<?= $a['status'] === 'applied' ? 'success' : 'secondary' ?>"><?= esc($a['status']) ?></span></td>
                    <td class="text-end <?= (float) $a['amount'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (float) $a['amount'] < 0 ? '−' : '+' ?>₹<?= esc(number_format(abs((float) $a['amount']), 2)) ?></td>
                    <td class="text-end"><?php if ($a['status'] === 'applied' && $settlement['status'] !== 'paid'): ?><form method="post" action="<?= site_url('admin/settlements/' . $settlement['id'] . '/adjustments/' . $a['id'] . '/reverse') ?>" data-confirm="Reverse this adjustment?"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0">Reverse</button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($adjustments)): ?><tr><td colspan="5" class="text-center text-secondary py-3">No adjustments yet.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <?php if ($settlement['status'] !== 'paid'): ?>
        <div class="card-body border-top">
            <form method="post" action="<?= site_url('admin/settlements/' . $settlement['id'] . '/adjustments') ?>" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <div class="col-md-3"><label class="form-label small mb-1">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="penalty">Penalty (−)</option><option value="bonus">Bonus (+)</option>
                        <option value="dispute_hold">Dispute hold (−)</option><option value="manual">Manual (±)</option>
                    </select></div>
                <div class="col-md-3"><label class="form-label small mb-1">Amount ₹</label><input name="amount" type="number" step="0.01" class="form-control form-control-sm" required></div>
                <div class="col-md-4"><label class="form-label small mb-1">Reason</label><input name="reason" class="form-control form-control-sm" required></div>
                <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Apply</button></div>
            </form>
        </div>
        <?php endif; ?>
    </div>
    </div>
    <div class="col-lg-4"><div class="card"><div class="card-header fw-semibold">Summary</div><div class="card-body">
        <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Vendor</span><span class="fw-semibold"><?= esc($settlement['vendor'] ?? '—') ?></span></div>
        <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Period</span><span class="small"><?= esc($settlement['period_start']) ?> → <?= esc($settlement['period_end']) ?></span></div>
        <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Status</span><span class="badge text-bg-<?= esc($badge[$settlement['status']] ?? 'secondary', 'attr') ?>"><?= esc($settlement['status']) ?></span></div>
        <hr class="my-2">
        <?php foreach (['Gross' => 'gross', 'Commission' => 'commission_total', 'Refunds' => 'refund_total', 'TCS' => 'tcs', 'TDS' => 'tds', 'Fees' => 'fees', 'Adjustments' => 'adjustments'] as $lbl => $col): ?>
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary"><?= $lbl ?></span><span>₹<?= esc(number_format((float) ($settlement[$col] ?? 0), 2)) ?></span></div>
        <?php endforeach; ?>
        <hr class="my-2">
        <div class="d-flex justify-content-between fw-semibold"><span>Net payable</span><span class="text-primary">₹<?= esc(number_format((float) $settlement['net_payable'], 2)) ?></span></div>
    </div></div>
    <?php if ($settlement['status'] !== 'paid'): ?>
    <div class="card mt-3"><div class="card-header fw-semibold small"><i class="bi bi-percent me-1"></i>TCS / TDS</div><div class="card-body">
        <form method="post" action="<?= site_url('admin/settlements/' . $settlement['id'] . '/apply-taxes') ?>" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-6"><label class="form-label small mb-1">TCS %</label><input name="tcs_rate" type="number" step="0.01" min="0" max="20" value="1" class="form-control form-control-sm"></div>
            <div class="col-6"><label class="form-label small mb-1">TDS %</label><input name="tds_rate" type="number" step="0.01" min="0" max="20" value="1" class="form-control form-control-sm"></div>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Apply on gross &amp; recalc net</button></div>
            <div class="col-12"><div class="form-text">Computed on gross (₹<?= esc(number_format((float) $settlement['gross'], 0)) ?>). Rates are admin-set, not hard-coded tax law.</div></div>
        </form>
    </div></div>
    <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
