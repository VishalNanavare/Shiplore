<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $badge = ['initiated' => 'secondary', 'processing' => 'info', 'completed' => 'success', 'failed' => 'danger']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Refunds</span><span class="text-secondary small"><?= count($refunds) ?> total</span></div>
    <div class="card-body">
        <?php if (empty($refunds)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-arrow-counterclockwise display-6 d-block mb-2"></i>No refunds yet.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table id="refundsTable" class="table table-hover align-middle w-100">
                <thead class="table-light"><tr><th>#</th><th>Order</th><th>Method</th><th>Destination</th><th>Amount</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($refunds as $r): ?>
                    <tr>
                        <td class="fw-semibold">#<?= esc($r['id']) ?></td>
                        <td><?= esc($r['order_no'] ?? '—') ?></td>
                        <td class="text-uppercase small"><?= esc($r['method'] ?? '—') ?></td>
                        <td class="text-capitalize"><?= esc($r['destination']) ?></td>
                        <td>₹<?= esc(number_format((float) $r['amount'], 2)) ?></td>
                        <td><span class="badge text-bg-<?= esc($badge[$r['status']] ?? 'secondary', 'attr') ?>"><?= esc($r['status']) ?></span></td>
                        <td class="text-end">
                            <a href="<?= site_url('admin/refunds/' . $r['id']) ?>" class="btn btn-sm btn-outline-secondary" title="View"><i class="bi bi-eye"></i></a>
                            <?php if (in_array($r['status'], ['initiated', 'processing'], true)): ?>
                                <form method="post" action="<?= site_url('admin/refunds/' . $r['id'] . '/process') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Complete</button></form>
                                <form method="post" action="<?= site_url('admin/refunds/' . $r['id'] . '/fail') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> Fail</button></form>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('refundsTable')) $('#refundsTable').DataTable({ pageLength: 10, columnDefs:[{orderable:false,targets:6}] }); });</script>
<?= $this->endSection() ?>
