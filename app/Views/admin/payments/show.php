<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $badge = ['created' => 'secondary', 'authorized' => 'info', 'captured' => 'success', 'failed' => 'danger', 'refunded' => 'warning', 'partially_refunded' => 'warning']; ?>
<div class="mb-3"><a href="<?= site_url('admin/payments') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back to payments</a></div>

<div class="row g-3">
    <div class="col-lg-7"><div class="card"><div class="card-header fw-semibold">Payment detail</div><div class="card-body">
        <dl class="row mb-0 small">
            <dt class="col-4 text-secondary">Reference</dt><dd class="col-8"><?= esc($payment['gateway_ref'] ?? ('#' . $payment['id'])) ?></dd>
            <dt class="col-4 text-secondary">Order</dt><dd class="col-8"><?= esc($payment['order_no'] ?? '—') ?></dd>
            <dt class="col-4 text-secondary">Method</dt><dd class="col-8 text-uppercase"><?= esc($payment['method']) ?></dd>
            <dt class="col-4 text-secondary">Gateway</dt><dd class="col-8"><?= esc($payment['gateway'] ?? '—') ?></dd>
            <dt class="col-4 text-secondary">Idempotency</dt><dd class="col-8 small text-truncate"><?= esc($payment['idempotency_key'] ?? '—') ?></dd>
            <dt class="col-4 text-secondary">Captured at</dt><dd class="col-8"><?= esc($payment['captured_at'] ?? '—') ?></dd>
        </dl>
    </div></div></div>
    <div class="col-lg-5"><div class="card"><div class="card-body text-center">
        <div class="text-secondary small">Amount</div>
        <div class="display-6 mb-2">₹<?= esc(number_format((float) $payment['amount'], 2)) ?></div>
        <span class="badge text-bg-<?= esc($badge[$payment['status']] ?? 'secondary', 'attr') ?> fs-6"><?= esc(str_replace('_', ' ', $payment['status'])) ?></span>
        <div class="text-secondary small mt-2"><?= esc($payment['currency'] ?? 'INR') ?></div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
