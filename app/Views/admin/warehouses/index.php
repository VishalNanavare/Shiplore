<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Warehouses</span><span class="text-secondary small"><?= count($warehouses) ?> </span><a href="<?= site_url('admin/warehouses/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add</a></div>
    <div class="card-body">
        <?php if (empty($warehouses)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-buildings display-6 d-block mb-2"></i>No warehouses yet.</div>
        <?php else: ?>
        <div class="table-responsive" data-ajax-region><table id="warehousesTable" class="table table-hover align-middle w-100">
            <thead class="table-light"><tr><th>Warehouse</th><th>Code</th><th>Vendor</th><th>Pincode</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($warehouses as $w): ?>
                <tr>
                    <td class="fw-semibold"><?= esc($w['name']) ?></td>
                    <td class="small"><?= esc($w['code']) ?></td>
                    <td><?= esc($w['vendor'] ?? '—') ?></td>
                    <td class="small"><?= esc($w['pincode']) ?></td>
                    <td><span class="badge text-bg-<?= $w['status'] === 'active' ? 'success' : 'secondary' ?>"><?= esc($w['status']) ?></span></td>
                    <td class="text-end">
                        <?php if ($w['status'] !== 'active'): ?>
                            <a href="<?= site_url('admin/warehouses/' . $w['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a> <form method="post" action="<?= site_url('admin/warehouses/' . $w['id'] . '/activate') ?>" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Activate</button></form>
                        <?php else: ?>
                            <form method="post" action="<?= site_url('admin/warehouses/' . $w['id'] . '/deactivate') ?>" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pause"></i> Deactivate</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/datatables/jquery.dataTables.min.js') ?>"></script>
<script src="<?= asset('plugins/datatables/dataTables.bootstrap5.min.js') ?>"></script>
<script>$(function(){ if($.fn.DataTable && document.getElementById('warehousesTable')) $('#warehousesTable').DataTable({ pageLength: 10, columnDefs:[{orderable:false,targets:5}] }); });</script>
<?= $this->endSection() ?>
