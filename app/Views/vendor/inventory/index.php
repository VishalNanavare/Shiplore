<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Inventory &amp; Pricing</span><span class="d-flex align-items-center gap-2"><?= view('partials/_shop_filter', ['shopOptions' => $shopOptions ?? [], 'shopId' => $shopId ?? null]) ?><span class="text-secondary small"><?= count($rows) ?> items</span></span></div>
    <div class="card-body">
        <?php if (empty($rows)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-clipboard-data display-6 d-block mb-2"></i>No inventory yet.</div>
        <?php else: ?>
        <div class="table-responsive" data-ajax-region><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Product</th><th>SKU</th><th>Shop</th><th style="width:110px">Stock</th><th style="width:130px">Price (₹)</th><th style="width:130px">MRP (₹)</th><th>Available</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $f = 'invf' . $r['id']; ?>
                <tr>
                    <td class="fw-medium"><?= esc($r['title'] ?? '—') ?></td>
                    <td class="small text-secondary"><?= esc($r['sku'] ?? '—') ?></td>
                    <td class="small"><?= esc($r['shop'] ?? '—') ?></td>
                    <td><input form="<?= $f ?>" name="on_hand" class="form-control form-control-sm" value="<?= esc(rtrim(rtrim((string) $r['on_hand'], '0'), '.'), 'attr') ?>"></td>
                    <td><input form="<?= $f ?>" name="base_price" class="form-control form-control-sm" value="<?= esc(number_format((float) $r['base_price'], 2, '.', ''), 'attr') ?>"></td>
                    <td><input form="<?= $f ?>" name="mrp" class="form-control form-control-sm" value="<?= esc(number_format((float) $r['mrp'], 2, '.', ''), 'attr') ?>"></td>
                    <td><span class="badge bg-<?= (float) $r['available'] <= (float) $r['reorder_level'] ? 'danger' : 'success' ?>-subtle text-<?= (float) $r['available'] <= (float) $r['reorder_level'] ? 'danger' : 'success' ?>"><?= esc(rtrim(rtrim((string) $r['available'], '0'), '.')) ?></span></td>
                    <td class="text-end"><button form="<?= $f ?>" class="btn btn-sm btn-primary"><i class="bi bi-save"></i></button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php foreach ($rows as $r): ?>
            <form id="invf<?= $r['id'] ?>" method="post" action="<?= site_url('vendor/inventory/' . $r['id'] . '/update') ?>" class="d-none">
                <?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= esc($r['variant_id'], 'attr') ?>">
            </form>
        <?php endforeach; ?>
        <p class="text-secondary small mt-2 mb-0">Editing updates stock (this shop) and price/MRP (the variant) for your own catalog only.</p>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
