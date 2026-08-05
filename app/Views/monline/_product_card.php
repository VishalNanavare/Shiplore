<?php
/** @var array $p */
/** @var bool $showPrices */
$img = ! empty($p['image_uuid']) ? site_url('media/' . $p['image_uuid']) : null;
?>
<div class="card mo-pcard h-100">
    <a href="<?= site_url('monline/product/' . rawurlencode((string) $p['slug'])) ?>" class="mo-thumb-wrap">
        <?php if ($img): ?>
            <img src="<?= esc($img, 'attr') ?>" class="mo-thumb-img" alt="<?= esc($p['title'] ?? '', 'attr') ?>" loading="lazy">
        <?php else: ?>
            <div class="mo-thumb-ph"><span><?= esc(mb_strtoupper(mb_substr((string) ($p['title'] ?? ''), 0, 1))) ?></span></div>
        <?php endif; ?>
    </a>
    <div class="card-body p-2 p-md-3 d-flex flex-column">
        <div class="mo-cat-label text-truncate"><?= esc($p['category'] ?? '') ?></div>
        <a href="<?= site_url('monline/product/' . rawurlencode((string) $p['slug'])) ?>" class="mo-ptitle">
            <?= esc($p['title'] ?? '') ?>
        </a>
        <div class="mo-vendor text-truncate"><i class="bi bi-building me-1"></i><?= esc($p['manufacturer'] ?? '') ?></div>
        <?php if (! empty($p['min_purchase_qty']) && (float) $p['min_purchase_qty'] > 1): ?>
            <div class="mo-moq">Min order <?= esc(rtrim(rtrim(number_format((float) $p['min_purchase_qty'], 3), '0'), '.')) ?></div>
        <?php endif; ?>
        <?php if (isset($p['distance_km'])): ?>
            <div class="mo-distance"><i class="bi bi-signpost-2"></i> <?= (float) $p['distance_km'] < 1 ? 'Nearby' : round((float) $p['distance_km']) . ' km away' ?></div>
        <?php endif; ?>

        <?php
        /*
         * Prices only exist in $p when the controller opted in for a signed-in buyer.
         * For a logged-out visitor the key is absent — not zero, not hidden — so there
         * is nothing to leak here.
         */
        ?>
        <div class="mt-auto pt-2">
            <?php if (! empty($showPrices) && isset($p['base_price'])): ?>
                <span class="mo-price">₹<?= esc(number_format((float) $p['base_price'], 2)) ?></span>
            <?php else: ?>
                <a class="mo-gate" href="<?= site_url('login') ?>"><i class="bi bi-lock"></i> Login to view price</a>
            <?php endif; ?>
        </div>
    </div>
</div>
