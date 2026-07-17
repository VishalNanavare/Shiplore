<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $sb = ['created' => 'secondary', 'pending' => 'warning', 'processing' => 'info', 'completed' => 'success', 'failed' => 'danger']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="mb-3"><a href="<?= site_url('admin/refunds') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back to refunds</a></div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card"><div class="card-header fw-semibold">Refund #<?= (int) $refund['id'] ?></div><div class="card-body">
            <dl class="row mb-0 small">
                <dt class="col-4 text-secondary fw-normal">Status</dt><dd class="col-8"><span class="badge text-bg-<?= esc($sb[$refund['status']] ?? 'secondary', 'attr') ?>"><?= esc($refund['status']) ?></span></dd>
                <dt class="col-4 text-secondary fw-normal">Amount</dt><dd class="col-8 fw-semibold">₹<?= esc(number_format((float) $refund['amount'], 2)) ?></dd>
                <dt class="col-4 text-secondary fw-normal">Order</dt><dd class="col-8"><?php if (! empty($refund['order_id'])): ?><a href="<?= site_url('admin/orders/' . (int) $refund['order_id']) ?>"><?= esc($refund['order_no'] ?? ('#' . $refund['order_id'])) ?></a><?php else: ?><?= esc($refund['order_no'] ?? '—') ?><?php endif; ?></dd>
                <dt class="col-4 text-secondary fw-normal">Payment method</dt><dd class="col-8 text-uppercase"><?= esc($refund['method'] ?? '—') ?> <?= ! empty($refund['pay_gateway']) ? '· ' . esc($refund['pay_gateway']) : '' ?></dd>
                <dt class="col-4 text-secondary fw-normal">Destination</dt><dd class="col-8 text-capitalize"><?= esc($refund['destination'] ?? '—') ?></dd>
                <dt class="col-4 text-secondary fw-normal">Gateway ref</dt><dd class="col-8"><code><?= esc($refund['gateway_ref'] ?: '—') ?></code></dd>
                <dt class="col-4 text-secondary fw-normal">Created</dt><dd class="col-8"><?= esc($refund['created_at'] ?? '') ?></dd>
            </dl>
        </div></div>
    </div>
    <div class="col-lg-5">
        <div class="card"><div class="card-header fw-semibold">Actions</div><div class="card-body">
            <?php if (in_array($refund['status'], ['completed', 'failed'], true)): ?>
                <p class="text-secondary small mb-0">This refund is in a final state.</p>
            <?php else: ?>
                <form method="post" action="<?= site_url('admin/refunds/' . $refund['id'] . '/process') ?>" class="mb-2" data-confirm="Process this refund? This issues a credit note and posts ledger entries.">
                    <?= csrf_field() ?><button class="btn btn-success w-100"><i class="bi bi-check2-circle me-1"></i>Process refund</button>
                </form>
                <form method="post" action="<?= site_url('admin/refunds/' . $refund['id'] . '/fail') ?>" data-confirm="Mark this refund as failed?">
                    <?= csrf_field() ?><button class="btn btn-outline-danger w-100"><i class="bi bi-x-circle me-1"></i>Mark failed</button>
                </form>
            <?php endif; ?>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
