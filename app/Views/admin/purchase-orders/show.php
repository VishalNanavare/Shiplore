<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
// Force-cancel is offered only where StatusMachine would actually permit it: before the
// goods move. After dispatch there is stock in transit that cancelling would not unwind.
$cancellable = in_array((string) $po['status'], ['draft', 'placed', 'accepted', 'packed'], true);
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <a href="<?= site_url('admin/purchase-orders') ?>" class="small text-decoration-none">&larr; All purchase orders</a>
        <h2 class="h4 mb-0 mt-1"><?= esc($po['po_no']) ?>
            <span class="badge text-bg-secondary align-middle"><?= esc(str_replace('_', ' ', (string) $po['status'])) ?></span>
        </h2>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-shop me-1"></i>Buyer</div>
            <div class="card-body small">
                <div class="fw-semibold">
                    <a href="<?= site_url('admin/vendors/' . (int) $po['buyer_vendor_id']) ?>"><?= esc((string) ($po['buyer_name'] ?? '—')) ?></a>
                </div>
                <div class="text-secondary">Deliver to: <?= esc((string) ($po['buyer_shop_name'] ?? '—')) ?></div>
                <div class="text-secondary">GSTIN: <code><?= esc((string) ($po['buyer_gstin'] ?? $po['buyer_vendor_gstin'] ?? '—')) ?></code></div>
                <div class="text-secondary">Place of supply: <?= esc((string) ($po['place_of_supply'] ?? '—')) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-building me-1"></i>Manufacturer</div>
            <div class="card-body small">
                <div class="fw-semibold">
                    <a href="<?= site_url('admin/manufacturers/' . (int) $po['seller_vendor_id']) ?>"><?= esc((string) ($po['seller_name'] ?? '—')) ?></a>
                </div>
                <div class="text-secondary">GSTIN: <code><?= esc((string) ($po['seller_gstin'] ?? '—')) ?></code></div>
                <div class="text-secondary">Placed: <?= esc((string) ($po['placed_at'] ?? '—')) ?></div>
                <?php if (! empty($po['dispatched_at'])): ?><div class="text-secondary">Dispatched: <?= esc((string) $po['dispatched_at']) ?></div><?php endif; ?>
                <?php if (! empty($po['received_at'])): ?><div class="text-secondary">Received: <?= esc((string) $po['received_at']) ?></div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header fw-semibold">Line items</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Product</th><th>SKU</th><th>HSN</th><th class="text-end">Qty</th><th class="text-end">Unit</th><th class="text-end">Line total</th></tr></thead>
            <tbody>
                <?php foreach ($items as $i): ?>
                    <tr class="<?= ($i['status'] ?? 'active') !== 'active' ? 'text-secondary' : '' ?>">
                        <td>
                            <?= esc($i['product_title_snapshot']) ?>
                            <?php if (($i['status'] ?? 'active') !== 'active'): ?>
                                <span class="badge text-bg-light border ms-1"><?= esc((string) $i['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-secondary"><?= esc((string) ($i['sku_snapshot'] ?? '—')) ?></td>
                        <td class="small text-secondary"><?= esc((string) ($i['hsn_snapshot'] ?? '—')) ?></td>
                        <td class="text-end"><?= esc(rtrim(rtrim(number_format((float) $i['qty'], 3), '0'), '.')) ?></td>
                        <td class="text-end">₹<?= esc(number_format((float) $i['unit_price'], 2)) ?></td>
                        <td class="text-end">₹<?= esc(number_format((float) $i['line_total'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="border-top">
                <tr><td colspan="5" class="text-end text-secondary">Taxable</td><td class="text-end">₹<?= esc(number_format((float) $po['taxable_value'], 2)) ?></td></tr>
                <?php if ((float) $po['igst'] > 0): ?>
                    <tr><td colspan="5" class="text-end text-secondary">IGST</td><td class="text-end">₹<?= esc(number_format((float) $po['igst'], 2)) ?></td></tr>
                <?php else: ?>
                    <tr><td colspan="5" class="text-end text-secondary">CGST + SGST</td><td class="text-end">₹<?= esc(number_format((float) $po['cgst'] + (float) $po['sgst'], 2)) ?></td></tr>
                <?php endif; ?>
                <tr class="fw-semibold"><td colspan="5" class="text-end">Grand total</td><td class="text-end">₹<?= esc(number_format((float) $po['grand_total'], 2)) ?></td></tr>
            </tfoot>
        </table>
    </div>
</div>

<?php if (! empty($po['reject_reason'])): ?>
    <div class="alert alert-warning">Reason on record: <?= esc((string) $po['reject_reason']) ?></div>
<?php endif; ?>

<?php if ($cancellable): ?>
    <div class="card border-danger-subtle">
        <div class="card-header fw-semibold text-danger">Force-cancel</div>
        <div class="card-body">
            <p class="small text-secondary">
                For an order stuck between two parties who will not move it. Both are notified.
                Not available once the goods have been dispatched.
            </p>
            <form method="post" action="<?= site_url('admin/purchase-orders/' . (int) $po['id'] . '/cancel') ?>" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <div class="col-md-9">
                    <label class="form-label small">Reason <span class="text-danger">*</span></label>
                    <input name="reason" class="form-control form-control-sm" maxlength="200" required placeholder="Why is the platform cancelling this order?">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-x-octagon me-1"></i>Cancel order</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
