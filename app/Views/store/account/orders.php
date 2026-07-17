<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<?php $badge = ['created' => 'info', 'confirmed' => 'primary', 'partially_fulfilled' => 'warning', 'completed' => 'success', 'cancelled' => 'danger']; ?>
<h1 class="h4 mb-3">My Orders</h1>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
    <thead class="table-light"><tr><th>Order</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
        <tr><td class="fw-medium"><?= esc($o['order_no']) ?></td><td>₹<?= esc(number_format((float) $o['grand_total'], 2)) ?></td>
            <td class="small text-capitalize"><?= esc(str_replace('_', ' ', $o['payment_status'])) ?></td>
            <td><span class="badge text-bg-<?= esc($badge[$o['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $o['status'])) ?></span></td>
            <td class="text-secondary small"><?= esc(substr((string) $o['created_at'], 0, 10)) ?></td>
            <td class="text-end">
                <a href="<?= site_url('store/account/orders/' . rawurlencode((string) $o['order_no'])) ?>" class="btn btn-sm btn-primary">View</a>
                <a href="<?= site_url('store/track/' . rawurlencode((string) $o['order_no'])) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-geo-alt"></i> Track</a>
                <?php foreach (($invoices[$o['order_no']] ?? []) as $inv): ?>
                    <a href="<?= site_url('store/account/invoices/' . $inv['id'] . '/pdf') ?>" class="btn btn-sm btn-outline-secondary" title="<?= esc($inv['invoice_no'], 'attr') ?>"><i class="bi bi-filetype-pdf"></i> Invoice</a>
                <?php endforeach; ?>
                <?php if (in_array($o['status'], ['created', 'confirmed'], true)): ?>
                    <form method="post" action="<?= site_url('store/account/orders/' . $o['order_no'] . '/cancel') ?>" class="d-inline" data-confirm="Cancel this order?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Cancel</button></form>
                <?php endif; ?>
            </td></tr>
    <?php endforeach; ?>
    <?php if (empty($orders)): ?><tr><td colspan="6" class="text-center text-secondary py-4">No orders yet.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= $this->endSection() ?>
