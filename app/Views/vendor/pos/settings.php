<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<?php $cfg = $bill['settings']; ?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Bill / Receipt Settings — <?= esc($bill['shop_name']) ?></span>
                <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('vendor/pos') ?>"><i class="bi bi-arrow-left me-1"></i>POS</a>
            </div>
            <form method="post" action="<?= site_url('vendor/pos/settings') ?>" class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="shop_id" value="<?= (int) $shopId ?>">
                <div class="mb-3"><label class="form-label small">Header note (printed under the shop name)</label>
                    <input name="header_note" class="form-control" value="<?= esc($cfg['header_note'], 'attr') ?>" <?= empty($canEdit) ? 'disabled' : '' ?>></div>
                <div class="mb-3"><label class="form-label small">Terms &amp; conditions (printed at the bottom)</label>
                    <textarea name="terms" rows="4" class="form-control" <?= empty($canEdit) ? 'disabled' : '' ?>><?= esc($cfg['terms']) ?></textarea></div>
                <div class="mb-3"><label class="form-label small">Footer note</label>
                    <input name="footer_note" class="form-control" value="<?= esc($cfg['footer_note'], 'attr') ?>" <?= empty($canEdit) ? 'disabled' : '' ?>></div>
                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="ss" name="show_savings" value="1" <?= ! empty($cfg['show_savings']) ? 'checked' : '' ?> <?= empty($canEdit) ? 'disabled' : '' ?>>
                    <label class="form-check-label small" for="ss">Show the customer's savings (MRP − sell price) on the bill</label>
                </div>
                <?php if (! empty($canEdit)): ?>
                    <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
                <?php else: ?>
                    <div class="text-secondary small">You don't have permission to change these settings.</div>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold">What prints on the bill</div>
            <div class="card-body small text-secondary">
                <p class="mb-1">Pulled automatically from the store record:</p>
                <ul class="mb-2">
                    <li>Shop name: <strong><?= esc($bill['shop_name']) ?></strong></li>
                    <li>Address: <?= esc($bill['address'] ?: '—') ?><?= $bill['pincode'] ? ' - ' . esc($bill['pincode']) : '' ?></li>
                    <li>Phone: <?= esc($bill['phone'] ?: '—') ?></li>
                    <li>GSTIN: <?= esc($bill['gstin'] ?: '—') ?></li>
                </ul>
                <p class="mb-0">Each line shows MRP, sell price and GST%; the bill shows the subtotal,
                   discount, taxable value, CGST/SGST/IGST, delivery (if any), round-off, grand total,
                   savings and payment breakup — followed by your terms and footer.</p>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
