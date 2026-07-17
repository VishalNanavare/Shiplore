<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$statusBadge   = ['open' => 'primary', 'pending' => 'warning', 'resolved' => 'info', 'closed' => 'secondary'];
$priorityBadge = ['low' => 'secondary', 'medium' => 'info', 'high' => 'warning', 'urgent' => 'danger'];
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Support Tickets</span><span class="text-secondary small"><?= count($tickets) ?> total</span></div>
    <div class="card-body">
        <?php if (empty($tickets)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-life-preserver display-6 d-block mb-2"></i>No support tickets yet.</div>
        <?php else: ?>
        <div class="table-responsive"><table id="supportTable" class="table table-hover align-middle w-100">
            <thead class="table-light"><tr><th>Ticket</th><th>Subject</th><th>Requester</th><th>Priority</th><th>Status</th><th>Opened</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($tickets as $t): ?>
                <tr>
                    <td class="fw-semibold small"><?= esc($t['ticket_no']) ?></td>
                    <td><?= esc($t['subject']) ?></td>
                    <td><?= esc($t['requester'] ?? '—') ?><?php if (!empty($t['vendor'])): ?> <span class="text-secondary small">· <?= esc($t['vendor']) ?></span><?php endif; ?></td>
                    <td><span class="badge text-bg-<?= esc($priorityBadge[$t['priority']] ?? 'secondary', 'attr') ?>"><?= esc($t['priority']) ?></span></td>
                    <td><span class="badge text-bg-<?= esc($statusBadge[$t['status']] ?? 'secondary', 'attr') ?>"><?= esc($t['status']) ?></span></td>
                    <td class="text-secondary small"><?= esc(substr((string) ($t['created_at'] ?? ''), 0, 10)) ?></td>
                    <td class="text-end"><a href="<?= site_url('admin/support/' . $t['id']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> Open</a></td>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('supportTable')) $('#supportTable').DataTable({ pageLength: 10, ordering:false, columnDefs:[{orderable:false,targets:6}] }); });</script>
<?= $this->endSection() ?>
