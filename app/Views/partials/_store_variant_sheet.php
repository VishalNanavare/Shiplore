<?php
/**
 * _store_variant_sheet.php — body of the quick-add variant bottom sheet.
 * One row per variant: label + price + the standard add/qty stepper (reused, so
 * all cart AJAX + live qty sync work unchanged). Loaded over AJAX by store-ux.js.
 *
 * @var list<array<string,mixed>> $vars
 * @var string                    $title
 */
?>
<div class="st-vsheet">
    <?php foreach ($vars as $v):
        $base = (float) $v['base_price'];
        $mrp  = (float) ($v['mrp'] ?? 0);
        $off  = $mrp > $base && $mrp > 0 ? (int) round(($mrp - $base) / $mrp * 100) : 0;
    ?>
        <div class="st-vrow">
            <div class="st-vrow-info">
                <div class="st-vrow-label"><?= esc($v['label'] ?: $v['sku']) ?></div>
                <div class="st-vrow-price">
                    <span class="fw-bold">₹<?= esc(number_format($base, 0)) ?></span>
                    <?php if ($off > 0): ?>
                        <span class="st-mrp ms-1">₹<?= esc(number_format($mrp, 0)) ?></span>
                        <span class="text-success small ms-1"><?= $off ?>% off</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="st-vrow-action">
                <?php if (! empty($v['out'])): ?>
                    <span class="badge text-bg-light border text-secondary">Out of stock</span>
                <?php else: ?>
                    <?= view('partials/_store_stepper', ['variantId' => $v['variant_id']]) ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
