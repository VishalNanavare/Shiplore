<?= $this->extend('monline/_layout') ?>
<?= $this->section('content') ?>
<?php
$moq  = (float) ($product['min_purchase_qty'] ?? 0);
$max  = (float) ($product['max_purchase_qty'] ?? 0);
$step = (float) ($product['qty_step'] ?? 0);

// min_purchase_qty is nullable AND may legitimately be 0, so `?? 1` does not catch it —
// the form would render min="0" value="0" and Add-to-order would post a quantity
// PurchaseRules rejects outright. The step is the correct fallback, not 1: with no
// minimum, PurchaseRules measures multiples from 0, so for a step of 12 the first
// legal quantity is 12.
$start = $moq > 0 ? $moq : ($step > 0 ? $step : 1.0);

$priced = ! empty($showPrices) && isset($product['base_price']);
?>

<nav class="small mb-3 text-secondary">
    <a href="<?= site_url('monline') ?>" class="text-decoration-none">monline</a>
    <?php if (! empty($product['category'])): ?>
        &rsaquo; <a href="<?= site_url('monline/browse') ?>" class="text-decoration-none"><?= esc($product['category']) ?></a>
    <?php endif; ?>
    &rsaquo; <span class="text-dark"><?= esc($product['title'] ?? '') ?></span>
</nav>

<div class="row g-4 mb-4">
    <div class="col-md-5">
        <div class="card border-0 shadow-sm p-3">
            <div class="mo-gallery">
                <?php if (! empty($product['image_uuid'])): ?>
                    <img src="<?= esc(site_url('media/' . $product['image_uuid']), 'attr') ?>" alt="<?= esc($product['title'] ?? '', 'attr') ?>" loading="lazy">
                <?php else: ?>
                    <div class="mo-thumb mo-ph-g<?= (int) ($product['id'] ?? 0) % 6 ?> w-100 h-100"><span class="mo-ph-letter" style="font-size:4rem"><?= esc(mb_strtoupper(mb_substr((string) ($product['title'] ?? ''), 0, 1))) ?></span></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="mo-cat-label"><?= esc($product['manufacturer'] ?? '') ?></div>
        <h1 class="h4 mb-2"><?= esc($product['title'] ?? '') ?></h1>

        <div class="d-flex flex-wrap gap-1 mb-3">
            <?php if (! empty($product['category'])): ?>
                <span class="badge rounded-pill bg-light border mo-moq"><i class="bi bi-tag me-1"></i><?= esc($product['category']) ?></span>
            <?php endif; ?>
            <?php if ($moq > 1): ?>
                <span class="badge rounded-pill bg-light border mo-moq"><i class="bi bi-box-seam me-1"></i>Min order <?= esc(rtrim(rtrim(number_format($moq, 3), '0'), '.')) ?></span>
            <?php endif; ?>
            <?php if (isset($product['distance_km'])): ?>
                <span class="badge mo-dist"><i class="bi bi-signpost-2 me-1"></i><?= (float) $product['distance_km'] < 1 ? 'Nearby' : round((float) $product['distance_km']) . ' km away' ?></span>
            <?php endif; ?>
        </div>

        <?php if ($priced): ?>
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <div class="d-flex align-items-baseline gap-2 mb-3">
                    <span class="h3 mb-0 fw-bold mo-price">₹<?= esc(number_format((float) $product['base_price'], 2)) ?></span>
                    <span class="mo-unit">per unit &middot; excl. GST</span>
                </div>
                <form method="post" action="<?= site_url('monline/cart/add') ?>" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="variant_id" value="<?= (int) ($product['variant_id'] ?? 0) ?>">
                    <input type="hidden" name="slug" value="<?= esc($product['slug'] ?? '', 'attr') ?>">
                    <div class="col-sm-5">
                        <label class="form-label small fw-semibold" for="moQty">Quantity</label>
                        <input id="moQty" name="qty" type="number" class="form-control"
                               min="<?= esc((string) $start, 'attr') ?>"
                               <?= $max > 0 ? 'max="' . esc((string) $max, 'attr') . '"' : '' ?>
                               step="<?= esc((string) ($step > 0 ? $step : 1), 'attr') ?>"
                               value="<?= esc((string) $start, 'attr') ?>" required>
                        <?php if ($moq > 1): ?>
                            <div class="form-text">Minimum order <?= esc(rtrim(rtrim(number_format($moq, 3), '0'), '.')) ?>.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-sm-7"><button class="btn btn-primary btn-lg w-100 fw-semibold"><i class="bi bi-cart-plus me-1"></i>Add to order</button></div>
                </form>
            </div></div>
        <?php elseif (! empty($showPrices)): ?>
            <?php // Signed in, but this product has no live default variant — do not tell them to log in. ?>
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <span class="mo-gate mo-gate-off" style="max-width:260px"><i class="bi bi-dash-circle"></i> Currently unavailable</span>
                <p class="text-secondary small mt-3 mb-0">
                    This product is not available to order right now. The manufacturer may have discontinued it or paused the listing.
                </p>
            </div></div>
        <?php else: ?>
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <a class="mo-gate" style="max-width:260px" href="<?= site_url('login') ?>"><i class="bi bi-lock-fill"></i> Login to view price</a>
                <p class="text-secondary small mt-3 mb-0">
                    Sign in with your vendor or shop-manager account to see wholesale pricing and place an order.
                </p>
            </div></div>
        <?php endif; ?>

        <?php if (! empty($product['description'])): ?>
            <div class="mo-prose small"><p><?= esc($product['description']) ?></p></div>
        <?php endif; ?>
    </div>
</div>

<div class="row g-2 text-secondary small">
    <div class="col-6 col-md-3"><i class="bi bi-building text-primary me-1"></i>Factory-direct pricing</div>
    <div class="col-6 col-md-3"><i class="bi bi-box-seam text-primary me-1"></i>MOQ enforced at checkout</div>
    <div class="col-6 col-md-3"><i class="bi bi-receipt text-primary me-1"></i>GST invoice per order</div>
    <div class="col-6 col-md-3"><i class="bi bi-check2-circle text-primary me-1"></i>Stock added on receipt</div>
</div>

<?= $this->endSection() ?>
