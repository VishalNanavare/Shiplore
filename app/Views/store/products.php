<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
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
$action = $action ?? site_url('store/products');
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h5 mb-0"><?= esc($title) ?></h1>
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
