<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<a class="small text-decoration-none" href="<?= site_url('manufacturer/purchase-orders') ?>">&larr; All purchase orders</a>

<div class="d-flex justify-content-between align-items-center mt-3 mb-3">
    <h5 class="mb-0"><?= esc($po['po_no']) ?></h5>
    <span class="badge bg-secondary fs-6"><?= esc(str_replace('_', ' ', (string) $po['status'])) ?></span>
</div>

<div class="card mb-3">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead><tr><th>Product</th><th>SKU</th><th class="text-end">Qty</th><th class="text-end">Unit</th><th class="text-end">Line total</th></tr></thead>
            <tbody>
                <?php foreach ($items as $i): ?>
                    <tr>
                        <td><?= esc($i['product_title_snapshot']) ?></td>
                        <td class="small text-secondary"><?= esc((string) ($i['sku_snapshot'] ?? '')) ?></td>
                        <td class="text-end"><?= esc(rtrim(rtrim(number_format((float) $i['qty'], 3), '0'), '.')) ?></td>
                        <td class="text-end">₹<?= esc(number_format((float) $i['unit_price'], 2)) ?></td>
                        <td class="text-end">₹<?= esc(number_format((float) $i['line_total'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="border-top">
                <tr class="fw-semibold"><td colspan="4" class="text-end">Grand total</td><td class="text-end">₹<?= esc(number_format((float) $po['grand_total'], 2)) ?></td></tr>
            </tfoot>
        </table>
    </div>
</div>

<?php
// Only the actions legal from the current status are offered. The state machine
// refuses anything else server-side regardless of what the page shows.
$status = (string) $po['status'];
?>
<div class="card">
    <div class="card-body d-flex flex-wrap gap-2 align-items-end">
        <?php if ($status === 'placed'): ?>
            <form method="post" action="<?= site_url('manufacturer/purchase-orders/' . (int) $po['id'] . '/accept') ?>" class="d-flex gap-2 align-items-end">
                <?= csrf_field() ?>
                <div>
                    <label class="form-label small mb-1">Fulfil from unit</label>
                    <select name="seller_mshop_id" class="form-select form-select-sm">
                        <option value="">Choose later…</option>
                        <?php foreach (($units ?? []) as $id => $name): ?>
                            <option value="<?= (int) $id ?>"><?= esc($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-primary">Accept</button>
            </form>
            <form method="post" action="<?= site_url('manufacturer/purchase-orders/' . (int) $po['id'] . '/reject') ?>" class="d-flex gap-2 align-items-end">
                <?= csrf_field() ?>
                <div>
                    <label class="form-label small mb-1">Reason</label>
                    <input name="reject_reason" class="form-control form-control-sm" maxlength="255">
                </div>
                <button class="btn btn-outline-danger">Reject</button>
            </form>
        <?php elseif ($status === 'accepted'): ?>
            <form method="post" action="<?= site_url('manufacturer/purchase-orders/' . (int) $po['id'] . '/pack') ?>">
                <?= csrf_field() ?><button class="btn btn-primary">Mark packed</button>
            </form>
        <?php elseif ($status === 'packed'): ?>
            <form method="post" action="<?= site_url('manufacturer/purchase-orders/' . (int) $po['id'] . '/dispatch') ?>">
                <?= csrf_field() ?><button class="btn btn-primary">Mark dispatched</button>
            </form>
        <?php elseif ($status === 'dispatched'): ?>
            <div class="text-secondary small mb-0">
                Waiting for the buyer to confirm receipt — that is what adds the stock to their shop.
            </div>
        <?php else: ?>
            <div class="text-secondary small mb-0">No actions available for a <?= esc(str_replace('_', ' ', $status)) ?> order.</div>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
