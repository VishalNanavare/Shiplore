<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $badge = ['created' => 'secondary', 'authorized' => 'info', 'captured' => 'success', 'failed' => 'danger', 'refunded' => 'warning', 'partially_refunded' => 'warning']; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold">Payments <span class="text-secondary small fw-normal">(<?= count($payments) ?>)</span></span>
        <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
            <input type="search" name="q" class="form-control form-control-sm" style="width:180px" placeholder="Ref / order no" value="<?= esc($fQ ?? '', 'attr') ?>">
            <select name="method" class="form-select form-select-sm" style="width:auto">
                <option value="">Any method</option>
                <?php foreach (['cod', 'razorpay', 'stripe', 'upi', 'card', 'netbanking'] as $mth): ?><option value="<?= $mth ?>" <?= ($fMethod ?? '') === $mth ? 'selected' : '' ?>><?= strtoupper($mth) ?></option><?php endforeach; ?>
            </select>
            <select name="status" class="form-select form-select-sm" style="width:auto">
                <option value="">Any status</option>
                <?php foreach (['created', 'authorized', 'captured', 'failed', 'refunded', 'partially_refunded'] as $st): ?><option value="<?= $st ?>" <?= ($fStatus ?? '') === $st ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $st)) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i></button>
            <?php if (($fQ ?? '') !== '' || ($fMethod ?? '') !== '' || ($fStatus ?? '') !== ''): ?><a href="<?= site_url('admin/payments') ?>" class="btn btn-sm btn-link">Clear</a><?php endif; ?>
        </form>
    </div>
    <div class="card-body">
        <?php if (empty($payments)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-credit-card display-6 d-block mb-2"></i>No payments yet.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table id="paymentsTable" class="table table-hover align-middle w-100">
                <thead class="table-light"><tr><th>Reference</th><th>Order</th><th>Method</th><th>Gateway</th><th>Amount</th><th>Status</th><th>Captured</th><th class="text-end"></th></tr></thead>
                <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td class="fw-semibold small"><?= esc($p['gateway_ref'] ?? ('#' . $p['id'])) ?></td>
                        <td><?= esc($p['order_no'] ?? '—') ?></td>
                        <td class="text-uppercase small"><?= esc($p['method']) ?></td>
                        <td><?= esc($p['gateway'] ?? '—') ?></td>
                        <td>₹<?= esc(number_format((float) $p['amount'], 2)) ?><?php if (! empty($p['refunded']) && (float) $p['refunded'] > 0): ?><br><span class="badge text-bg-warning" title="Refunded">−₹<?= esc(number_format((float) $p['refunded'], 0)) ?> refunded</span><?php endif; ?></td>
                        <td><span class="badge text-bg-<?= esc($badge[$p['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $p['status'])) ?></span></td>
                        <td><span class="text-secondary small"><?= esc(substr((string) ($p['captured_at'] ?? ''), 0, 10) ?: '—') ?></span></td>
                        <td class="text-end"><a href="<?= site_url('admin/payments/' . $p['id']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('paymentsTable')) $('#paymentsTable').DataTable({ pageLength: 10, order: [[6,'desc']], columnDefs:[{orderable:false,targets:7}] }); });</script>
<?= $this->endSection() ?>
