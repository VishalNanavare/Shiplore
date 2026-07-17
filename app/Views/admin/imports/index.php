<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $badge = ['uploaded' => 'secondary', 'validating' => 'info', 'dry_run' => 'info', 'processing' => 'warning', 'completed' => 'success', 'failed' => 'danger']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card mb-4"><div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-upload me-1"></i>Upload CSV / XLSX</h2>
    <form method="post" action="<?= site_url('admin/imports/upload') ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <div class="col-md-3"><label class="form-label small">Type</label>
            <select name="type" class="form-select"><?php foreach ($types as $t): ?><option value="<?= esc($t, 'attr') ?>"><?= esc(ucfirst($t)) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-5"><label class="form-label small">File</label><input type="file" name="file" class="form-control" accept=".csv,.xlsx" required></div>
        <div class="col-md-4">
            <button class="btn btn-primary"><i class="bi bi-cloud-upload me-1"></i>Import</button>
        </div>
    </form>
    <div class="mt-3">
        <span class="small fw-semibold me-1"><i class="bi bi-file-earmark-excel text-success me-1"></i>Excel templates:</span>
        <?php foreach ($types as $t): ?>
            <a href="<?= site_url('admin/imports/template/' . $t) ?>" class="btn btn-sm btn-outline-success me-1"><i class="bi bi-download me-1"></i><?= esc(ucfirst($t)) ?> (.xlsx)</a>
        <?php endforeach; ?>
    </div>
    <div class="form-text mt-2">
        Each template is a real Excel workbook with an <strong>Instructions</strong> sheet documenting every column,
        which are required, and the exact reference codes to use (tax classes, units, category / vendor / brand slugs).
        The product template carries the full model — vendor, category, brand, HSN, title, sku, barcode, product type,
        condition, GST type, tax, unit, MRP, price, manufacturer, country of origin, short &amp; full description.
        Category must be allowed for the vendor's business type. Fill it in and upload it back here (CSV is also accepted).
    </div>
</div></div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Bulk Import Jobs</span><span class="text-secondary small"><?= count($jobs) ?> total</span></div>
    <div class="card-body">
        <?php if (empty($jobs)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-upload display-6 d-block mb-2"></i>No import jobs yet.</div>
        <?php else: ?>
        <div class="table-responsive"><table id="importsTable" class="table table-hover align-middle w-100">
            <thead class="table-light"><tr><th>Type</th><th>Requested by</th><th>Progress</th><th>Errors</th><th>Status</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $j): $tot = (int) $j['total_rows']; $done = (int) $j['processed_rows']; $pct = $tot > 0 ? round($done / $tot * 100) : 0; ?>
                <tr>
                    <td class="fw-semibold text-capitalize"><a href="<?= site_url('admin/imports/' . $j['id']) ?>" class="text-reset"><?= esc(str_replace('_', ' ', $j['type'])) ?></a></td>
                    <td><?= esc($j['requested_by'] ?? '—') ?></td>
                    <td style="min-width:160px"><div class="d-flex justify-content-between small"><span><?= $done ?>/<?= $tot ?></span><span class="text-secondary"><?= $pct ?>%</span></div><div class="progress" style="height:5px"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div></td>
                    <td><?= (int) $j['error_rows'] > 0 ? '<span class="badge bg-danger-subtle text-danger">' . esc($j['error_rows']) . '</span>' : '<span class="text-secondary small">0</span>' ?></td>
                    <td><span class="badge text-bg-<?= esc($badge[$j['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $j['status'])) ?></span></td>
                    <td class="small text-secondary"><?= esc(substr((string) ($j['created_at'] ?? ''), 0, 16)) ?></td>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('importsTable')) $('#importsTable').DataTable({ pageLength: 10, order:[[5,'desc']] }); });</script>
<?= $this->endSection() ?>
