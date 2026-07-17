<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Nearby Shops</h1>
    <button class="btn btn-sm btn-outline-primary" onclick="window.openLocationPicker&&openLocationPicker()"><i class="bi bi-geo-alt me-1"></i><?= $location ? esc($location['label']) : 'Set location' ?></button>
</div>

<?php if (! $location): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>Set your location to see shops that deliver to you. Showing all active shops below.</div>
<?php endif; ?>

<div class="row g-3">
    <?php foreach ($shops as $s): ?>
        <div class="col-md-6 col-lg-4"><div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-1">
                <h2 class="h6 mb-0"><?= esc($s['name']) ?></h2>
                <div class="text-end">
                    <?php if (isset($s['eta_min'])): ?><span class="badge st-eta d-block mb-1"><i class="bi bi-clock me-1"></i>~<?= (int) $s['eta_min'] ?> min</span><?php endif; ?>
                    <?php if (isset($s['distance_km'])): ?><span class="badge bg-success-subtle text-success"><?= esc($s['distance_km']) ?> km</span><?php endif; ?>
                </div>
            </div>
            <div class="text-secondary small mb-2"><?= esc($s['vendor'] ?? '') ?> · <?= esc($s['business_type'] ?? '') ?></div>
            <ul class="list-inline small text-secondary mb-2">
                <li class="list-inline-item"><i class="bi bi-geo me-1"></i><?= esc($s['pincode']) ?></li>
                <?php if (isset($s['min_order_value']) && (float) $s['min_order_value'] > 0): ?><li class="list-inline-item"><i class="bi bi-basket me-1"></i>Min ₹<?= esc(number_format((float) $s['min_order_value'], 0)) ?></li><?php endif; ?>
            </ul>
            <div class="small mb-3">
                <?php if (! empty($s['free_delivery_above'])): ?>
                    <span class="text-success"><i class="bi bi-truck me-1"></i>Free delivery over ₹<?= esc(number_format((float) $s['free_delivery_above'], 0)) ?></span>
                <?php elseif (isset($s['delivery_fee'])): ?>
                    <span class="text-secondary"><i class="bi bi-truck me-1"></i>₹<?= esc(number_format((float) $s['delivery_fee'], 0)) ?> delivery</span>
                <?php endif; ?>
            </div>
            <a href="<?= site_url('store/shop/' . $s['id']) ?>" class="btn btn-sm btn-primary">Visit shop</a>
        </div></div></div>
    <?php endforeach; ?>
    <?php if (empty($shops)): ?>
        <div class="col-12 st-empty"><i class="bi bi-shop"></i><?= $location ? 'No shops deliver to ' . esc($location['label']) . ' yet.' : 'No active shops found.' ?><div class="mt-2"><a href="<?= site_url('store/location') ?>" class="btn btn-sm btn-outline-primary">Change location</a></div></div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
