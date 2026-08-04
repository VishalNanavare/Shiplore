<?= $this->extend('monline/_layout') ?>
<?= $this->section('content') ?>

<a class="small text-decoration-none" href="<?= site_url('monline/browse') ?>">&larr; Back to catalogue</a>

<div class="card mo-pcard mt-3">
    <div class="card-body">
        <div class="row g-4">
            <div class="col-md-4">
                <?php if (! empty($product['image_uuid'])): ?>
                    <div class="mo-thumb-wrap">
                        <img src="<?= esc(site_url('media/' . $product['image_uuid']), 'attr') ?>" class="mo-thumb-img" alt="<?= esc($product['title'] ?? '', 'attr') ?>" loading="lazy">
                    </div>
                <?php else: ?>
                    <div class="mo-thumb-wrap"><div class="mo-thumb-ph"><span><?= esc(mb_strtoupper(mb_substr((string) ($product['title'] ?? ''), 0, 1))) ?></span></div></div>
                <?php endif; ?>
            </div>
            <div class="col-md-8">
                <div class="mo-cat-label"><?= esc($product['manufacturer'] ?? '') ?> &middot; <?= esc($product['category'] ?? '') ?></div>
                <h1 class="h4 mb-3"><?= esc($product['title'] ?? '') ?></h1>
                <?php if (! empty($product['description'])): ?>
                    <p class="text-secondary"><?= esc($product['description']) ?></p>
                <?php endif; ?>

                <?php if (! empty($showPrices) && isset($product['base_price'])): ?>
                    <div class="mo-price fs-3 mb-3">₹<?= esc(number_format((float) $product['base_price'], 2)) ?></div>

                    <form method="post" action="<?= site_url('monline/cart/add') ?>" class="row g-2 align-items-end" style="max-width:420px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="variant_id" value="<?= (int) ($product['variant_id'] ?? 0) ?>">
                        <input type="hidden" name="slug" value="<?= esc($product['slug'] ?? '', 'attr') ?>">
                        <div class="col-7">
                            <label class="form-label small">Quantity</label>
                            <input name="qty" type="number" class="form-control"
                                   min="<?= esc((string) ($product['min_purchase_qty'] ?? 1), 'attr') ?>"
                                   step="<?= esc((string) ($product['qty_step'] ?? 1), 'attr') ?>"
                                   value="<?= esc((string) ($product['min_purchase_qty'] ?? 1), 'attr') ?>" required>
                            <?php if (! empty($product['min_purchase_qty']) && (float) $product['min_purchase_qty'] > 1): ?>
                                <div class="form-text">Minimum order <?= esc(rtrim(rtrim(number_format((float) $product['min_purchase_qty'], 3), '0'), '.')) ?>.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-5"><button class="btn btn-primary w-100">Add to order</button></div>
                    </form>
                <?php else: ?>
                    <?php if (! empty($product['min_purchase_qty']) && (float) $product['min_purchase_qty'] > 1): ?>
                        <div class="mo-moq mb-2">Min order <?= esc(rtrim(rtrim(number_format((float) $product['min_purchase_qty'], 3), '0'), '.')) ?></div>
                    <?php endif; ?>
                    <a class="mo-gate mb-3" href="<?= site_url('login') ?>"><i class="bi bi-lock"></i> Login to view price</a>
                    <p class="text-secondary small mt-3 mb-0">
                        Sign in with your vendor or shop-manager account to see wholesale pricing and place an order.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
