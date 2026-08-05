<?= $this->extend('monline/_layout') ?>
<?= $this->section('content') ?>

<div class="mo-hero p-4 p-md-5 mb-4">
    <div class="mo-eyebrow mb-2">Wholesale marketplace</div>
    <h1 class="h3 fw-bold mb-2">Buy directly from manufacturers</h1>
    <p class="mb-1 fw-semibold">Skip the distributor markup — order at factory prices, straight from the source.</p>
    <p class="mb-0 opacity-75">Browse the full catalogue for free. Sign in with your vendor or shop-manager account to see wholesale pricing and place a purchase order.</p>
    <?php /* showPrices: gate stays server-side, never CSS — see _product_card.php below */ ?>
    <?php if (empty($isBuyer)): ?>
        <a class="btn mo-signin-cta mt-3" href="<?= site_url('login') ?>"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in to see prices</a>
        <p class="small mb-0 mt-2 opacity-75">
            Not registered yet? <a class="text-white text-decoration-underline" href="<?= site_url('register') ?>">Become a vendor</a>
            &middot; <a class="text-white text-decoration-underline" href="<?= site_url('manufacturer-register') ?>">Become a manufacturer</a>
        </p>
    <?php endif; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mo-section-title mb-0"><?= (int) $total ?> products available</h2>
    <a href="<?= site_url('monline/browse') ?>" class="small text-decoration-none">View full catalogue <i class="bi bi-arrow-right"></i></a>
</div>

<?php if (empty($products)): ?>
    <div class="card"><div class="card-body text-center text-secondary py-5">No products available yet.</div></div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($products as $p): ?>
            <div class="col-6 col-md-4 col-lg-3">
                <?= view('monline/_product_card', ['p' => $p, 'showPrices' => $showPrices]) ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?= $this->endSection() ?>
