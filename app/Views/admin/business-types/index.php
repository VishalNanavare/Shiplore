<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="alert alert-info d-flex align-items-center"><i class="bi bi-shop me-2"></i><div class="small">A vendor registers under one business type, which gates the categories it can use and its default commission.</div></div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Business Types</span><span class="text-secondary small"><?= count($types) ?> </span><a href="<?= site_url('admin/business-types/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add</a></div>
    <div class="card-body">
        <?php if (empty($types)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-shop display-6 d-block mb-2"></i>No business types yet.</div>
        <?php else: ?>
        <div class="table-responsive" data-ajax-region>
            <table id="businessTypesTable" class="table table-hover align-middle w-100">
                <thead class="table-light"><tr><th>Code</th><th>Name</th><th>Categories</th><th>Default Commission</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($types as $t): ?>
                    <tr>
                        <td class="fw-semibold text-uppercase"><?= esc($t['code']) ?></td>
                        <td><?= esc($t['name']) ?></td>
                        <td><span class="badge bg-light text-secondary border"><?= esc($t['category_count']) ?> categories</span></td>
                        <td><?= $t['default_commission_rate'] !== null ? esc($t['default_commission_rate']) . '%' : '—' ?></td>
                        <td><span class="badge text-bg-<?= $t['status'] === 'active' ? 'success' : 'secondary' ?>"><?= esc($t['status']) ?></span></td>
                        <td class="text-end">
                            <a href="<?= site_url('admin/business-types/' . $t['id'] . '/edit') ?>" class="btn btn-sm btn-outline-primary" title="Edit categories & settings"><i class="bi bi-pencil"></i></a>
                            <?php if ($t['status'] !== 'active'): ?>
                                <form method="post" action="<?= site_url('admin/business-types/' . $t['id'] . '/activate') ?>" data-ajax-refresh class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Activate</button></form>
                            <?php else: ?>
                                <form method="post" action="<?= site_url('admin/business-types/' . $t['id'] . '/deactivate') ?>" data-ajax-refresh class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pause"></i> Deactivate</button></form>
                            <?php endif; ?>
                        </td>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('businessTypesTable')) $('#businessTypesTable').DataTable({ pageLength: 10, columnDefs:[{orderable:false,targets:5}] }); });</script>
<?= $this->endSection() ?>
