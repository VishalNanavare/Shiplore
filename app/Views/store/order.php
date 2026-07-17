<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<?php $badge = ['created' => 'info', 'confirmed' => 'primary', 'partially_fulfilled' => 'warning', 'completed' => 'success', 'cancelled' => 'danger', 'pending' => 'secondary', 'delivered' => 'success']; ?>
<div class="row justify-content-center"><div class="col-lg-8">
    <?php if (session('success')): ?>
        <div class="text-center mb-4"><div class="display-5 text-success mb-2"><i class="bi bi-check-circle"></i></div><h1 class="h4">Thank you! Your order is placed.</h1></div>
    <?php endif; ?>
    <?php if (! $order): ?>
        <div class="st-empty"><i class="bi bi-bag-x"></i>Order <?= esc($orderNo) ?> not found.</div>
    <?php else: ?>
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between flex-wrap"><div><div class="text-secondary small">Order</div><div class="h5 mb-0"><?= esc($order['order_no']) ?></div></div>
            <div class="text-end"><div class="text-secondary small">Total</div><div class="h5 mb-0 text-primary">₹<?= esc(number_format((float) $order['grand_total'], 2)) ?></div></div></div>
        <div class="mt-2"><span class="badge text-bg-<?= esc($badge[$order['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $order['status'])) ?></span>
            <span class="badge text-bg-<?= ($order['payment_status'] ?? '') === 'paid' ? 'success' : 'secondary' ?>"><?= esc($order['payment_status']) ?></span></div>
    </div></div>
    <div class="card"><div class="card-header fw-semibold">Shipments</div><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>Sub-order</th><th>Seller</th><th>Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach (($order['sub_orders'] ?? []) as $so): ?>
            <tr><td class="small fw-medium"><?= esc($so['sub_order_no']) ?></td><td><?= esc($so['vendor'] ?? '—') ?></td><td>₹<?= esc(number_format((float) $so['grand_total'], 2)) ?></td>
                <td><span class="badge text-bg-<?= esc($badge[$so['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $so['status'])) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
    <div class="text-center mt-3">
        <a href="<?= site_url('store/track/' . rawurlencode((string) $order['order_no'])) ?>" class="btn btn-primary me-2"><i class="bi bi-geo-alt me-1"></i>Track delivery</a>
        <a href="<?= site_url('store/products') ?>" class="btn btn-outline-primary">Continue shopping</a>
    </div>
    <?php endif; ?>
</div></div>
<?= $this->endSection() ?>
