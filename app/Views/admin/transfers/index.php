<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$badge = ['requested' => 'warning', 'approved' => 'primary', 'packed' => 'primary', 'dispatched' => 'info', 'in_transit' => 'info', 'received' => 'success', 'partially_received' => 'info', 'reconciled' => 'success', 'closed' => 'secondary', 'rejected' => 'danger', 'cancelled' => 'danger'];
$ajaxVerbs = ['delete', 'remove', 'toggle', 'activate', 'deactivate', 'enable', 'disable', 'approve', 'reject', 'publish', 'unpublish', 'restore', 'default', 'set-default', 'status', 'resend', 'retry', 'cancel', 'hold', 'release', 'resolve', 'verify', 'bulk', 'suspend', 'reinstate', 'archive', 'pin', 'feature'];
$act = static fn (int $id, string $action, string $label, string $cls): string =>
    '<form method="post" action="' . site_url('admin/transfers/' . $id . '/' . $action) . '" class="d-inline"' . (in_array($action, $ajaxVerbs, true) ? ' data-ajax-refresh' : '') . '>' . csrf_field() . '<button class="btn btn-sm ' . $cls . '">' . $label . '</button></form>';
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Stock Transfers <span class="text-secondary fw-normal small">· all vendors · admin can override any stage</span></span><span class="text-secondary small"><?= count($transfers) ?> total</span></div>
    <div class="card-body">
        <?php if (empty($transfers)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-arrow-left-right display-6 d-block mb-2"></i>No stock transfers yet.</div>
        <?php else: ?>
        <div class="table-responsive" data-ajax-region><table id="transfersTable" class="table table-hover align-middle w-100">
            <thead class="table-light"><tr><th>Transfer</th><th>Vendor</th><th>Product</th><th>From → To</th><th>Qty</th><th>Status</th><th class="text-end">Override</th></tr></thead>
            <tbody>
            <?php foreach ($transfers as $t): $id = (int) $t['id']; ?>
                <tr>
                    <td class="small text-secondary"><?= esc($t['transfer_no'] ?? ('#' . $id)) ?><div><?= esc(substr((string) ($t['created_at'] ?? ''), 0, 10)) ?></div></td>
                    <td class="small"><?= esc($t['vendor'] ?? '—') ?></td>
                    <td class="small"><?= esc($t['title'] ?? '—') ?><div class="text-secondary"><?= esc($t['sku'] ?? '') ?></div></td>
                    <td class="small"><?= esc($t['from_shop'] ?? '—') ?> <i class="bi bi-arrow-right"></i> <?= esc($t['to_shop'] ?? '—') ?></td>
                    <td class="small"><?= esc(rtrim(rtrim((string) $t['qty'], '0'), '.')) ?><?php if ((float) $t['qty_received'] > 0): ?><div class="text-success">recv <?= esc(rtrim(rtrim((string) $t['qty_received'], '0'), '.')) ?></div><?php endif; ?></td>
                    <td><span class="badge text-bg-<?= esc($badge[$t['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $t['status'])) ?></span></td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1 flex-wrap justify-content-end">
                        <?php if ($t['status'] === 'requested'): ?>
                            <?= $act($id, 'approve', 'Approve', 'btn-success') ?><?= $act($id, 'reject', 'Reject', 'btn-outline-danger') ?>
                        <?php elseif ($t['status'] === 'approved'): ?>
                            <?= $act($id, 'pack', 'Pack', 'btn-outline-primary') ?><?= $act($id, 'dispatch', 'Dispatch', 'btn-primary') ?><?= $act($id, 'cancel', 'Cancel', 'btn-outline-secondary') ?>
                        <?php elseif ($t['status'] === 'packed'): ?>
                            <?= $act($id, 'dispatch', 'Dispatch', 'btn-primary') ?><?= $act($id, 'cancel', 'Cancel', 'btn-outline-secondary') ?>
                        <?php elseif (in_array($t['status'], ['dispatched', 'in_transit', 'partially_received'], true)): ?>
                            <form method="post" action="<?= site_url('admin/transfers/' . $id . '/receive') ?>" class="input-group input-group-sm" style="max-width:160px"><?= csrf_field() ?><input name="qty_received" type="number" step="0.001" class="form-control" value="<?= esc(rtrim(rtrim((string) $t['qty_dispatched'], '0'), '.'), 'attr') ?>"><button class="btn btn-success">Receive</button></form>
                            <?php if ($t['status'] === 'partially_received'): ?><?= $act($id, 'close', 'Close', 'btn-outline-secondary') ?><?php endif; ?>
                        <?php elseif ($t['status'] === 'received'): ?>
                            <?= $act($id, 'close', 'Close', 'btn-outline-secondary') ?>
                        <?php else: ?><span class="text-secondary small">—</span><?php endif; ?>
                        </div>
                    </td>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('transfersTable')) $('#transfersTable').DataTable({ pageLength: 10, columnDefs:[{orderable:false,targets:6}] }); });</script>
<?= $this->endSection() ?>
