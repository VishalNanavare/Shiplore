<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$badge = ['started' => 'info', 'completed' => 'success', 'failed' => 'danger'];
$human = static function ($b): string {
    $b = (int) $b;
    if ($b <= 0) return '—';
    foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) { if ($b < 1024) return round($b, 1) . ' ' . $u; $b /= 1024; }
    return round($b, 1) . ' PB';
};
?>
<div class="alert alert-info d-flex align-items-center"><i class="bi bi-hdd-stack me-2"></i><div class="small">Disaster-recovery run log — scheduled backups, snapshots and restores.</div></div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Backup &amp; Restore Logs</span><span class="text-secondary small"><?= count($backups) ?> total</span></div>
    <div class="card-body">
        <?php if (empty($backups)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-hdd-stack display-6 d-block mb-2"></i>No backup runs recorded yet.</div>
        <?php else: ?>
        <div class="table-responsive"><table id="backupsTable" class="table table-hover align-middle w-100">
            <thead class="table-light"><tr><th>Type</th><th>Scope</th><th>Size</th><th>Location</th><th>Started</th><th>Finished</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($backups as $b): ?>
                <tr>
                    <td class="fw-semibold text-capitalize"><?= esc($b['type']) ?></td>
                    <td class="small"><?= esc($b['scope'] ?? '—') ?></td>
                    <td><?= esc($human($b['size_bytes'])) ?></td>
                    <td class="small text-secondary text-truncate" style="max-width:220px"><?= esc($b['location'] ?? '—') ?></td>
                    <td class="small text-secondary"><?= esc(substr((string) ($b['started_at'] ?? ''), 0, 16) ?: '—') ?></td>
                    <td class="small text-secondary"><?= esc(substr((string) ($b['finished_at'] ?? ''), 0, 16) ?: '—') ?></td>
                    <td><span class="badge text-bg-<?= esc($badge[$b['status']] ?? 'secondary', 'attr') ?>"><?= esc($b['status']) ?></span></td>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('backupsTable')) $('#backupsTable').DataTable({ pageLength: 10, order:[[4,'desc']] }); });</script>
<?= $this->endSection() ?>
