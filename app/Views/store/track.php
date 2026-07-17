<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<?php $badge = ['created' => 'info', 'confirmed' => 'primary', 'completed' => 'success', 'cancelled' => 'danger', 'pending' => 'secondary', 'delivered' => 'success']; ?>
<div class="row justify-content-center"><div class="col-lg-7">
    <div class="card mb-3"><div class="card-body">
        <h1 class="h5 mb-3"><i class="bi bi-truck me-1 text-primary"></i>Track your order</h1>
        <form method="get" action="<?= site_url('store/track') ?>" class="input-group">
            <input name="order_no" class="form-control" placeholder="Enter order number e.g. ORD-2026-1001" value="<?= esc($orderNo, 'attr') ?>">
            <button class="btn btn-primary">Track</button>
        </form>
    </div></div>

    <?php if ($orderNo !== '' && ! $order): ?>
        <div class="st-empty"><i class="bi bi-search"></i>No order found for "<?= esc($orderNo) ?>".</div>
    <?php elseif ($order): ?>
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between mb-2"><span class="fw-semibold"><?= esc($order['order_no']) ?></span><span class="badge text-bg-<?= esc($badge[$order['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $order['status'])) ?></span></div>
            <div class="text-secondary small mb-3">Placed <?= esc(substr((string) ($order['placed_at'] ?? $order['created_at'] ?? ''), 0, 16)) ?> · ₹<?= esc(number_format((float) $order['grand_total'], 2)) ?> · <?= esc($order['payment_status']) ?></div>
            <h2 class="h6">Shipments</h2>
            <?php foreach (($order['sub_orders'] ?? []) as $so): ?>
                <div class="d-flex justify-content-between border-bottom py-2"><span class="small"><?= esc($so['vendor'] ?? '') ?> · <?= esc($so['sub_order_no']) ?></span><span class="badge text-bg-<?= esc($badge[$so['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $so['status'])) ?></span></div>
            <?php endforeach; ?>
        </div></div>
    <?php endif; ?>
</div></div>
<?= $this->endSection() ?>
