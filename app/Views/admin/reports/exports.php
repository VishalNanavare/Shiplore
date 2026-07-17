<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<?php $badge = ['queued' => 'warning', 'processing' => 'info', 'completed' => 'success', 'failed' => 'danger', 'expired' => 'dark']; ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Report Exports (async)</span>
        <form method="post" action="<?= site_url('admin/reports/export-async') ?>"><?= csrf_field() ?>
            <button class="btn btn-sm btn-primary"><i class="bi bi-cloud-arrow-down me-1"></i>Queue sales export (this month)</button>
        </form>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>#</th><th>Type</th><th>Requested by</th><th>Rows</th><th>Status</th><th>Created</th><th class="text-end">Download</th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $j): ?>
            <tr>
                <td class="fw-semibold">#<?= esc($j['id']) ?></td>
                <td class="small text-capitalize"><?= esc($j['type']) ?></td>
                <td class="small"><?= esc($j['requested_by_name'] ?? '—') ?></td>
                <td class="small"><?= (int) $j['row_count'] ?></td>
                <td><span class="badge text-bg-<?= esc($badge[$j['status']] ?? 'secondary', 'attr') ?>"><?= esc($j['status']) ?></span></td>
                <td class="text-secondary small"><?= esc(substr((string) $j['created_at'], 0, 16)) ?></td>
                <td class="text-end">
                    <?php if ($j['status'] === 'completed' && ! empty($j['media_uuid'])): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= site_url('media/' . $j['media_uuid']) ?>"><i class="bi bi-download me-1"></i>CSV</a>
                    <?php else: ?><span class="text-secondary small">—</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($jobs)): ?><tr><td colspan="7" class="text-center text-secondary py-4">No exports yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
