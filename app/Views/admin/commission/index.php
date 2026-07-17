<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="alert alert-info d-flex align-items-center"><i class="bi bi-info-circle me-2"></i><div class="small">Commission resolves in priority: <strong>Product → Subcategory → Category → Business Type → Global default</strong>.</div></div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Commission Plans</span><span class="text-secondary small"><?= count($plans) ?> total</span></div>
    <div class="card-body">
        <?php if (empty($plans)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-percent display-6 d-block mb-2"></i>No commission plans yet.</div>
        <?php else: ?>
        <div class="table-responsive" data-ajax-region>
            <table id="commissionTable" class="table table-hover align-middle w-100">
                <thead class="table-light"><tr><th>Code</th><th>Name</th><th>Type</th><th>Rate</th><th>Base</th><th>Validity</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($plans as $p): ?>
                    <tr>
                        <td class="fw-semibold"><?= esc($p['code']) ?></td>
                        <td><?= esc($p['name']) ?></td>
                        <td><span class="badge bg-light text-secondary border text-capitalize"><?= esc(str_replace('_', ' ', $p['type'])) ?></span></td>
                        <td><?= $p['default_rate'] !== null ? esc($p['default_rate']) . '%' : '—' ?></td>
                        <td class="small text-capitalize"><?= esc(str_replace('_', '-', $p['base'])) ?></td>
                        <td class="small text-secondary"><?= esc($p['valid_from']) ?><?= $p['valid_to'] ? ' → ' . esc($p['valid_to']) : '' ?></td>
                        <td><span class="badge text-bg-<?= $p['status'] === 'active' ? 'success' : 'secondary' ?>"><?= esc($p['status']) ?></span></td>
                        <td class="text-end">
                            <?php if ($p['status'] !== 'active'): ?>
                                <form method="post" action="<?= site_url('admin/commission/' . $p['id'] . '/activate') ?>" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Activate</button></form>
                            <?php else: ?>
                                <form method="post" action="<?= site_url('admin/commission/' . $p['id'] . '/deactivate') ?>" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pause"></i> Deactivate</button></form>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('commissionTable')) $('#commissionTable').DataTable({ pageLength: 10, columnDefs:[{orderable:false,targets:7}] }); });</script>
<?= $this->endSection() ?>
