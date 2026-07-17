<?= $this->extend('layouts/rider') ?>

<?= $this->section('content') ?>
<?php $stops = $route['stops']; ?>
<div class="d-flex justify-content-between align-items-center mb-2">
    <h1 class="h6 mb-0"><i class="bi bi-signpost-split me-1"></i>My route</h1>
    <span class="rd-badge"><?= count($stops) ?> stop<?= count($stops) === 1 ? '' : 's' ?> · <?= esc(number_format((float) $route['total_km'], 1)) ?> km</span>
</div>

<?php if (! $hasOrigin): ?>
    <div class="alert alert-warning py-2 small"><i class="bi bi-geo-alt me-1"></i>Allow location access so the route can start from where you are. Showing best order from the first stop for now.</div>
<?php endif; ?>

<?php if (empty($stops)): ?>
    <div class="rd-card p-4 text-center text-secondary"><i class="bi bi-check2-circle fs-3 d-block mb-2"></i>No active trips to route.</div>
<?php else: ?>
    <?php if ($mapsUrl): ?>
        <a href="<?= esc($mapsUrl, 'attr') ?>" target="_blank" class="btn btn-rd w-100 mb-3"><i class="bi bi-map me-1"></i>Open full route in Maps</a>
    <?php endif; ?>

    <?php foreach ($stops as $s): ?>
    <div class="rd-card p-3 mb-2">
        <div class="d-flex">
            <div class="me-2 text-center" style="min-width:34px">
                <span class="rd-pin d-inline-flex align-items-center justify-content-center" style="width:30px;height:30px"><?= (int) $s['seq'] ?></span>
                <?php if ($s['leg_km'] !== null): ?><div class="small text-secondary mt-1"><?= esc(number_format((float) $s['leg_km'], 1)) ?>km</div><?php endif; ?>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex justify-content-between">
                    <a href="<?= site_url('rider/deliveries/' . $s['delivery_id']) ?>" class="fw-semibold text-decoration-none"><?= esc($s['order_no'] ?? ('#' . $s['delivery_id'])) ?></a>
                    <span class="rd-badge st-<?= esc($s['status'], 'attr') ?>"><?= esc(str_replace('_', ' ', (string) $s['status'])) ?></span>
                </div>
                <div class="small"><?= esc($s['name'] ?? '—') ?></div>
                <div class="small text-secondary"><?= esc($s['address'] ?? '') ?></div>
                <div class="mt-1 d-flex gap-2">
                    <?php if ((float) $s['cod_amount'] > 0): ?><span class="rd-badge rd-cod">COD ₹<?= esc(number_format((float) $s['cod_amount'], 0)) ?></span><?php endif; ?>
                    <?php if (! empty($s['phone'])): ?><a href="tel:<?= esc(preg_replace('/[^0-9+]/', '', (string) $s['phone']), 'attr') ?>" class="small text-decoration-none"><i class="bi bi-telephone me-1"></i>Call</a><?php endif; ?>
                    <?php if ($s['lat'] && $s['lng']): ?><a href="https://www.google.com/maps/dir/?api=1&destination=<?= esc($s['lat'], 'attr') ?>,<?= esc($s['lng'], 'attr') ?>&travelmode=driving" target="_blank" class="small text-decoration-none"><i class="bi bi-signpost-2 me-1"></i>Navigate</a><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
<?= $this->endSection() ?>
