<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<nav class="small mb-2"><a href="<?= site_url('store/shops') ?>" class="text-decoration-none">Shops</a> › <?= esc($shop['name']) ?></nav>

<div class="card mb-4"><div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
        <h1 class="h4 mb-1"><?= esc($shop['name']) ?></h1>
        <div class="text-secondary small"><?= esc($shop['vendor'] ?? '') ?> · <?= esc($shop['business_type'] ?? '') ?></div>
        <?php
        $loc = service('locationService')->get();
        $eta = null;
        if ($loc && $shop['latitude'] !== null && $shop['longitude'] !== null) {
            $d   = \App\Libraries\Store\LocationService::distanceKm((float) $loc['lat'], (float) $loc['lng'], (float) $shop['latitude'], (float) $shop['longitude']);
            $eta = \App\Libraries\Store\LocationService::etaMinutes($d, $shop['prep_time_min'] !== null ? (int) $shop['prep_time_min'] : null);
        }
        ?>
        <ul class="list-inline small text-secondary mt-1 mb-0">
            <li class="list-inline-item"><i class="bi bi-geo me-1"></i><?= esc($shop['pincode']) ?> (<?= esc($shop['state_code']) ?>)</li>
            <?php if ($eta !== null): ?><li class="list-inline-item"><span class="badge st-eta"><i class="bi bi-clock me-1"></i>~<?= (int) $eta ?> min</span></li><?php endif; ?>
            <?php if (isset($shop['min_order_value']) && (float) $shop['min_order_value'] > 0): ?><li class="list-inline-item"><i class="bi bi-basket me-1"></i>Min ₹<?= esc(number_format((float) $shop['min_order_value'], 0)) ?></li><?php endif; ?>
            <?php if (! empty($shop['free_delivery_above'])): ?><li class="list-inline-item text-success"><i class="bi bi-truck me-1"></i>Free over ₹<?= esc(number_format((float) $shop['free_delivery_above'], 0)) ?></li>
            <?php elseif (isset($shop['delivery_fee'])): ?><li class="list-inline-item"><i class="bi bi-truck me-1"></i>₹<?= esc(number_format((float) $shop['delivery_fee'], 0)) ?> delivery</li><?php endif; ?>
            <?php if (! empty($shop['pickup_enabled'])): ?><li class="list-inline-item"><span class="badge bg-info-subtle text-info">Pickup available</span></li><?php endif; ?>
        </ul>
    </div>
    <span class="badge bg-success-subtle text-success fs-6">Open</span>
</div></div>

<?php
// Active filters, keyed by query-string name (shared by sidebar + results).
$applied = array_filter([
    'q'         => $q ?? '',
    'category'  => $activeCat ?? '',
    'brand'     => $activeBrand ?? '',
    'type'      => $activeType ?? '',
    'min_price' => $minPrice ?? '',
    'max_price' => $maxPrice ?? '',
    'in_stock'  => ! empty($inStock) ? 1 : '',
    'on_offer'  => ! empty($onOffer) ? 1 : '',
    'sort'      => $sort ?? '',
], static fn ($v) => $v !== '' && $v !== null);
$action = $action ?? site_url('store/shop/' . ($shopId ?? 0));
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="h6 mb-0">Products</h2>
    <button class="btn btn-sm btn-outline-secondary d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#filterCol"><i class="bi bi-funnel me-1"></i>Filters</button>
</div>
<div class="row g-3">
    <div class="col-lg-3">
        <div class="collapse d-lg-block" id="filterCol">
            <?= view('partials/_store_filters', compact('action', 'applied', 'catFacets', 'brandFacets', 'typeFacets', 'priceBounds')) ?>
        </div>
    </div>
    <div class="col-lg-9">
        <?= view('partials/_store_results', compact('products', 'total', 'page', 'perPage', 'applied', 'action', 'brandFacets')) ?>
    </div>
</div>
<?= $this->endSection() ?>
