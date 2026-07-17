<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0"><i class="bi bi-arrow-return-left me-1"></i>Returns / Credit Notes</h1>
    <div>
        <a href="<?= site_url('vendor/pos/credit-note') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-receipt me-1"></i>Walk-in credit note</a>
        <a href="<?= site_url('vendor/pos') ?>" class="btn btn-sm btn-primary"><i class="bi bi-shop me-1"></i>Back to POS</a>
    </div>
</div>

<form method="get" action="<?= site_url('vendor/pos/returns') ?>" class="mb-3">
    <div class="input-group" style="max-width:480px">
        <input name="ref" class="form-control" value="<?= esc($ref, 'attr') ?>" placeholder="Invoice number to return…" autofocus>
        <button class="btn btn-primary">Find sale</button>
    </div>
</form>

<?php if ($ref !== '' && $sale === null): ?>
    <div class="alert alert-warning">No sale found for "<?= esc($ref) ?>".</div>
<?php elseif ($sale !== null): ?>
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between mb-2"><span class="fw-semibold">Invoice <?= esc($sale['server_invoice_no']) ?></span><span class="text-secondary small"><?= esc(substr((string) $sale['sold_at'], 0, 16)) ?> · Total ₹<?= esc(number_format((float) $sale['grand_total'], 2)) ?></span></div>
        <form method="post" action="<?= site_url('vendor/pos/returns') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="sale_id" value="<?= esc($sale['id'], 'attr') ?>">
            <table class="table table-sm align-middle">
                <thead class="table-light"><tr><th>Item</th><th class="text-end">Sold</th><th class="text-end">Returned</th><th class="text-end">Price</th><th style="width:120px">Return qty</th></tr></thead>
                <tbody>
                <?php foreach ($sale['items'] as $it): ?>
                    <tr>
                        <td class="small"><?= esc($it['product_title_snapshot']) ?><div class="text-secondary"><?= esc($it['sku_snapshot']) ?></div></td>
                        <td class="text-end small"><?= esc(rtrim(rtrim((string) $it['qty'], '0'), '.')) ?></td>
                        <td class="text-end small text-secondary"><?= esc(rtrim(rtrim((string) $it['returned_qty'], '0'), '.')) ?></td>
                        <td class="text-end small">₹<?= esc(number_format((float) $it['unit_price'], 2)) ?></td>
                        <td>
                            <?php if ($it['returnable_qty'] > 0): ?>
                                <input name="qty[<?= esc($it['id'], 'attr') ?>]" type="number" min="0" max="<?= esc($it['returnable_qty'], 'attr') ?>" step="0.001" value="0" class="form-control form-control-sm">
                            <?php else: ?><span class="text-secondary small">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="row g-2 align-items-end">
                <div class="col-md-5"><label class="form-label small">Reason</label><input name="reason" class="form-control form-control-sm" placeholder="e.g. wrong size, damaged"></div>
                <div class="col-md-3"><label class="form-label small">Refund via</label><select name="refund_method" class="form-select form-select-sm"><option value="cash">Cash</option><option value="upi">UPI</option><option value="card">Card</option><option value="wallet">Wallet</option><option value="exchange">Exchange (no stock back)</option><option value="credit_note">Credit note</option></select></div>
                <div class="col-md-4 text-end"><button class="btn btn-success"><i class="bi bi-check2 me-1"></i>Process return</button></div>
            </div>
        </form>
    </div></div>
<?php endif; ?>

<div class="card"><div class="card-header fw-semibold">Recent credit notes</div><div class="table-responsive"><table class="table table-sm mb-0">
    <thead class="table-light"><tr><th>CN No.</th><th>Against / customer</th><th>Store</th><th>Via</th><th class="text-end">Refund</th><th>When</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
        <tr>
            <td class="small fw-semibold"><?= esc($r['credit_note_no'] ?? ('#' . $r['id'])) ?></td>
            <td class="small text-secondary"><?= esc($r['server_invoice_no'] ?? ($r['customer_name'] ?? 'walk-in')) ?></td>
            <td class="small"><?= esc($r['shop'] ?? '—') ?></td>
            <td class="small text-capitalize"><?= esc(str_replace('_', ' ', $r['refund_method'])) ?></td>
            <td class="text-end small">₹<?= esc(number_format((float) $r['refund_amount'], 2)) ?></td>
            <td class="small text-secondary"><?= esc(substr((string) $r['created_at'], 0, 16)) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= site_url('vendor/pos/credit-note/' . $r['id']) ?>"><i class="bi bi-printer"></i></a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($recent)): ?><tr><td colspan="7" class="text-center text-secondary py-3">No credit notes yet.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= $this->endSection() ?>
