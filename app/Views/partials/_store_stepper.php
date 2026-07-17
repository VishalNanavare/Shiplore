<?php
/**
 * _store_stepper.php — quick-commerce add/qty control (Blinkit-style).
 * Renders "ADD" when the variant isn't in the cart, else a − N + stepper.
 * Both states are always in the DOM; CSS (injected by store-ux.js) shows one
 * based on `.in-cart`, and store-ux.js keeps data-qty / the − form's qty in sync
 * after each add/update (live, no reload). CSRF lives in the forms (rotated by
 * ajax-forms.js), so the control never needs to be rebuilt in JS.
 *
 * @var int $variantId
 */
$variantId = (int) $variantId;
$qty       = (int) (service('cartService')->raw()[$variantId] ?? 0);
?>
<div class="st-stepper<?= $qty > 0 ? ' in-cart' : '' ?>" data-variant="<?= $variantId ?>" data-qty="<?= $qty ?>">
    <form method="post" action="<?= site_url('store/cart/add') ?>" data-ajax-stay class="st-add">
        <?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= $variantId ?>"><input type="hidden" name="qty" value="1">
        <button type="submit" class="btn btn-sm btn-outline-primary fw-semibold px-3">ADD</button>
    </form>
    <div class="st-qty btn-group btn-group-sm">
        <form method="post" action="<?= site_url('store/cart/update') ?>" data-ajax-stay class="st-dec">
            <?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= $variantId ?>"><input type="hidden" name="qty" value="<?= max(0, $qty - 1) ?>">
            <button type="submit" class="btn btn-primary"><i class="bi bi-dash"></i></button>
        </form>
        <span class="btn btn-primary disabled st-q"><?= $qty ?></span>
        <form method="post" action="<?= site_url('store/cart/add') ?>" data-ajax-stay class="st-inc">
            <?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= $variantId ?>"><input type="hidden" name="qty" value="1">
            <button type="submit" class="btn btn-primary"><i class="bi bi-plus"></i></button>
        </form>
    </div>
</div>
