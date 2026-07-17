<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<h1 class="h4 mb-3">Your Cart</h1>
<?php if (empty($items)): ?>
    <div class="st-empty"><i class="bi bi-bag"></i>Your cart is empty.<div class="mt-2"><a href="<?= site_url('store/products') ?>" class="btn btn-sm btn-primary">Start shopping</a></div></div>
<?php else: ?>
<div class="row g-3">
    <div class="col-lg-8"><div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>Item</th><th>Price</th><th style="width:140px">Qty</th><th class="text-end">Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td><a href="<?= site_url('store/product/' . $it['slug']) ?>" class="text-reset text-decoration-none fw-medium"><?= esc($it['title']) ?></a><div class="text-secondary small"><?= esc($it['sku']) ?></div></td>
                <td>₹<?= esc(number_format((float) $it['price'], 2)) ?></td>
                <td>
                    <form method="post" action="<?= site_url('store/cart/update') ?>" class="d-flex gap-1"><?= csrf_field() ?>
                        <input type="hidden" name="variant_id" value="<?= esc($it['variant_id'], 'attr') ?>">
                        <input name="qty" type="number" min="0" value="<?= esc($it['qty'], 'attr') ?>" class="form-control form-control-sm" style="width:70px">
                        <button class="btn btn-sm btn-light"><i class="bi bi-arrow-repeat"></i></button>
                    </form>
                </td>
                <td class="text-end">₹<?= esc(number_format((float) $it['line_total'], 2)) ?></td>
                <td class="text-end"><form method="post" action="<?= site_url('store/cart/remove') ?>"><?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= esc($it['variant_id'], 'attr') ?>"><button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div></div>
    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
            <h2 class="h6 mb-3">Summary</h2>
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">Subtotal</span><span>₹<?= esc($totals['subtotal']) ?></span></div>
            <?php if ((float) $totals['discount'] > 0): ?><div class="d-flex justify-content-between small mb-1 text-success"><span>Discount (<?= esc($coupon['code'] ?? '') ?>)</span><span>−₹<?= esc($totals['discount']) ?></span></div><?php endif; ?>
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">incl. GST</span><span>₹<?= esc($totals['tax']) ?></span></div>
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">Delivery</span><span><?= (float) $totals['delivery'] > 0 ? '₹' . esc($totals['delivery']) : 'Free' ?></span></div>
            <hr class="my-2"><div class="d-flex justify-content-between fw-semibold"><span>Total</span><span class="text-primary">₹<?= esc($totals['grand']) ?></span></div>
            <a href="<?= site_url('store/checkout') ?>" class="btn btn-primary w-100 mt-3"><i class="bi bi-lock me-1"></i>Checkout</a>
        </div></div>
        <div class="card"><div class="card-body">
            <form method="post" action="<?= site_url('store/cart/coupon') ?>" class="input-group"><?= csrf_field() ?>
                <input name="code" class="form-control" placeholder="Coupon code" value="<?= esc($coupon['code'] ?? '', 'attr') ?>">
                <button class="btn btn-outline-primary">Apply</button>
            </form>
            <div class="form-text">Try <code>FEST20</code>.</div>
        </div></div>
    </div>
</div>
<?php endif; ?>
<?= $this->endSection() ?>
