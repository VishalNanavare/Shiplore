<?= $this->extend('monline/_layout') ?>
<?= $this->section('content') ?>
<?php
// Products already arrive distance-sorted when the buyer has a sort point, so the
// first slice is a genuine "nearest to you" rail. The threshold must exceed the
// slice, or a catalogue of 9-12 leaves the rail holding everything and the grid
// below it empty.
$rail = (! empty($nearLabel) && count($products) > 12) ? array_slice($products, 0, 10) : [];
$grid = $rail !== [] ? array_slice($products, 10) : $products;

// Manufacturers register before they list products, so there is a real window where
// there are suppliers but nothing published. Lead with them rather than with an
// empty product grid.
$leadWithMfrs = ($products === [] && ($manufacturers ?? []) !== []);
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

<?php // ---- Featured manufacturers, promoted when there is nothing published yet ---- ?>
<?php if ($leadWithMfrs): ?>
    <?= view('monline/_manufacturer_grid', ['manufacturers' => $manufacturers, 'heading' => 'Manufacturers now onboarding']) ?>
<?php endif; ?>

<?php // ---- Evergreen: always has content, so the page is never bare ---- ?>
<h2 class="mo-section-title">How wholesale on <?= esc(service('settingsRepository')->brandName()) ?> works</h2>
<div class="row g-3 mb-4">
    <?php foreach ([
        ['search', 'Find factory-direct supply', 'Browse manufacturer catalogues by category, SKU or brand — sorted nearest to your shop.'],
        ['file-earmark-text', 'Raise a purchase order', 'Order at wholesale prices with MOQ and GST handled. One PO per manufacturer, automatically.'],
        ['box-arrow-in-down', 'Receive and stock up', 'Mark the delivery received and the quantity lands in your shop\'s stock, ready to sell.'],
    ] as $i => [$ico, $h, $b]): ?>
        <div class="col-md-4"><div class="card h-100 border-0 shadow-sm"><div class="card-body">
            <span class="mo-cat-ico mo-cat-c<?= $i ?>"><i class="bi bi-<?= $ico ?>"></i></span>
            <div class="fw-semibold mb-1"><?= esc($h) ?></div>
            <p class="small text-secondary mb-0"><?= esc($b) ?></p>
        </div></div></div>
    <?php endforeach; ?>
</div>

<?php // ---- Sections below render ONLY when they have content. An empty shell with a
      //      grey icon in it reads as broken; absence reads as "not that kind of page". ---- ?>
<?php if (($navCategories ?? []) !== []): ?>
    <h2 class="mo-section-title">Shop by category</h2>
    <div class="row g-2 g-md-3 mb-4">
        <?php foreach ($navCategories as $i => $c): ?>
            <div class="col-4 col-md-3 col-lg-2">
                <a href="<?= site_url('monline/browse') . '?category=' . rawurlencode((string) $c['slug']) ?>" class="mo-cat-tile">
                    <span class="mo-cat-ico mo-cat-c<?= $i % 6 ?>"><i class="bi bi-<?= ['nut', 'cpu', 'droplet', 'hammer', 'basket3', 'thermometer-half'][$i % 6] ?>"></i></span>
                    <div class="mo-cat-name"><?= esc($c['name']) ?></div>
                    <div class="mo-cat-count"><?= (int) $c['product_count'] ?> items</div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (! $leadWithMfrs && ($manufacturers ?? []) !== []): ?>
    <?= view('monline/_manufacturer_grid', ['manufacturers' => $manufacturers, 'heading' => 'Featured manufacturers']) ?>
<?php endif; ?>

<?php if ($rail !== []): ?>
    <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
        <h2 class="mo-section-title mb-0"><i class="bi bi-signpost-2 me-2 text-primary"></i>Nearest to <?= esc($nearLabel) ?></h2>
        <a href="<?= site_url('monline/browse') ?>" class="small text-decoration-none">View all <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="mo-rail">
        <?php foreach ($rail as $p): ?><div class="mo-rail-item"><?= view('monline/_product_card', ['p' => $p, 'showPrices' => $showPrices]) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($products !== []): ?>
    <div class="d-flex justify-content-between align-items-center mt-4 mb-3">
        <h2 class="mo-section-title mb-0"><?= $rail !== [] ? 'More from the catalogue' : 'Wholesale catalogue' ?></h2>
        <div>
            <span class="small text-secondary me-2"><?= number_format((int) $total) ?> products</span>
            <a href="<?= site_url('monline/browse') ?>" class="small text-decoration-none">View full catalogue <i class="bi bi-arrow-right"></i></a>
        </div>
    </div>
    <div class="row g-2 g-md-3">
        <?php foreach ($grid as $p): ?>
            <div class="col-6 col-md-4 col-lg-3 col-xl-2"><?= view('monline/_product_card', ['p' => $p, 'showPrices' => $showPrices]) ?></div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <?php // The ONE empty state on this page — a designed panel with a way forward,
          // not a grey icon and a full stop. showPrices is irrelevant here: there is
          // nothing priced to gate. ?>
    <div class="mo-empty mo-empty-card">
        <i class="bi bi-buildings"></i>
        <div class="fw-semibold text-dark mb-1 fs-6">The wholesale catalogue is opening for listings</div>
        <p class="small mb-3 mx-auto" style="max-width:36rem">
            Manufacturers are onboarding now. Register your factory to be among the first suppliers shops can order from — or browse the retail storefront meanwhile.
        </p>
        <a href="<?= site_url('manufacturer-register') ?>" class="btn btn-sm btn-primary">Become a manufacturer</a>
        <a href="<?= site_url('/') ?>" class="btn btn-sm btn-link">Shop the retail storefront</a>
    </div>
<?php endif; ?>

<?= $this->endSection() ?>
