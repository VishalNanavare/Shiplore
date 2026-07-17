<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$orderBadge = ['created' => 'info', 'confirmed' => 'primary', 'partially_fulfilled' => 'warning', 'completed' => 'success', 'cancelled' => 'danger'];
$blocked    = $customer['status'] === 'blocked';
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="mb-3 d-flex justify-content-between align-items-center">
    <a href="<?= site_url('admin/customers') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back to customers</a>
    <?php if ($blocked): ?>
        <form method="post" action="<?= site_url('admin/customers/' . $customer['id'] . '/unblock') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-unlock me-1"></i>Unblock</button></form>
    <?php else: ?>
        <form method="post" action="<?= site_url('admin/customers/' . $customer['id'] . '/block') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-slash-circle me-1"></i>Block</button></form>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-lg-4"><div class="card"><div class="card-body text-center">
        <span class="rounded-circle bg-primary-subtle text-primary d-grid mx-auto mb-2" style="width:72px;height:72px;place-items:center;font-weight:700;font-size:1.6rem"><?= esc(strtoupper(substr((string) ($customer['name'] ?? 'C'), 0, 2))) ?></span>
        <h2 class="h5 mb-0"><?= esc($customer['name'] ?? '—') ?></h2>
        <div class="text-secondary small"><?= esc($customer['email'] ?? '') ?></div>
        <span class="badge text-bg-<?= $blocked ? 'danger' : 'success' ?> mt-2"><?= esc($customer['status']) ?></span>
        <hr>
        <dl class="row mb-0 small text-start">
            <dt class="col-5 text-secondary">Phone</dt><dd class="col-7"><?= esc($customer['phone'] ?? '—') ?></dd>
            <dt class="col-5 text-secondary">Lifetime value</dt><dd class="col-7">₹<?= esc(number_format((float) $customer['lifetime_value'], 2)) ?></dd>
            <dt class="col-5 text-secondary">Joined</dt><dd class="col-7"><?= esc(substr((string) ($customer['created_at'] ?? ''), 0, 10)) ?></dd>
        </dl>
    </div></div></div>
    <div class="col-lg-8"><div class="card"><div class="card-header fw-semibold">Recent orders</div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Order</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr><td class="fw-medium"><a href="<?= site_url('admin/orders/' . $o['id']) ?>" class="text-reset text-decoration-none"><?= esc($o['order_no']) ?></a></td>
                    <td>₹<?= esc(number_format((float) $o['grand_total'], 2)) ?></td>
                    <td class="small text-capitalize"><?= esc(str_replace('_', ' ', $o['payment_status'])) ?></td>
                    <td><span class="badge text-bg-<?= esc($orderBadge[$o['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $o['status'])) ?></span></td>
                    <td class="text-secondary small"><?= esc(substr((string) ($o['created_at'] ?? ''), 0, 10)) ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($orders)): ?><tr><td colspan="5" class="text-center text-secondary py-4">No orders yet.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div></div>
</div>
<?= $this->endSection() ?>
