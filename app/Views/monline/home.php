<?= $this->extend('monline/_layout') ?>
<?= $this->section('content') ?>
<?php
// Products already arrive distance-sorted when the buyer has a sort point, so the
// first slice is a genuine "nearest to you" rail — no extra controller data needed.
$rail = (! empty($nearLabel) && count($products) > 8) ? array_slice($products, 0, 10) : [];
$grid = $rail !== [] ? array_slice($products, 10) : $products;
?>

<div class="mo-hero p-4 p-md-5 mb-4">
    <div class="row align-items-center">
        <div class="col-md-8">
            <div class="mo-eyebrow mb-2">Wholesale marketplace</div>
            <h1 class="h3 fw-bold mb-2">Buy directly from manufacturers</h1>
            <p class="mb-3 opacity-75">Skip the distributor markup — order at factory prices, straight from the source. Browsing is free; sign in with your vendor or shop-manager account to see wholesale pricing and place a purchase order.</p>
            <?php if (empty($isBuyer)): ?>
                <a class="btn btn-light fw-semibold" href="<?= site_url('login') ?>"><i class="bi bi-box-arrow-in-right text-primary me-1"></i>Sign in to see prices</a>
                <a href="<?= site_url('monline/browse') ?>" class="btn btn-outline-light ms-2">Browse catalogue</a>
            <?php else: ?>
                <a class="btn btn-light fw-semibold" href="<?= site_url('monline/browse') ?>"><i class="bi bi-grid text-primary me-1"></i>Browse the catalogue</a>
                <a href="<?= site_url('monline/orders') ?>" class="btn btn-outline-light ms-2">My purchase orders</a>
            <?php endif; ?>
        </div>
        <div class="col-md-4 text-center d-none d-md-block"><i class="bi bi-buildings" style="font-size:5.5rem;opacity:.9"></i></div>
    </div>
</div>

<?php if (empty($isBuyer)): ?>
    <div class="alert mo-promptbar d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <span><i class="bi bi-lock-fill text-primary me-1"></i>Wholesale prices are visible to registered vendors and shop managers only.</span>
        <a class="btn btn-sm btn-primary" href="<?= site_url('login') ?>">Sign in</a>
    </div>
<?php endif; ?>

<h2 class="mo-section-title">Shop by category</h2>
<div class="row g-2 g-md-3 mb-4">
    <?php foreach (($navCategories ?? []) as $i => $c): ?>
        <div class="col-4 col-md-3 col-lg-2">
            <a href="<?= site_url('monline/browse') . '?category=' . rawurlencode((string) $c['slug']) ?>" class="mo-cat-tile">
                <span class="mo-cat-ico mo-cat-c<?= $i % 6 ?>"><i class="bi bi-<?= ['box-seam', 'gear', 'boxes', 'stack', 'grid-3x3-gap', 'archive'][$i % 6] ?>"></i></span>
                <div class="mo-cat-name"><?= esc($c['name']) ?></div>
                <div class="mo-cat-count"><?= (int) $c['product_count'] ?> items</div>
            </a>
        </div>
    <?php endforeach; ?>
    <?php if (empty($navCategories)): ?><div class="col-12 mo-empty"><i class="bi bi-tags"></i>No categories yet.</div><?php endif; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mo-section-title mb-0">Featured manufacturers</h2>
    <a href="<?= site_url('monline/browse') ?>" class="small text-decoration-none">View all <i class="bi bi-arrow-right"></i></a>
</div>
<div class="row g-3 mb-4">
    <?php foreach (array_slice($manufacturers ?? [], 0, 6) as $m): ?>
        <div class="col-6 col-md-4 col-lg-2"><a href="<?= site_url('monline/browse') . '?manufacturer=' . (int) $m['id'] ?>" class="card mo-mfrcard h-100 text-decoration-none">
            <div class="card-body p-3">
                <div class="mo-mfr-ico mb-2"><i class="bi bi-building"></i></div>
                <div class="fw-semibold small text-truncate"><?= esc($m['display_name']) ?></div>
                <div class="text-secondary text-truncate" style="font-size:.72rem">Manufacturer</div>
                <div class="mt-2 d-flex flex-wrap gap-1">
                    <span class="badge mo-count"><i class="bi bi-box-seam me-1"></i><?= (int) $m['product_count'] ?> products</span>
                </div>
            </div>
        </a></div>
    <?php endforeach; ?>
    <?php if (empty($manufacturers)): ?><div class="col-12 mo-empty"><i class="bi bi-buildings"></i>No manufacturers are listed yet.</div><?php endif; ?>
</div>

<?php // ---- Nearest-to-you rail (buyer with a sort point only) ---- ?>
<?php if ($rail !== []): ?>
    <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
        <h2 class="mo-section-title mb-0"><i class="bi bi-signpost-2 me-2 text-primary"></i>Nearest to <?= esc($nearLabel) ?></h2>
        <a href="<?= site_url('monline/browse') ?>" class="small text-decoration-none">View all <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="mo-rail">
        <?php foreach ($rail as $p): ?><div class="mo-rail-item"><?= view('monline/_product_card', ['p' => $p, 'showPrices' => $showPrices]) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mt-4 mb-3">
    <h2 class="mo-section-title mb-0"><?= (int) $total ?> products available</h2>
    <a href="<?= site_url('monline/browse') ?>" class="small text-decoration-none">View full catalogue <i class="bi bi-arrow-right"></i></a>
</div>
<div class="row g-2 g-md-3">
    <?php foreach ($grid as $p): ?>
        <div class="col-6 col-md-4 col-lg-3 col-xl-2"><?= view('monline/_product_card', ['p' => $p, 'showPrices' => $showPrices]) ?></div>
    <?php endforeach; ?>
    <?php if (empty($grid)): ?><div class="col-12 mo-empty"><i class="bi bi-box-seam"></i>No products are published yet.</div><?php endif; ?>
</div>

<?= $this->endSection() ?>
