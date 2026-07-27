<?= $this->extend('msonline/_layout') ?>
<?= $this->section('content') ?>

<a class="small text-decoration-none" href="<?= site_url('msonline/browse') ?>">&larr; Back to catalogue</a>

<div class="card mt-3">
    <div class="card-body">
        <div class="small text-secondary"><?= esc($product['manufacturer'] ?? '') ?> · <?= esc($product['category'] ?? '') ?></div>
        <h4 class="mb-3"><?= esc($product['title'] ?? '') ?></h4>
        <?php if (! empty($product['description'])): ?>
            <p class="text-secondary"><?= esc($product['description']) ?></p>
        <?php endif; ?>

        <?php if (! empty($showPrices) && isset($product['base_price'])): ?>
            <div class="fs-3 fw-semibold text-primary mb-3">₹<?= esc(number_format((float) $product['base_price'], 2)) ?></div>

            <form method="post" action="<?= site_url('msonline/cart/add') ?>" class="row g-2 align-items-end" style="max-width:420px">
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
            <div class="alert alert-light border">
                Sign in with your vendor account to see wholesale pricing and place an order.
            </div>
            <a class="btn btn-primary" href="<?= site_url('login') ?>">Sign in</a>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
