<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $badge = ['draft' => 'secondary', 'scheduled' => 'info', 'active' => 'success', 'paused' => 'warning', 'expired' => 'danger']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Promotions</span><a href="<?= site_url('admin/promotions/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Promotion</a></div>
    <div class="card-body">
        <?php if (empty($promotions)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-megaphone display-6 d-block mb-2"></i>No promotions yet.</div>
        <?php else: ?>
        <div class="table-responsive" data-ajax-region><table id="promotionsTable" class="table table-hover align-middle w-100">
            <thead class="table-light"><tr><th>Name</th><th>Vendor</th><th>Type</th><th>Value</th><th>Funded by</th><th>Validity</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($promotions as $p): ?>
                <tr>
                    <td class="fw-semibold"><?= esc($p['name']) ?></td>
                    <td><?= esc($p['vendor'] ?? 'Platform') ?></td>
                    <td class="text-capitalize small"><?= esc(str_replace('_', ' ', $p['type'])) ?></td>
                    <td><?= esc($p['value']) ?></td>
                    <td class="small text-capitalize"><?= esc($p['funded_by'] ?? '—') ?></td>
                    <td class="small text-secondary"><?= esc($p['valid_from']) ?><?= $p['valid_to'] ? ' → ' . esc($p['valid_to']) : '' ?></td>
                    <td><span class="badge text-bg-<?= esc($badge[$p['status']] ?? 'secondary', 'attr') ?>"><?= esc($p['status']) ?></span></td>
                    <td class="text-end">
                        <?php if ($p['status'] !== 'active'): ?>
                            <form method="post" action="<?= site_url('admin/promotions/' . $p['id'] . '/activate') ?>" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-play"></i> Activate</button></form>
                        <?php else: ?>
                            <form method="post" action="<?= site_url('admin/promotions/' . $p['id'] . '/pause') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-warning"><i class="bi bi-pause"></i> Pause</button></form>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('promotionsTable')) $('#promotionsTable').DataTable({ pageLength: 10, columnDefs:[{orderable:false,targets:7}] }); });</script>
<?= $this->endSection() ?>
