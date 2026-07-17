<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Customers</span><span class="text-secondary small"><?= count($customers) ?> total</span></div>
    <div class="card-body">
        <?php if (empty($customers)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-people display-6 d-block mb-2"></i>No customers yet.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table id="customersTable" class="table table-hover align-middle w-100">
                <thead class="table-light"><tr><th>Customer</th><th>Email</th><th>Phone</th><th>Lifetime Value</th><th>Status</th><th>Joined</th><th class="text-end"></th></tr></thead>
                <tbody>
                <?php foreach ($customers as $c): ?>
                    <tr>
                        <td class="fw-semibold"><?= esc($c['name'] ?? '—') ?></td>
                        <td class="small"><?= esc($c['email'] ?? '—') ?></td>
                        <td class="small"><?= esc($c['phone'] ?? '—') ?></td>
                        <td>₹<?= esc(number_format((float) $c['lifetime_value'], 2)) ?></td>
                        <td><span class="badge text-bg-<?= $c['status'] === 'active' ? 'success' : 'danger' ?>"><?= esc($c['status']) ?></span></td>
                        <td><span class="text-secondary small"><?= esc(substr((string) ($c['created_at'] ?? ''), 0, 10)) ?></span></td>
                        <td class="text-end"><a href="<?= site_url('admin/customers/' . $c['id']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('customersTable')) $('#customersTable').DataTable({ pageLength: 10, order:[[5,'desc']], columnDefs:[{orderable:false,targets:6}] }); });</script>
<?= $this->endSection() ?>
