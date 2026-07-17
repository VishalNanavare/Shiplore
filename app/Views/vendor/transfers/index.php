<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php
$badge = ['requested' => 'warning', 'approved' => 'primary', 'packed' => 'primary', 'dispatched' => 'info', 'in_transit' => 'info', 'received' => 'success', 'partially_received' => 'info', 'reconciled' => 'success', 'closed' => 'secondary', 'rejected' => 'danger', 'cancelled' => 'danger'];
$act = static fn (int $id, string $action, string $label, string $cls): string =>
    '<form method="post" action="' . site_url('vendor/transfers/' . $id . '/' . $action) . '" class="d-inline"' . (in_array($action, ['approve', 'reject', 'cancel'], true) ? ' data-ajax-refresh' : '') . '>' . csrf_field() . '<button class="btn btn-sm ' . $cls . '">' . $label . '</button></form>';
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4"><div class="card"><div class="card-body">
        <h2 class="h6 mb-3">New transfer request</h2>
        <?php if (count($shops) < 2): ?>
            <p class="text-secondary small mb-0">You need at least two shops to transfer stock between them.</p>
        <?php else: ?>
        <form method="post" action="<?= site_url('vendor/transfers') ?>">
            <?= csrf_field() ?>
            <div class="mb-2"><label class="form-label small">From shop (source)</label><select name="from_shop_id" class="form-select form-select-sm" required><?php foreach ($shops as $s): ?><option value="<?= esc($s['id'], 'attr') ?>"><?= esc($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="mb-2"><label class="form-label small">To shop (needs stock)</label><select name="to_shop_id" class="form-select form-select-sm" required><?php foreach ($shops as $s): ?><option value="<?= esc($s['id'], 'attr') ?>"><?= esc($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="mb-2"><label class="form-label small">Variant</label><select name="variant_id" class="form-select form-select-sm" required><?php foreach ($variants as $v): ?><option value="<?= esc($v['id'], 'attr') ?>"><?= esc($v['title']) ?> · <?= esc($v['sku']) ?></option><?php endforeach; ?></select></div>
            <div class="mb-2"><label class="form-label small">Quantity</label><input name="qty" type="number" min="1" step="0.001" class="form-control form-control-sm" value="1" required></div>
            <div class="mb-3"><label class="form-label small">Reason (optional)</label><input name="reason" class="form-control form-control-sm" placeholder="e.g. low stock at destination"></div>
            <button class="btn btn-sm btn-primary w-100"><i class="bi bi-arrow-left-right me-1"></i>Request transfer</button>
        </form>
        <?php endif; ?>
        <a href="<?= site_url('vendor/transfers/find') ?>" class="btn btn-outline-primary btn-sm w-100 mt-2"><i class="bi bi-search me-1"></i>Find stock across stores</a>
    </div></div></div>

    <div class="col-lg-8"><div class="card">
        <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">My Transfers</span><span class="text-secondary small"><?= count($transfers) ?></span></div>
        <div class="table-responsive" data-ajax-region><table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Transfer</th><th>Product</th><th>From → To</th><th>Qty</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($transfers as $t): $id = (int) $t['id']; ?>
                <tr>
                    <td class="small text-secondary"><?= esc($t['transfer_no'] ?? ('#' . $id)) ?><div><?= esc(substr((string) ($t['created_at'] ?? ''), 0, 10)) ?></div></td>
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
                            <form method="post" action="<?= site_url('vendor/transfers/' . $id . '/receive') ?>" class="input-group input-group-sm" style="max-width:170px"><?= csrf_field() ?><input name="qty_received" type="number" step="0.001" class="form-control" value="<?= esc(rtrim(rtrim((string) $t['qty_dispatched'], '0'), '.'), 'attr') ?>"><button class="btn btn-success">Receive</button></form>
                            <?php if ($t['status'] === 'partially_received'): ?><?= $act($id, 'close', 'Close', 'btn-outline-secondary') ?><?php endif; ?>
                        <?php elseif ($t['status'] === 'received'): ?>
                            <?= $act($id, 'close', 'Close', 'btn-outline-secondary') ?>
                        <?php else: ?>
                            <span class="text-secondary small">—</span>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($transfers)): ?><tr><td colspan="6" class="text-center text-secondary py-4">No transfers yet.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div></div>
</div>
<?= $this->endSection() ?>
