<?php
/**
 * _store_minicart.php — compact cart body rendered into the slide-over drawer.
 * Loaded via fetch('store/cart/mini'); its forms are data-ajax-stay so they
 * toast without navigating, and store-ux.js reloads this partial after each change.
 * @var list<array<string,mixed>> $items
 * @var array<string,string>      $totals
 * @var int                       $count
 */
?>
<div data-cart-count="<?= (int) $count ?>">
<?php if (empty($items)): ?>
    <div class="text-center text-secondary py-5">
        <i class="bi bi-bag display-6 d-block mb-2"></i>
        Your cart is empty.
        <div class="mt-3"><button type="button" class="btn btn-sm btn-primary" data-bs-dismiss="offcanvas">Start shopping</button></div>
    </div>
<?php else: ?>
    <div class="st-mini-items">
        <?php foreach ($items as $it): ?>
            <div class="d-flex gap-2 py-2 border-bottom align-items-center">
                <div class="flex-grow-1 min-w-0">
                    <a href="<?= site_url('store/product/' . $it['slug']) ?>" class="text-reset text-decoration-none fw-medium small d-block text-truncate"><?= esc($it['title']) ?></a>
                    <div class="text-secondary" style="font-size:.72rem">₹<?= esc(number_format((float) $it['price'], 0)) ?> · line ₹<?= esc(number_format((float) $it['line_total'], 0)) ?></div>
                </div>
                <div class="btn-group btn-group-sm align-items-center" role="group">
                    <?php // minus: set qty-1 (CartService removes at <=0) ?>
                    <form method="post" action="<?= site_url('store/cart/update') ?>" class="d-inline" data-ajax-stay>
                        <?= csrf_field() ?>
                        <input type="hidden" name="variant_id" value="<?= esc($it['variant_id'], 'attr') ?>">
                        <input type="hidden" name="qty" value="<?= (int) $it['qty'] - 1 ?>">
                        <button class="btn btn-outline-secondary btn-sm" title="Less"><i class="bi bi-dash"></i></button>
                    </form>
                    <span class="px-2 small fw-semibold"><?= (int) $it['qty'] ?></span>
                    <?php // plus: increment by 1 ?>
                    <form method="post" action="<?= site_url('store/cart/add') ?>" class="d-inline" data-ajax-stay>
                        <?= csrf_field() ?>
                        <input type="hidden" name="variant_id" value="<?= esc($it['variant_id'], 'attr') ?>">
                        <input type="hidden" name="qty" value="1">
                        <button class="btn btn-outline-secondary btn-sm" title="More"><i class="bi bi-plus"></i></button>
                    </form>
                </div>
                <form method="post" action="<?= site_url('store/cart/remove') ?>" class="d-inline" data-ajax-stay>
                    <?= csrf_field() ?>
                    <input type="hidden" name="variant_id" value="<?= esc($it['variant_id'], 'attr') ?>">
                    <button class="btn btn-sm btn-link text-danger p-1" title="Remove"><i class="bi bi-trash"></i></button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="mt-3 pt-2 border-top small">
        <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Subtotal</span><span>₹<?= esc($totals['subtotal']) ?></span></div>
        <?php if ((float) $totals['discount'] > 0): ?><div class="d-flex justify-content-between mb-1 text-success"><span>Discount</span><span>−₹<?= esc($totals['discount']) ?></span></div><?php endif; ?>
        <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Delivery</span><span><?= (float) $totals['delivery'] > 0 ? '₹' . esc($totals['delivery']) : 'Free' ?></span></div>
        <div class="d-flex justify-content-between fw-bold fs-6 mt-1"><span>Total</span><span class="text-primary">₹<?= esc($totals['grand']) ?></span></div>
    </div>
    <div class="d-grid gap-2 mt-3">
        <a href="<?= site_url('store/checkout') ?>" class="btn btn-primary"><i class="bi bi-lock me-1"></i>Checkout · ₹<?= esc($totals['grand']) ?></a>
        <a href="<?= site_url('store/cart') ?>" class="btn btn-outline-secondary btn-sm">View full cart</a>
    </div>
<?php endif; ?>
</div>
