<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $sb = ['pending' => 'secondary', 'assigned' => 'info', 'picked_up' => 'info', 'out_for_delivery' => 'warning', 'delivered' => 'success', 'failed' => 'danger', 'returned' => 'dark']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="mb-3"><a href="<?= site_url('admin/deliveries') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back to deliveries</a></div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card"><div class="card-header fw-semibold">Delivery #<?= (int) $delivery['id'] ?></div><div class="card-body">
            <dl class="row mb-0 small">
                <dt class="col-4 text-secondary fw-normal">Status</dt><dd class="col-8"><span class="badge text-bg-<?= esc($sb[$delivery['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $delivery['status'])) ?></span></dd>
                <dt class="col-4 text-secondary fw-normal">Order</dt><dd class="col-8"><?php if (! empty($delivery['order_id'])): ?><a href="<?= site_url('admin/orders/' . (int) $delivery['order_id']) ?>"><?= esc($delivery['order_no'] ?? '') ?></a><?php endif; ?> <span class="text-secondary"><?= esc($delivery['sub_order_no'] ?? '') ?></span></dd>
                <dt class="col-4 text-secondary fw-normal">Shop / Vendor</dt><dd class="col-8"><?= esc($delivery['shop'] ?? '—') ?> · <?= esc($delivery['vendor'] ?? '—') ?></dd>
                <dt class="col-4 text-secondary fw-normal">Mode</dt><dd class="col-8 text-uppercase"><?= esc($delivery['mode'] ?? '—') ?></dd>
                <dt class="col-4 text-secondary fw-normal">Delivery fee</dt><dd class="col-8">₹<?= esc(number_format((float) $delivery['delivery_fee'], 2)) ?></dd>
                <dt class="col-4 text-secondary fw-normal">ETA</dt><dd class="col-8"><?= esc($delivery['eta_at'] ?: '—') ?></dd>
                <dt class="col-4 text-secondary fw-normal">Recipient</dt><dd class="col-8"><?= esc($delivery['recipient_name'] ?: '—') ?> <span class="text-secondary"><?= esc($delivery['recipient_phone'] ?? '') ?></span></dd>
                <dt class="col-4 text-secondary fw-normal">Address</dt><dd class="col-8"><?= esc($delivery['recipient_address'] ?: '—') ?></dd>
                <dt class="col-4 text-secondary fw-normal">Proof of delivery</dt><dd class="col-8"><?= esc($delivery['pod_type'] ?: '—') ?> <?= ! empty($delivery['pod_ref']) ? '· ' . esc($delivery['pod_ref']) : '' ?></dd>
            </dl>
        </div></div>
    </div>
    <div class="col-lg-5">
        <div class="card"><div class="card-header fw-semibold">Update status</div><div class="card-body">
            <?php if (empty($next)): ?>
                <p class="text-secondary small mb-0">This delivery is in a final state — no further changes.</p>
            <?php else: ?>
                <p class="text-secondary small">From <strong><?= esc(str_replace('_', ' ', $delivery['status'])) ?></strong>, move to:</p>
                <div class="d-flex flex-wrap gap-2">
                    <?php $labels = ['assigned' => 'Assign', 'picked_up' => 'Picked up', 'out_for_delivery' => 'Dispatch', 'delivered' => 'Delivered', 'failed' => 'Mark failed', 'returned' => 'Returned']; ?>
                    <?php foreach ($next as $to): ?>
                        <form method="post" action="<?= site_url('admin/deliveries/' . $delivery['id'] . '/status') ?>" data-confirm="Move this delivery to <?= esc(str_replace('_', ' ', $to), 'attr') ?>?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="status" value="<?= esc($to, 'attr') ?>">
                            <button class="btn btn-sm btn-outline-<?= $to === 'failed' ? 'danger' : ($to === 'delivered' ? 'success' : 'primary') ?>"><?= esc($labels[$to] ?? ucfirst(str_replace('_', ' ', $to))) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
