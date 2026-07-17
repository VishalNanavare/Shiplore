<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="alert alert-info d-flex align-items-center"><i class="bi bi-shield-lock me-2"></i><div class="small">Append-only, hash-chained audit trail — every privileged action is recorded.</div></div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Audit Logs</span><span class="text-secondary small"><?= count($logs) ?> recent</span></div>
    <div class="card-body">
        <?php if (empty($logs)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-clipboard-data display-6 d-block mb-2"></i>No audit entries yet.</div>
        <?php else: ?>
        <div class="table-responsive"><table id="auditTable" class="table table-hover align-middle w-100">
            <thead class="table-light"><tr><th>Action</th><th>Entity</th><th>Actor</th><th>Type</th><th>Result</th><th>IP</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td class="fw-semibold small"><?= esc($l['action']) ?></td>
                    <td class="small"><?= esc($l['entity_type'] ?? '—') ?><?= $l['entity_id'] ? ' #' . esc($l['entity_id']) : '' ?></td>
                    <td><?= esc($l['actor'] ?? 'system') ?></td>
                    <td class="small text-capitalize"><?= esc($l['actor_principal_type'] ?? '—') ?></td>
                    <td><span class="badge text-bg-<?= ($l['result'] ?? '') === 'success' ? 'success' : 'secondary' ?>"><?= esc($l['result'] ?? '—') ?></span></td>
                    <td class="small text-secondary"><?= esc($l['ip'] ?? '—') ?></td>
                    <td class="small text-secondary"><?= esc(substr((string) ($l['created_at'] ?? ''), 0, 16)) ?></td>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('auditTable')) $('#auditTable').DataTable({ pageLength: 15, order:[[6,'desc']] }); });</script>
<?= $this->endSection() ?>
