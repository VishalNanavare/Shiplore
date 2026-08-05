<?php
/**
 * monline product card — same anatomy as partials/_store_product_card.php.
 *
 * The ONLY structural difference is the action row: the storefront has a
 * quick-add stepper, monline has a price that may not exist. Prices are absent
 * from $p entirely for a logged-out visitor (the controller never selects them),
 * so the gate below is a server-side fact, not a CSS hide.
 *
 * @var array $p
 * @var bool  $showPrices
 */
$img = ! empty($p['image_uuid']) ? site_url('media/' . $p['image_uuid']) : null;
$moq = (float) ($p['min_purchase_qty'] ?? 0);
$url = site_url('monline/product/' . rawurlencode((string) $p['slug']));
?>
<div class="card mo-pcard h-100">
    <a href="<?= esc($url, 'attr') ?>" class="mo-thumb-wrap">
        <?php if ($img): ?>
            <img src="<?= esc($img, 'attr') ?>" class="mo-thumb-img" alt="<?= esc($p['title'] ?? '', 'attr') ?>" loading="lazy">
        <?php else: ?>
            <div class="mo-thumb mo-ph-g<?= (int) $p['id'] % 6 ?>"><span class="mo-ph-letter"><?= esc(mb_strtoupper(mb_substr((string) ($p['title'] ?? ''), 0, 1))) ?></span></div>
        <?php endif; ?>
    </a>
    <div class="card-body p-2 p-md-3 d-flex flex-column">
        <div class="mo-cat-label text-truncate"><?= esc($p['category'] ?? '') ?></div>
        <a href="<?= esc($url, 'attr') ?>" class="mo-ptitle"><?= esc($p['title'] ?? '') ?></a>
        <div class="mo-vendor text-truncate"><i class="bi bi-building me-1"></i><?= esc($p['manufacturer'] ?? '') ?></div>

        <div class="d-flex flex-wrap gap-1 mt-1">
            <?php if ($moq > 1): ?>
                <span class="badge rounded-pill text-bg-light border mo-moq"><i class="bi bi-box-seam me-1"></i>Min <?= esc(rtrim(rtrim(number_format($moq, 3), '0'), '.')) ?></span>
            <?php endif; ?>
            <?php if (isset($p['distance_km'])): ?>
                <span class="badge mo-dist"><i class="bi bi-signpost-2 me-1"></i><?= (float) $p['distance_km'] < 1 ? 'Nearby' : round((float) $p['distance_km']) . ' km' ?></span>
            <?php endif; ?>
        </div>

        <?php
        /*
         * Prices only exist in $p when the controller opted in for a signed-in buyer.
         * For a logged-out visitor the key is absent — not zero, not hidden — so there
         * is nothing to leak here.
         */
        ?>
        <?php if (! empty($showPrices) && isset($p['base_price'])): ?>
            <div class="d-flex align-items-baseline gap-2 mt-1 mb-2">
                <span class="mo-price">₹<?= esc(number_format((float) $p['base_price'], 2)) ?></span>
                <span class="mo-unit">per unit</span>
            </div>
        <?php endif; ?>

        <div class="mt-auto">
            <?php if (! empty($showPrices) && isset($p['base_price'])): ?>
                <a href="<?= esc($url, 'attr') ?>" class="btn btn-sm btn-outline-primary w-100 fw-semibold">View &amp; order</a>
            <?php else: ?>
                <a class="mo-gate" href="<?= site_url('login') ?>"><i class="bi bi-lock-fill"></i> Login to view price</a>
            <?php endif; ?>
        </div>
    </div>
</div>
