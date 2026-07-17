<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success py-2"><?= esc($m) ?></div><?php endif; ?>

<div class="st-hero p-4 p-md-5 mb-4">
    <div class="row align-items-center">
        <div class="col-md-8">
            <h1 class="h3 fw-bold mb-2">Groceries, fashion &amp; more — delivered from shops near you</h1>
            <p class="mb-3 opacity-75">Set your location and discover stores that deliver to your doorstep in minutes.</p>
            <button class="btn btn-light fw-semibold" onclick="window.openLocationPicker&&openLocationPicker()">
                <i class="bi bi-geo-alt-fill text-primary me-1"></i><?= $location ? esc($location['label']) : 'Set your location' ?>
            </button>
            <a href="<?= site_url('store/shops') ?>" class="btn btn-outline-light ms-2">Browse shops</a>
        </div>
        <div class="col-md-4 text-center d-none d-md-block"><i class="bi bi-bag-heart" style="font-size:5.5rem;opacity:.9"></i></div>
    </div>
</div>

<?php if (! $location): ?>
    <div class="alert st-locbar d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <span><i class="bi bi-geo-alt-fill text-primary me-1"></i>Set your location to see what delivers to you.</span>
        <button class="btn btn-sm btn-primary" onclick="window.openLocationPicker&&openLocationPicker()">Choose location</button>
    </div>
<?php endif; ?>

<h2 class="st-section-title">Shop by category</h2>
<div class="row g-2 g-md-3 mb-4">
    <?php foreach ($categories as $i => $c): ?>
        <div class="col-4 col-md-3 col-lg-2">
            <a href="<?= site_url('store/category/' . $c['slug']) ?>" class="st-cat-tile">
                <span class="st-cat-ico st-cat-c<?= $i % 6 ?>"><i class="bi bi-<?= ['basket3', 'cup-straw', 'phone', 'bag', 'house-heart', 'flower1'][$i % 6] ?>"></i></span>
                <div class="st-cat-name"><?= esc($c['name']) ?></div>
                <?php if (isset($c['cnt'])): ?><div class="st-cat-count text-secondary" style="font-size:.7rem"><?= (int) $c['cnt'] ?> items</div><?php endif; ?>
            </a>
        </div>
    <?php endforeach; ?>
    <?php if (empty($categories)): ?><div class="col-12 st-empty"><i class="bi bi-tags"></i>No categories yet.</div><?php endif; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="st-section-title mb-0"><?= $location ? 'Shops near ' . esc($location['label']) : 'Featured shops' ?></h2>
    <a href="<?= site_url('store/shops') ?>" class="small text-decoration-none">View all <i class="bi bi-arrow-right"></i></a>
</div>
<div class="row g-3 mb-4">
    <?php foreach ($shops as $s): ?>
        <div class="col-6 col-md-4 col-lg-2"><a href="<?= site_url('store/shop/' . $s['id']) ?>" class="card st-shopcard h-100 text-decoration-none">
            <div class="card-body p-3">
                <div class="st-shop-ico mb-2"><i class="bi bi-shop"></i></div>
                <div class="fw-semibold small text-truncate"><?= esc($s['name']) ?></div>
                <div class="text-secondary text-truncate" style="font-size:.72rem"><?= esc($s['business_type'] ?? 'Store') ?></div>
                <div class="mt-2 d-flex flex-wrap gap-1">
                    <?php if (isset($s['eta_min'])): ?><span class="badge st-eta"><i class="bi bi-clock me-1"></i>~<?= (int) $s['eta_min'] ?> min</span><?php endif; ?>
                    <?php if (isset($s['distance_km'])): ?><span class="badge st-dist"><i class="bi bi-geo-alt me-1"></i><?= esc($s['distance_km']) ?> km</span><?php endif; ?>
                </div>
                <?php if (! empty($s['free_delivery_above'])): ?><div class="text-success mt-1" style="font-size:.66rem"><i class="bi bi-truck me-1"></i>Free over ₹<?= esc(number_format((float) $s['free_delivery_above'], 0)) ?></div><?php endif; ?>
            </div>
        </a></div>
    <?php endforeach; ?>
    <?php if (empty($shops)): ?><div class="col-12 st-empty"><i class="bi bi-shop"></i><?= $location ? 'No shops deliver to your location yet.' : 'Set your location to see nearby shops.' ?></div><?php endif; ?>
</div>

<?php // ---- Curated rails (horizontal, scrollable) ---- ?>
<?php foreach (($rails ?? []) as $rail): ?>
    <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
        <h2 class="st-section-title mb-0"><i class="bi bi-<?= esc($rail['icon'] ?? 'grid', 'attr') ?> me-2 text-primary"></i><?= esc($rail['title']) ?></h2>
        <a href="<?= esc($rail['link'], 'attr') ?>" class="small text-decoration-none">View all <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="st-rail">
        <?php foreach ($rail['products'] as $p): ?><div class="st-rail-item"><?= view('partials/_store_product_card', ['p' => $p]) ?></div><?php endforeach; ?>
    </div>
<?php endforeach; ?>

<h2 class="st-section-title mt-4"><?= $location ? 'Available near you' : 'Popular products' ?></h2>
<div class="row g-2 g-md-3">
    <?php foreach ($products as $p): ?><div class="col-6 col-md-4 col-lg-3 col-xl-2"><?= view('partials/_store_product_card', ['p' => $p]) ?></div><?php endforeach; ?>
    <?php if (empty($products)): ?>
        <div class="col-12 st-empty"><i class="bi bi-box-seam"></i>
            <?= $location ? 'No products deliver to your area yet — try a different location.' : 'No products available yet.' ?>
        </div>
    <?php endif; ?>
</div>

<style>
.st-rail{display:flex;gap:.75rem;overflow-x:auto;scroll-snap-type:x mandatory;padding:.25rem .1rem 1rem;-webkit-overflow-scrolling:touch}
.st-rail::-webkit-scrollbar{height:6px}
.st-rail::-webkit-scrollbar-thumb{background:#d4d7e0;border-radius:6px}
.st-rail-item{flex:0 0 auto;width:170px;scroll-snap-align:start}
@media (min-width:768px){.st-rail-item{width:200px}}
</style>
<?= $this->endSection() ?>
