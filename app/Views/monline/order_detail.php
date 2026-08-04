<?= $this->extend('monline/_layout') ?>
<?= $this->section('content') ?>

<a class="small text-decoration-none" href="<?= site_url('monline/orders') ?>">&larr; All purchase orders</a>

<div class="d-flex justify-content-between align-items-center mt-3 mb-3">
    <h5 class="mb-0"><?= esc($po['po_no']) ?></h5>
    <span class="badge bg-secondary fs-6"><?= esc(str_replace('_', ' ', (string) $po['status'])) ?></span>
</div>

<div class="card mb-3">
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
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
                <tr><td colspan="4" class="text-end text-secondary">Taxable</td><td class="text-end">₹<?= esc(number_format((float) $po['taxable_value'], 2)) ?></td></tr>
                <?php if ((float) $po['igst'] > 0): ?>
                    <tr><td colspan="4" class="text-end text-secondary">IGST</td><td class="text-end">₹<?= esc(number_format((float) $po['igst'], 2)) ?></td></tr>
                <?php else: ?>
                    <tr><td colspan="4" class="text-end text-secondary">CGST + SGST</td><td class="text-end">₹<?= esc(number_format((float) $po['cgst'] + (float) $po['sgst'], 2)) ?></td></tr>
                <?php endif; ?>
                <tr class="fw-semibold"><td colspan="4" class="text-end">Grand total</td><td class="text-end">₹<?= esc(number_format((float) $po['grand_total'], 2)) ?></td></tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="d-flex gap-2">
    <?php if ((string) $po['status'] === 'dispatched'): ?>
        <form method="post" action="<?= site_url('monline/orders/' . (int) $po['id'] . '/receive') ?>">
            <?= csrf_field() ?>
            <button class="btn btn-primary">Mark received &amp; add to stock</button>
        </form>
    <?php endif; ?>
    <?php if (in_array((string) $po['status'], ['placed', 'accepted'], true)): ?>
        <form method="post" action="<?= site_url('monline/orders/' . (int) $po['id'] . '/cancel') ?>">
            <?= csrf_field() ?>
            <button class="btn btn-outline-danger">Cancel order</button>
        </form>
    <?php endif; ?>
</div>

<?php if ((string) $po['status'] === 'rejected' && ! empty($po['reject_reason'])): ?>
    <div class="alert alert-warning mt-3 mb-0">Rejected by the manufacturer: <?= esc($po['reject_reason']) ?></div>
<?php endif; ?>

<?= $this->endSection() ?>
