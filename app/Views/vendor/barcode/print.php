<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Print Barcodes — <?= esc($product['title'] ?? '') ?></span>
        <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('vendor/products') ?>"><i class="bi bi-arrow-left me-1"></i>Products</a>
    </div>
    <form method="post" action="<?= site_url('vendor/products/' . $product['id'] . '/barcodes/print') ?>" target="_blank">
        <?= csrf_field() ?>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Variant (SKU)</th><th>Barcode</th><th>MRP</th><th style="width:140px">Copies</th></tr></thead>
            <tbody>
            <?php foreach ($variants as $v): ?>
                <tr>
                    <td class="small fw-semibold"><?= esc($v['sku'] ?? '') ?></td>
                    <td class="small"><code><?= esc(($v['barcode'] ?? '') !== '' ? $v['barcode'] : ($v['sku'] ?? '')) ?></code></td>
                    <td class="small">₹<?= esc(number_format((float) ($v['mrp'] ?? 0), 2)) ?></td>
                    <td><input type="number" min="0" value="0" name="qty[<?= (int) $v['id'] ?>]" class="form-control form-control-sm"></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($variants)): ?><tr><td colspan="4" class="text-center text-secondary py-4">This product has no variants.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <div class="card-footer d-flex gap-2 align-items-center">
            <label class="small text-secondary mb-0">Columns</label>
            <select name="columns" class="form-select form-select-sm w-auto"><option>2</option><option selected>3</option><option>4</option></select>
            <button class="btn btn-sm btn-primary ms-auto"><i class="bi bi-printer me-1"></i>Generate label sheet (PDF)</button>
        </div>
    </form>
</div>
<?= $this->endSection() ?>
