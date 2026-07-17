<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $badge = ['draft' => 'secondary', 'calculated' => 'info', 'approved' => 'primary', 'paid' => 'success', 'held' => 'warning', 'failed' => 'danger']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex justify-content-end mb-2"><a href="<?= site_url('admin/payouts') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-bank me-1"></i>Vendor payouts</a></div>
<div class="card mb-4"><div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-calculator me-1"></i>Run a settlement</h2>
    <form method="post" action="<?= site_url('admin/settlements/run') ?>" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <div class="col-md-4"><label class="form-label small">Vendor</label>
            <select name="vendor_id" class="form-select" required><option value="">Choose…</option>
                <?php foreach (($vendors ?? []) as $v): ?><option value="<?= esc($v['id'], 'attr') ?>"><?= esc($v['display_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3"><label class="form-label small">Period start</label><input type="date" name="period_start" class="form-control" required></div>
        <div class="col-md-3"><label class="form-label small">Period end</label><input type="date" name="period_end" class="form-control" required></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Run</button></div>
    </form>
    <div class="form-text mt-1">Aggregates delivered/completed sub-orders in the period: gross − commission = net payable. Idempotent per vendor+period.</div>
</div></div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Settlements</span><span class="text-secondary small"><?= count($settlements) ?> total</span></div>
    <div class="card-body">
        <?php if (empty($settlements)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-cash-stack display-6 d-block mb-2"></i>No settlements yet.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table id="settlementsTable" class="table table-hover align-middle w-100">
                <thead class="table-light"><tr><th>Vendor</th><th>Period</th><th>Gross</th><th>Commission</th><th>Refunds</th><th>Net Payable</th><th>Status</th><th class="text-end"></th></tr></thead>
                <tbody>
                <?php foreach ($settlements as $s): ?>
                    <tr>
                        <td class="fw-semibold"><?= esc($s['vendor'] ?? '—') ?></td>
                        <td class="small text-secondary"><?= esc($s['period_start']) ?> → <?= esc($s['period_end']) ?></td>
                        <td>₹<?= esc(number_format((float) $s['gross'], 2)) ?></td>
                        <td>₹<?= esc(number_format((float) $s['commission_total'], 2)) ?></td>
                        <td>₹<?= esc(number_format((float) $s['refund_total'], 2)) ?></td>
                        <td class="fw-semibold">₹<?= esc(number_format((float) $s['net_payable'], 2)) ?></td>
                        <td><span class="badge text-bg-<?= esc($badge[$s['status']] ?? 'secondary', 'attr') ?>"><?= esc($s['status']) ?></span></td>
                        <td class="text-end"><a href="<?= site_url('admin/settlements/' . $s['id']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('settlementsTable')) $('#settlementsTable').DataTable({ pageLength: 10, columnDefs:[{orderable:false,targets:7}] }); });</script>
<?= $this->endSection() ?>
