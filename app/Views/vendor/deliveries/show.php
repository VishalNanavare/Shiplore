<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<?php
$sla  = ['on_time' => 'success', 'at_risk' => 'warning', 'breached' => 'danger', 'unknown' => 'secondary'];
$stat = ['pending' => 'secondary', 'assigned' => 'info', 'picked_up' => 'primary', 'out_for_delivery' => 'primary', 'delivered' => 'success', 'failed' => 'danger', 'returned' => 'dark'];
$canAct = ! in_array($delivery['status'], ['delivered', 'returned'], true);
?>
<a href="<?= site_url('vendor/deliveries') ?>" class="btn btn-sm btn-light mb-3"><i class="bi bi-arrow-left"></i> Deliveries</a>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3"><div class="card-body">
            <div class="d-flex justify-content-between flex-wrap">
                <div>
                    <h1 class="h5 mb-1"><?= esc($delivery['order_no']) ?> <span class="text-secondary small"><?= esc($delivery['sub_order_no']) ?></span></h1>
                    <div class="text-secondary small"><?= esc($delivery['shop']) ?> · mode: <?= esc($delivery['mode']) ?></div>
                </div>
                <div class="text-end">
                    <span class="badge text-bg-<?= $stat[$delivery['status']] ?? 'secondary' ?>"><?= esc(str_replace('_', ' ', $delivery['status'])) ?></span>
                    <span class="badge bg-<?= $sla[$delivery['sla_status']] ?? 'secondary' ?>-subtle text-<?= $sla[$delivery['sla_status']] ?? 'secondary' ?>">SLA: <?= esc(str_replace('_', ' ', $delivery['sla_status'])) ?></span>
                </div>
            </div>
            <hr>
            <dl class="row small mb-0">
                <dt class="col-4 text-secondary">Customer</dt><dd class="col-8"><?= esc($delivery['customer']) ?></dd>
                <dt class="col-4 text-secondary">Contact (masked)</dt><dd class="col-8"><i class="bi bi-telephone me-1"></i><?= esc($delivery['customer_phone_masked']) ?></dd>
                <dt class="col-4 text-secondary">Rider</dt><dd class="col-8"><?= $delivery['rider'] ? esc($delivery['rider']) : '— unassigned —' ?></dd>
                <dt class="col-4 text-secondary">ETA</dt><dd class="col-8"><?= esc($delivery['eta_at'] ?? '—') ?></dd>
                <dt class="col-4 text-secondary">COD to collect</dt><dd class="col-8"><?= (float) $delivery['cod_amount'] > 0 ? '₹' . esc($delivery['cod_amount']) : 'Prepaid' ?></dd>
            </dl>
        </div></div>

        <div class="card"><div class="card-header fw-semibold">Status & route history</div><div class="card-body">
            <?php foreach ($history as $h): ?>
                <div class="d-flex gap-2 border-bottom py-2">
                    <i class="bi bi-circle-fill text-primary mt-1" style="font-size:.5rem"></i>
                    <div class="flex-grow-1"><div class="small"><span class="text-secondary"><?= esc($h['from_status']) ?> →</span> <span class="fw-medium"><?= esc($h['to_status']) ?></span></div>
                        <div class="text-secondary small"><?= esc($h['note']) ?> · <?= esc(substr((string) $h['created_at'], 0, 16)) ?></div></div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($history)): ?><div class="text-secondary small">No history yet.</div><?php endif; ?>
        </div></div>
    </div>

    <div class="col-lg-5">
        <?php if ($canAct): ?>
        <div class="card mb-3"><div class="card-body">
            <h2 class="h6 mb-3">Assign / Reassign</h2>
            <form method="post" action="<?= site_url('vendor/deliveries/' . $delivery['id'] . '/assign') ?>" class="mb-2">
                <?= csrf_field() ?>
                <div class="input-group input-group-sm mb-2">
                    <select name="rider_user_id" class="form-select" required>
                        <option value="">Choose rider…</option>
                        <?php foreach ($riders as $r): ?>
                            <option value="<?= esc($r['user_id'], 'attr') ?>" <?= $r['availability'] !== 'available' ? 'disabled' : '' ?>>
                                <?= esc($r['name']) ?> — <?= esc($r['availability']) ?> (<?= esc($r['active']) ?>/<?= esc($r['max']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="mode" class="form-select" style="max-width:110px"><option value="pool">Rider</option><option value="self">Self</option></select>
                    <button class="btn btn-primary">Assign</button>
                </div>
            </form>
            <form method="post" action="<?= site_url('vendor/deliveries/' . $delivery['id'] . '/auto-assign') ?>">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-magic me-1"></i>Auto-assign nearest available</button>
            </form>
        </div></div>

        <div class="card"><div class="card-body">
            <h2 class="h6 mb-2 text-danger">Mark failed</h2>
            <form method="post" action="<?= site_url('vendor/deliveries/' . $delivery['id'] . '/fail') ?>">
                <?= csrf_field() ?>
                <select name="reason" class="form-select form-select-sm mb-2">
                    <option value="customer_unreachable">Customer unreachable</option>
                    <option value="address_not_found">Address not found</option>
                    <option value="customer_rejected">Customer rejected</option>
                    <option value="cod_not_ready">COD not ready</option>
                    <option value="rescheduled">Rescheduled by customer</option>
                </select>
                <button class="btn btn-sm btn-outline-danger w-100">Mark as failed</button>
            </form>
        </div></div>
        <?php else: ?>
            <div class="alert alert-light border">This delivery is <strong><?= esc($delivery['status']) ?></strong> — no further action.</div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
