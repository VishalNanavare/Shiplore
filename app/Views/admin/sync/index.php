<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<?php
$kpi = [
    ['Queued', $stats['queued'] ?? 0, 'hourglass-split', 'primary'],
    ['Processing', $stats['processing'] ?? 0, 'arrow-repeat', 'info'],
    ['Done', $stats['done'] ?? 0, 'check2-circle', 'success'],
    ['Failed', $stats['failed'] ?? 0, 'x-octagon', 'warning'],
    ['Dead-letter', $stats['dead_letter'] ?? 0, 'envelope-exclamation', 'danger'],
    ['Open conflicts', $health['conflicts_open'] ?? 0, 'shuffle', 'danger'],
];
?>
<div class="row g-3 mb-4">
    <?php foreach ($kpi as [$label, $val, $icon, $color]): ?>
        <div class="col-6 col-md-2"><div class="card h-100"><div class="card-body text-center py-3">
            <i class="bi bi-<?= $icon ?> fs-4 text-<?= $color ?>"></i>
            <div class="h4 mb-0 mt-1"><?= esc($val) ?></div>
            <div class="text-secondary small"><?= esc($label) ?></div>
        </div></div></div>
    <?php endforeach; ?>
</div>

<div class="card mb-4"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2 class="h6 mb-1">Entities under sync</h2>
            <?php foreach ($entities as $e): ?><span class="badge bg-light text-secondary border me-1 mb-1"><?= esc($e) ?></span><?php endforeach; ?>
        </div>
        <form method="post" action="<?= site_url('admin/sync-health/trigger') ?>" class="d-flex gap-2">
            <?= csrf_field() ?>
            <select name="entity" class="form-select form-select-sm w-auto">
                <option value="all">All entities</option>
                <?php foreach ($entities as $e): ?><option value="<?= esc($e, 'attr') ?>"><?= esc($e) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-primary"><i class="bi bi-play-circle me-1"></i>Trigger sync</button>
        </form>
    </div>
    <div class="text-secondary small mt-2"><i class="bi bi-info-circle me-1"></i>Background worker: <code>php spark sync:work</code> drains the queue with exponential-backoff retry → dead-letter.</div>
</div></div>

<div class="row g-3">
    <div class="col-lg-7"><div class="card h-100"><div class="card-header fw-semibold">Open conflicts</div><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>Entity</th><th>Type</th><th>Client→Server ver</th><th class="text-end">Resolve</th></tr></thead>
        <tbody>
        <?php foreach ($conflicts as $c): ?>
            <tr>
                <td class="small"><span class="fw-medium"><?= esc($c['entity_type']) ?></span><div class="text-secondary text-truncate" style="max-width:160px"><?= esc($c['entity_uuid']) ?></div></td>
                <td><span class="badge bg-warning-subtle text-warning"><?= esc($c['conflict_type']) ?></span></td>
                <td class="small"><?= esc($c['client_version']) ?> → <?= esc($c['server_version']) ?></td>
                <td class="text-end">
                    <form method="post" action="<?= site_url('admin/sync-health/conflict/' . $c['id'] . '/resolve') ?>" class="d-inline-flex gap-1">
                        <?= csrf_field() ?>
                        <button name="resolution" value="server_wins" class="btn btn-sm btn-outline-secondary">Server</button>
                        <button name="resolution" value="client_wins" class="btn btn-sm btn-outline-primary">Client</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($conflicts)): ?><tr><td colspan="4" class="text-center text-secondary py-4">No open conflicts 🎉</td></tr><?php endif; ?>
        </tbody>
    </table></div></div></div>
    <div class="col-lg-5"><div class="card h-100"><div class="card-header fw-semibold">Dead-letter (partial-sync recovery)</div><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>Entity</th><th>Attempts</th><th class="text-end">Action</th></tr></thead>
        <tbody>
        <?php foreach ($deadLetter as $d): ?>
            <tr>
                <td class="small"><span class="fw-medium"><?= esc($d['entity_type']) ?></span><div class="text-secondary text-truncate" style="max-width:160px"><?= esc($d['reason']) ?></div></td>
                <td><?= esc($d['attempts']) ?></td>
                <td class="text-end"><form method="post" action="<?= site_url('admin/sync-health/deadletter/' . $d['id'] . '/requeue') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise"></i> Requeue</button></form></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($deadLetter)): ?><tr><td colspan="3" class="text-center text-secondary py-4">Dead-letter queue is empty.</td></tr><?php endif; ?>
        </tbody>
    </table></div></div></div>
</div>

<div class="card mt-3"><div class="card-header fw-semibold">Last-sync cursors</div><div class="table-responsive"><table class="table align-middle mb-0">
    <thead class="table-light"><tr><th>Terminal</th><th>Entity</th><th>Last anchor</th><th>Last pulled</th></tr></thead>
    <tbody>
    <?php foreach (($health['cursors'] ?? []) as $cur): ?>
        <tr><td>#<?= esc($cur['terminal_id']) ?></td><td><?= esc($cur['entity_type']) ?></td><td class="small"><?= esc($cur['last_anchor']) ?></td><td class="small text-secondary"><?= esc($cur['last_pulled_at']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (empty($health['cursors'])): ?><tr><td colspan="4" class="text-center text-secondary py-4">No sync cursors yet.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= $this->endSection() ?>
