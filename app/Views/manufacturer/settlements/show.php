<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php $m = static fn ($v): string => '₹' . number_format((float) $v, 2); ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0"><?= esc((string) $s['period_start']) ?> — <?= esc((string) $s['period_end']) ?></h5>
        <span class="text-secondary small">Status: <?= esc((string) $s['status']) ?></span>
    </div>
    <a class="btn btn-sm btn-light" href="<?= site_url('manufacturer/settlements') ?>">
        <i class="bi bi-arrow-left me-1"></i>All settlements
    </a>
</div>

<div class="card mb-3"><div class="card-body">
    <dl class="row mb-0">
        <dt class="col-6 col-md-3 text-secondary">Gross</dt><dd class="col-6 col-md-3"><?= esc($m($s['gross'])) ?></dd>
        <dt class="col-6 col-md-3 text-secondary">Commission</dt><dd class="col-6 col-md-3">−<?= esc($m($s['commission_total'])) ?></dd>
        <dt class="col-6 col-md-3 text-secondary">Refunds</dt><dd class="col-6 col-md-3">−<?= esc($m($s['refund_total'])) ?></dd>
        <dt class="col-6 col-md-3 fw-semibold">Net payable</dt><dd class="col-6 col-md-3 fw-semibold"><?= esc($m($s['net_payable'])) ?></dd>
    </dl>
</div></div>

<div class="card">
    <div class="card-header py-2"><strong>Lines</strong></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead><tr><th>Type</th><th>Reference</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
                <?php foreach (($s['lines'] ?? []) as $l): ?>
                    <tr>
                        <td><?= esc(str_replace('_', ' ', (string) $l['ref_type'])) ?></td>
                        <td class="text-secondary small">
                            <?= ($l['ref_id'] ?? null) ? 'PO #' . (int) $l['ref_id'] : '—' ?>
                        </td>
                        <td class="text-end <?= ($l['direction'] ?? '') === 'debit' ? 'text-secondary' : '' ?>">
                            <?= ($l['direction'] ?? '') === 'debit' ? '−' : '' ?><?= esc($m($l['amount'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
