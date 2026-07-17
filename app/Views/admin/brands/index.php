<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $badge = ['active' => 'success', 'pending' => 'warning', 'inactive' => 'secondary']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Brands</span><span class="text-secondary small"><?= count($brands) ?> </span><a href="<?= site_url('admin/brands/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add</a></div>
    <div class="card-body">
        <?php if (empty($brands)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-bookmark-star display-6 d-block mb-2"></i>No brands yet.</div>
        <?php else: ?>
        <div class="table-responsive" data-ajax-region><table id="brandsTable" class="table table-hover align-middle w-100">
            <thead class="table-light"><tr><th>Brand</th><th>Slug</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($brands as $b): ?>
                <tr>
                    <td class="fw-semibold"><?= esc($b['name']) ?></td>
                    <td class="text-secondary small"><?= esc($b['slug']) ?></td>
                    <td><span class="badge text-bg-<?= esc($badge[$b['status']] ?? 'secondary', 'attr') ?>"><?= esc($b['status']) ?></span></td>
                    <td class="text-end">
                        <?php if ($b['status'] !== 'active'): ?>
                            <a href="<?= site_url('admin/brands/' . $b['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a> <form method="post" action="<?= site_url('admin/brands/' . $b['id'] . '/approve') ?>" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Approve</button></form>
                        <?php else: ?>
                            <form method="post" action="<?= site_url('admin/brands/' . $b['id'] . '/deactivate') ?>" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pause"></i> Deactivate</button></form>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('brandsTable')) $('#brandsTable').DataTable({ pageLength: 10, columnDefs:[{orderable:false,targets:3}] }); });</script>
<?= $this->endSection() ?>
