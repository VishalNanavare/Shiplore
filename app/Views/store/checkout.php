<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php
$canPlace = empty($undeliverable);
$addrLine = $location['formatted'] ?? trim((string) ($location['line1'] ?? '') . ', ' . (string) ($location['city'] ?? '') . ' ' . (string) ($location['pincode'] ?? ''), ' ,');
?>
<nav class="small mb-2 text-secondary"><a href="<?= site_url('store/checkout') ?>" class="text-decoration-none">Delivery address</a> › Payment</nav>
<h1 class="h4 mb-3">Payment</h1>

<form method="post" action="<?= site_url('store/checkout/place') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="checkout_token" value="<?= esc($checkoutToken ?? '', 'attr') ?>">
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card"><div class="card-body">
                <h2 class="h6 mb-3">Select payment method</h2>
                <label class="st-pay-opt">
                    <input class="form-check-input me-2" type="radio" name="method" value="cod" checked>
                    <i class="bi bi-cash-coin me-2 text-success fs-5"></i><span>Cash on Delivery</span>
                </label>
                <?php foreach ($gateways as $g): ?>
                    <label class="st-pay-opt">
                        <input class="form-check-input me-2" type="radio" name="method" value="<?= esc($g['provider'], 'attr') ?>">
                        <i class="bi bi-credit-card-2-front me-2 text-primary fs-5"></i><span><?= esc($gwLabels[$g['provider']] ?? $g['provider']) ?></span>
                    </label>
                <?php endforeach; ?>
                <?php if (empty($gateways)): ?><div class="text-secondary small mt-1">Online payment unavailable — COD only.</div><?php endif; ?>
            </div></div>
        </div>

        <div class="col-lg-5">
            <!-- Delivery address summary -->
            <div class="card mb-3"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <h2 class="h6 mb-1"><i class="bi bi-geo-alt-fill text-primary me-1"></i>Delivery address</h2>
                    <a href="<?= site_url('store/checkout') ?>" class="small text-decoration-none">Change</a>
                </div>
                <div class="fw-semibold"><?= esc($location['label'] ?? 'Delivery') ?></div>
                <div class="text-secondary small"><?= esc($addrLine) ?></div>
                <?php if (($location['name'] ?? '') !== ''): ?>
                    <div class="text-secondary small mt-1"><i class="bi bi-person me-1"></i><?= esc($location['name']) ?><?php if (($location['phone'] ?? '') !== ''): ?> · <?= esc($location['phone']) ?><?php endif; ?></div>
                <?php endif; ?>
                <?php if (! empty($undeliverable)): ?>
                    <div class="alert alert-warning mt-3 mb-0 py-2 small">
                        <i class="bi bi-truck me-1"></i><strong>Can't be delivered to this address:</strong>
                        <ul class="mb-1 mt-1 ps-3">
                            <?php foreach ($undeliverable as $u): ?>
                                <li><?= esc($u['title']) ?> — <?= esc($u['text']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="<?= site_url('store/cart') ?>" class="alert-link">Update cart</a> or <a href="<?= site_url('store/checkout') ?>" class="alert-link">change address</a>.
                    </div>
                <?php endif; ?>
            </div></div>

            <!-- Order summary -->
            <div class="card"><div class="card-body">
                <h2 class="h6 mb-3">Order summary</h2>
                <?php foreach ($items as $it): ?>
                    <div class="d-flex justify-content-between small mb-1"><span class="text-truncate"><?= esc($it['title']) ?> × <?= esc($it['qty']) ?></span><span>₹<?= esc(number_format((float) $it['line_total'], 2)) ?></span></div>
                <?php endforeach; ?>
                <hr class="my-2">
                <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">Subtotal</span><span>₹<?= esc($totals['subtotal']) ?></span></div>
                <?php if ((float) $totals['discount'] > 0): ?><div class="d-flex justify-content-between small mb-1 text-success"><span>Discount</span><span>−₹<?= esc($totals['discount']) ?></span></div><?php endif; ?>
                <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">Delivery</span><span><?= (float) $totals['delivery'] > 0 ? '₹' . esc($totals['delivery']) : 'Free' ?></span></div>
                <hr class="my-2"><div class="d-flex justify-content-between fw-semibold"><span>Total</span><span class="text-primary">₹<?= esc($totals['grand']) ?></span></div>
                <button class="btn btn-success w-100 mt-3 fw-semibold" <?= $canPlace ? '' : 'disabled' ?>><i class="bi bi-check2-circle me-1"></i>Place order · ₹<?= esc($totals['grand']) ?></button>
                <?php if (! $canPlace): ?><div class="text-danger small mt-2 text-center">Remove the items above or change your address to continue.</div><?php endif; ?>
            </div></div>
        </div>
    </div>
</form>
<?= $this->endSection() ?>
