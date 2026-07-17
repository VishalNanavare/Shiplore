<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$statusBadge  = ['created' => 'info', 'confirmed' => 'primary', 'partially_fulfilled' => 'warning', 'completed' => 'success', 'cancelled' => 'danger'];
$payBadge     = ['pending' => 'secondary', 'paid' => 'success', 'partially_paid' => 'warning', 'failed' => 'danger', 'refunded' => 'info'];
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold">Orders <span class="text-secondary small fw-normal">(<?= count($orders) ?>)</span></span>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
                <input type="search" name="q" class="form-control form-control-sm" style="width:170px" placeholder="Order no / customer" value="<?= esc($filters['q'] ?? '', 'attr') ?>">
                <select name="status" class="form-select form-select-sm" style="width:auto">
                    <option value="">Any status</option>
                    <?php foreach (['created', 'confirmed', 'partially_fulfilled', 'completed', 'cancelled'] as $s): ?>
                        <option value="<?= $s ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="from" class="form-control form-control-sm" style="width:auto" value="<?= esc($filters['from'] ?? '', 'attr') ?>" title="From date">
                <input type="date" name="to" class="form-control form-control-sm" style="width:auto" value="<?= esc($filters['to'] ?? '', 'attr') ?>" title="To date">
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i></button>
                <?php if (! empty($filters)): ?><a href="<?= site_url('admin/orders') ?>" class="btn btn-sm btn-link">Clear</a><?php endif; ?>
            </form>
            <a href="<?= site_url('admin/orders/export') . (! empty($filters) ? '?' . http_build_query(array_diff_key($filters, ['limit' => 1])) : '') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download me-1"></i>Export</a>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($orders)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-bag display-6 d-block mb-2"></i>No orders yet.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table id="ordersTable" class="table table-hover align-middle w-100">
                <thead class="table-light"><tr><th>Order</th><th>Customer</th><th>Channel</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td class="fw-semibold"><?= esc($o['order_no']) ?></td>
                        <td><?= esc($o['customer'] ?? '—') ?></td>
                        <td><span class="badge bg-light text-secondary border text-uppercase"><?= esc($o['channel']) ?></span></td>
                        <td>₹<?= esc(number_format((float) $o['grand_total'], 2)) ?></td>
                        <td><span class="badge text-bg-<?= esc($payBadge[$o['payment_status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $o['payment_status'])) ?></span></td>
                        <td><span class="badge text-bg-<?= esc($statusBadge[$o['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $o['status'])) ?></span></td>
                        <td><span class="text-secondary small"><?= esc(substr((string) ($o['placed_at'] ?? $o['created_at'] ?? ''), 0, 10)) ?></span></td>
                        <td class="text-end"><a href="<?= site_url('admin/orders/' . $o['id']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/datatables/jquery.dataTables.min.js') ?>"></script>
<script src="<?= asset('plugins/datatables/dataTables.bootstrap5.min.js') ?>"></script>
<script>
$(function () {
    if ($.fn && $.fn.DataTable && document.getElementById('ordersTable')) {
        $('#ordersTable').DataTable({ pageLength: 10, order: [[6, 'desc']], columnDefs: [{ orderable: false, targets: 7 }] });
    }
});
</script>
<?= $this->endSection() ?>
