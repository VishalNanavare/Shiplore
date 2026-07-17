<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<a href="<?= site_url('admin/imports') ?>" class="btn btn-sm btn-light mb-3"><i class="bi bi-arrow-left"></i> Imports</a>

<div class="card mb-3"><div class="card-body d-flex flex-wrap gap-4">
    <div><div class="text-secondary small">Type</div><div class="fw-semibold text-capitalize"><?= esc($job['type']) ?></div></div>
    <div><div class="text-secondary small">Total</div><div class="fw-semibold"><?= esc($job['total_rows']) ?></div></div>
    <div><div class="text-secondary small">Imported</div><div class="fw-semibold text-success"><?= esc($job['processed_rows']) ?></div></div>
    <div><div class="text-secondary small">Errors</div><div class="fw-semibold text-danger"><?= esc($job['error_rows']) ?></div></div>
    <div><div class="text-secondary small">Status</div><span class="badge text-bg-<?= $job['status'] === 'completed' ? 'success' : 'secondary' ?>"><?= esc($job['status']) ?></span></div>
</div></div>

<div class="card"><div class="card-header fw-semibold">Rows</div><div class="table-responsive"><table class="table align-middle mb-0">
    <thead class="table-light"><tr><th style="width:60px">#</th><th>Status</th><th>Errors</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $errs = $r['errors'] ? (json_decode((string) $r['errors'], true) ?: []) : []; ?>
        <tr>
            <td><?= esc($r['row_no']) ?></td>
            <td><span class="badge text-bg-<?= $r['status'] === 'imported' ? 'success' : ($r['status'] === 'invalid' ? 'danger' : 'secondary') ?>"><?= esc($r['status']) ?></span></td>
            <td class="small text-danger"><?= $errs ? esc(implode('; ', $errs)) : '<span class="text-secondary">—</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?><tr><td colspan="3" class="text-center text-secondary py-4">No rows.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= $this->endSection() ?>
