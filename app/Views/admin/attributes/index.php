<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Attributes</span><span class="text-secondary small"><?= count($attributes) ?> </span><a href="<?= site_url('admin/attributes/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add</a></div>
    <div class="card-body">
        <?php if (empty($attributes)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-tags display-6 d-block mb-2"></i>No attributes yet.</div>
        <?php else: ?>
        <div class="table-responsive" data-ajax-region>
            <table id="attributesTable" class="table table-hover align-middle w-100">
                <thead class="table-light"><tr><th>Code</th><th>Name</th><th>Type</th><th>Variant-defining</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($attributes as $a): ?>
                    <tr>
                        <td class="fw-semibold"><?= esc($a['code']) ?></td>
                        <td><?= esc($a['name']) ?></td>
                        <td><span class="badge bg-light text-secondary border text-capitalize"><?= esc($a['type']) ?></span></td>
                        <td><?= $a['is_variant_defining'] ? '<span class="badge bg-info-subtle text-info">Yes</span>' : '<span class="text-secondary small">No</span>' ?></td>
                        <td><span class="badge text-bg-<?= $a['status'] === 'active' ? 'success' : 'secondary' ?>"><?= esc($a['status']) ?></span></td>
                        <td class="text-end">
                            <?php if (in_array($a['type'], ['select', 'multiselect'], true)): ?>
                                <a href="<?= site_url('admin/attributes/' . $a['id'] . '/values') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-list-ul"></i> Manage values</a>
                            <?php endif; ?>
                            <?php if ($a['status'] !== 'active'): ?>
                                <a href="<?= site_url('admin/attributes/' . $a['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a> <form method="post" action="<?= site_url('admin/attributes/' . $a['id'] . '/activate') ?>" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Activate</button></form>
                            <?php else: ?>
                                <form method="post" action="<?= site_url('admin/attributes/' . $a['id'] . '/deactivate') ?>" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pause"></i> Deactivate</button></form>
                            <?php endif; ?>
                            <form method="post" action="<?= site_url('admin/attributes/' . $a['id'] . '/delete') ?>" data-ajax-refresh onsubmit="return confirm('Delete this attribute? This cannot be undone. Blocked automatically if it is still in use by a published product.')" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('attributesTable')) $('#attributesTable').DataTable({ pageLength: 10, columnDefs:[{orderable:false,targets:5}] }); });</script>
<?= $this->endSection() ?>
