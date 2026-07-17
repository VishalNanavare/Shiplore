<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php $badge = ['accrued' => 'warning', 'settled' => 'success', 'reversed' => 'danger']; ?>
<div class="row g-3 mb-3">
    <div class="col-md-5"><div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
        <span class="rounded d-grid bg-primary-subtle text-primary" style="width:48px;height:48px;place-items:center;font-size:1.3rem"><i class="bi bi-percent"></i></span>
        <div>
            <div class="text-secondary small">Your effective commission rate</div>
            <div class="h4 mb-0"><?= esc(number_format((float) ($effective['rate'] ?? 0), 2)) ?>%</div>
            <span class="badge bg-light text-secondary border text-capitalize">from <?= esc(str_replace('_', ' ', $effective['level'] ?? 'none')) ?></span>
        </div>
    </div></div></div>
    <div class="col-md-7"><div class="alert alert-info h-100 d-flex align-items-center mb-0"><div class="small"><i class="bi bi-info-circle me-1"></i>Resolved live in priority <strong>Product → Subcategory → Category → Business Type → Global</strong>, then deducted per sub-order and reconciled in your settlements.</div></div></div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Commission Ledger</span><span class="text-secondary small"><?= count($ledger) ?> entries</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Sub-order</th><th>Base Amount</th><th>Rate</th><th>Commission</th><th>Status</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($ledger as $c): ?>
            <tr>
                <td class="fw-semibold small"><?= esc($c['sub_order_no'] ?? '—') ?></td>
                <td>₹<?= esc(number_format((float) $c['base_amount'], 2)) ?></td>
                <td><?= esc($c['rate']) ?>%</td>
                <td>₹<?= esc(number_format((float) $c['commission_amount'], 2)) ?></td>
                <td><span class="badge text-bg-<?= esc($badge[$c['status']] ?? 'secondary', 'attr') ?>"><?= esc($c['status']) ?></span></td>
                <td class="text-secondary small"><?= esc(substr((string) ($c['created_at'] ?? ''), 0, 10)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($ledger)): ?><tr><td colspan="6" class="text-center text-secondary py-4">No commission entries yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
