<?= $this->extend('monline/_layout') ?>
<?= $this->section('content') ?>

<h5 class="mb-3">Your order</h5>

<?php if (empty($lines)): ?>
    <div class="card"><div class="card-body text-center text-secondary py-5">
        Your order is empty. <a href="<?= site_url('monline/browse') ?>">Browse the catalogue</a>.
    </div></div>
<?php else: ?>
    <form method="post" action="<?= site_url('monline/cart/update') ?>">
        <?= csrf_field() ?>
        <div class="card mb-3">
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead><tr><th>Product</th><th>Manufacturer</th><th style="width:150px">Qty</th><th class="text-end">Unit</th><th class="text-end">Total</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($lines as $l): ?>
                            <tr>
                                <td>
                                    <?= esc($l['title'] ?? '') ?>
                                    <div class="small text-secondary"><?= esc((string) ($l['sku'] ?? '')) ?></div>
                                </td>
                                <td class="small"><?= esc($l['manufacturer'] ?? '') ?></td>
                                <td>
                                    <input name="qty[<?= (int) $l['variant_id'] ?>]" type="number" class="form-control form-control-sm"
                                           min="<?= esc((string) ($l['min_purchase_qty'] ?? 1), 'attr') ?>"
                                           step="<?= esc((string) ($l['qty_step'] ?? 1), 'attr') ?>"
                                           value="<?= esc((string) $l['qty'], 'attr') ?>">
                                </td>
                                <td class="text-end">₹<?= esc(number_format((float) $l['base_price'], 2)) ?></td>
                                <td class="text-end">₹<?= esc(number_format((float) $l['line_total'], 2)) ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-link text-danger p-0" formaction="<?= site_url('monline/cart/remove/' . (int) $l['variant_id']) ?>" formmethod="post">Remove</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <button class="btn btn-outline-secondary btn-sm mb-4">Update quantities</button>
    </form>

    <div class="card">
        <div class="card-body">
            <form method="post" action="<?= site_url('monline/place') ?>" class="row g-3">
                <?= csrf_field() ?>
                <div class="col-md-6">
                    <label class="form-label">Deliver to <span class="text-danger">*</span></label>
                    <select name="buyer_shop_id" class="form-select" required>
                        <option value="">Choose one of your shops…</option>
                        <?php foreach ($shops as $id => $name): ?>
                            <option value="<?= (int) $id ?>"><?= esc($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Stock is added here when you mark the order received.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Notes for the manufacturer</label>
                    <input name="notes" class="form-control" maxlength="500">
                </div>
                <div class="col-12 d-flex justify-content-between align-items-center border-top pt-3">
                    <div>
                        <div class="text-secondary small">Subtotal (excl. GST)</div>
                        <div class="fs-4 fw-semibold">₹<?= esc(number_format((float) $subtotal, 2)) ?></div>
                        <div class="small text-secondary">GST is calculated per manufacturer when the order is placed.</div>
                    </div>
                    <button class="btn btn-primary btn-lg">Place purchase order</button>
                </div>
            </form>
        </div>
    </div>
    <p class="small text-secondary mt-2 mb-0">
        Items from different manufacturers become separate purchase orders — each is accepted and dispatched independently.
    </p>
<?php endif; ?>

<?= $this->endSection() ?>
