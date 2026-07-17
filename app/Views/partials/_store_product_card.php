<?php
/** @var array $p */
$base = (float) ($p['base_price'] ?? 0);
$mrp  = (float) ($p['mrp'] ?? 0);
$off  = $mrp > $base && $mrp > 0 ? (int) round(($mrp - $base) / $mrp * 100) : 0;
$img  = ! empty($p['image_uuid']) ? site_url('media/' . $p['image_uuid']) : null;
?>
<div class="card st-pcard h-100">
    <a href="<?= site_url('store/product/' . $p['slug']) ?>" class="st-thumb-wrap">
        <?php if ($off > 0): ?><span class="st-off-badge"><?= $off ?>% OFF</span><?php endif; ?>
        <?php if ($img): ?>
            <img src="<?= esc($img, 'attr') ?>" class="st-thumb-img" alt="<?= esc($p['title'], 'attr') ?>" loading="lazy">
        <?php else: ?>
            <div class="st-thumb st-ph-g<?= (int) $p['id'] % 6 ?>"><span class="st-ph-letter"><?= esc(mb_strtoupper(mb_substr((string) $p['title'], 0, 1))) ?></span></div>
        <?php endif; ?>
    </a>
    <div class="card-body p-2 p-md-3 d-flex flex-column">
        <div class="st-cat-label text-truncate"><?= esc($p['category'] ?? '') ?></div>
        <a href="<?= site_url('store/product/' . $p['slug']) ?>" class="st-ptitle"><?= esc($p['title']) ?></a>
        <div class="st-vendor text-truncate"><i class="bi bi-shop me-1"></i><?= esc($p['vendor'] ?? '') ?></div>
        <?php if ((int) ($p['variant_count'] ?? 1) > 1): ?><div class="mt-1"><span class="badge rounded-pill text-bg-light border"><i class="bi bi-sliders2 me-1"></i><?= (int) $p['variant_count'] ?> options</span></div><?php endif; ?>
        <div class="d-flex align-items-baseline gap-2 mt-1 mb-2">
            <span class="st-price">₹<?= esc(number_format($base, 0)) ?></span>
            <?php if ($off > 0): ?><span class="st-mrp">₹<?= esc(number_format($mrp, 0)) ?></span><?php endif; ?>
        </div>
        <div class="mt-auto d-flex gap-1 align-items-center">
            <div class="flex-grow-1">
                <?php if (! empty($p['variant_id'])): ?>
                    <?= view('partials/_store_stepper', ['variantId' => $p['variant_id']]) ?>
                <?php elseif ((int) ($p['variant_count'] ?? 0) > 1): ?>
                    <button type="button" class="st-add-options" data-variant-sheet="<?= site_url('store/product/' . (int) $p['id'] . '/variants') ?>" data-title="<?= esc($p['title'], 'attr') ?>">ADD <i class="bi bi-chevron-down ms-1"></i></button>
                <?php else: ?>
                    <a href="<?= site_url('store/product/' . $p['slug']) ?>" class="btn btn-sm btn-outline-primary w-100">View</a>
                <?php endif; ?>
            </div>
            <form method="post" action="<?= site_url('store/wishlist/add') ?>" data-ajax-stay><?= csrf_field() ?><input type="hidden" name="product_id" value="<?= esc($p['id'], 'attr') ?>"><button class="btn btn-sm btn-outline-secondary st-wish" title="Save"><i class="bi bi-heart"></i></button></form>
        </div>
    </div>
</div>
