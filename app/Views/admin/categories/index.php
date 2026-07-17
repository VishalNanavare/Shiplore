<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="alert alert-info d-flex align-items-center"><i class="bi bi-diagram-3 me-2"></i><div class="small">Categories are admin-controlled and mapped to business types — a vendor only sees categories for its business type.</div></div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Categories</span><span class="text-secondary small"><?= count($categories) ?> </span><a href="<?= site_url('admin/categories/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add</a></div>
    <div class="card-body">
        <?php if (empty($categories)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-diagram-3 display-6 d-block mb-2"></i>No categories yet.</div>
        <?php else: ?>
        <div class="table-responsive" data-ajax-region>
            <table id="categoriesTable" class="table table-hover align-middle w-100">
                <thead class="table-light"><tr><th>Category</th><th>Parent</th><th>Business Types</th><th>Commission</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($categories as $c): ?>
                    <tr>
                        <td><span style="padding-left:<?= (int) $c['level'] * 18 ?>px"><?php if ($c['level'] > 0): ?><i class="bi bi-arrow-return-right text-secondary me-1"></i><?php endif; ?><span class="fw-semibold"><?= esc($c['name']) ?></span></span><div class="text-secondary small" style="padding-left:<?= (int) $c['level'] * 18 ?>px"><?= esc($c['slug']) ?></div></td>
                        <td><?= esc($c['parent'] ?? '—') ?></td>
                        <td>
                            <?php foreach (array_filter(array_map('trim', explode(',', (string) ($c['business_types'] ?? '')))) as $bt): ?>
                                <span class="badge bg-primary-subtle text-primary me-1"><?= esc($bt) ?></span>
                            <?php endforeach; ?>
                            <?php if (empty($c['business_types'])): ?><span class="text-secondary small">—</span><?php endif; ?>
                        </td>
                        <td><?= $c['default_commission_rate'] !== null ? esc($c['default_commission_rate']) . '%' : '—' ?></td>
                        <td><span class="badge text-bg-<?= $c['status'] === 'active' ? 'success' : 'secondary' ?>"><?= esc($c['status']) ?></span></td>
                        <td class="text-end">
                            <?php if ($c['status'] !== 'active'): ?>
                                <a href="<?= site_url('admin/categories/' . $c['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a> <form method="post" action="<?= site_url('admin/categories/' . $c['id'] . '/activate') ?>" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Activate</button></form>
                            <?php else: ?>
                                <form method="post" action="<?= site_url('admin/categories/' . $c['id'] . '/deactivate') ?>" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pause"></i> Deactivate</button></form>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('categoriesTable')) $('#categoriesTable').DataTable({ pageLength: 15, ordering: false, columnDefs:[{orderable:false,targets:5}] }); });</script>
<?= $this->endSection() ?>
