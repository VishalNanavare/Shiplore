<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $badge = ['created' => 'secondary', 'queued' => 'info', 'sent' => 'primary', 'read' => 'success', 'failed' => 'danger']; ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Notifications</span><span class="text-secondary small"><?= count($notifications) ?> recent</span></div>
    <div class="card-body">
        <?php if (empty($notifications)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-bell display-6 d-block mb-2"></i>No notifications yet.</div>
        <?php else: ?>
        <div class="table-responsive"><table id="notificationsTable" class="table table-hover align-middle w-100">
            <thead class="table-light"><tr><th>Title</th><th>Event</th><th>Category</th><th>Recipient</th><th>Status</th><th>Sent</th></tr></thead>
            <tbody>
            <?php foreach ($notifications as $n): ?>
                <tr>
                    <td class="fw-semibold"><?= esc($n['title'] ?? '—') ?></td>
                    <td class="small text-secondary"><?= esc($n['event_code'] ?? '—') ?></td>
                    <td><span class="badge bg-light text-secondary border text-capitalize"><?= esc($n['category'] ?? '—') ?></span></td>
                    <td><?= esc($n['recipient'] ?? '—') ?></td>
                    <td><span class="badge text-bg-<?= esc($badge[$n['status']] ?? 'secondary', 'attr') ?>"><?= esc($n['status']) ?></span></td>
                    <td class="text-secondary small"><?= esc(substr((string) ($n['created_at'] ?? ''), 0, 16)) ?></td>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('notificationsTable')) $('#notificationsTable').DataTable({ pageLength: 10, order:[[5,'desc']] }); });</script>
<?= $this->endSection() ?>
