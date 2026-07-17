<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$sb = ['requested' => 'info', 'approved' => 'primary', 'pickup_scheduled' => 'info', 'picked_up' => 'info', 'qc' => 'warning', 'refund_approved' => 'primary', 'refunded' => 'success', 'restocked' => 'success', 'rejected' => 'danger', 'write_off' => 'dark'];
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="mb-3"><a href="<?= site_url('admin/returns') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back to returns</a></div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card"><div class="card-header fw-semibold">Return #<?= (int) $ret['id'] ?></div><div class="card-body">
            <dl class="row mb-0 small">
                <dt class="col-4 text-secondary fw-normal">Status</dt><dd class="col-8"><span class="badge text-bg-<?= esc($sb[$ret['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $ret['status'])) ?></span></dd>
                <dt class="col-4 text-secondary fw-normal">Order</dt><dd class="col-8"><?php if (! empty($ret['order_id'])): ?><a href="<?= site_url('admin/orders/' . (int) $ret['order_id']) ?>"><?= esc($ret['order_no'] ?? ('#' . $ret['order_id'])) ?></a><?php else: ?><?= esc($ret['order_no'] ?? '—') ?><?php endif; ?> <span class="text-secondary"><?= esc($ret['sub_order_no'] ?? '') ?></span></dd>
                <dt class="col-4 text-secondary fw-normal">Customer</dt><dd class="col-8"><?= esc($ret['customer'] ?? '—') ?> <span class="text-secondary"><?= esc($ret['customer_email'] ?? '') ?></span></dd>
                <dt class="col-4 text-secondary fw-normal">Vendor</dt><dd class="col-8"><?= esc($ret['vendor'] ?? '—') ?></dd>
                <dt class="col-4 text-secondary fw-normal">Reason</dt><dd class="col-8"><?= esc($ret['reason'] ?? '—') ?></dd>
                <dt class="col-4 text-secondary fw-normal">Refund to</dt><dd class="col-8 text-capitalize"><?= esc($ret['refund_to'] ?? '—') ?></dd>
                <dt class="col-4 text-secondary fw-normal">QC result</dt><dd class="col-8"><?= esc($ret['qc_result'] ?: '—') ?></dd>
                <dt class="col-4 text-secondary fw-normal">Channel</dt><dd class="col-8 text-uppercase"><?= esc($ret['channel'] ?? '—') ?></dd>
                <dt class="col-4 text-secondary fw-normal">Created</dt><dd class="col-8"><?= esc($ret['created_at'] ?? '') ?></dd>
            </dl>
        </div></div>
    </div>
    <div class="col-lg-5">
        <div class="card"><div class="card-header fw-semibold">Move return forward</div><div class="card-body">
            <?php if (empty($next)): ?>
                <p class="text-secondary small mb-0">This return is in a final state — no further transitions.</p>
            <?php else: ?>
                <p class="text-secondary small">Allowed next steps from <strong><?= esc(str_replace('_', ' ', $ret['status'])) ?></strong>:</p>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($next as $to): ?>
                        <form method="post" action="<?= site_url('admin/returns/' . $ret['id'] . '/transition') ?>" data-confirm="Move this return to <?= esc(str_replace('_', ' ', $to), 'attr') ?>?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="to" value="<?= esc($to, 'attr') ?>">
                            <input type="hidden" name="return" value="show">
                            <button class="btn btn-sm btn-outline-primary text-capitalize"><?= esc(str_replace('_', ' ', $to)) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
