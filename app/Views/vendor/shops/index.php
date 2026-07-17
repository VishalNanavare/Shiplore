<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php $statusBadge = ['active' => 'success', 'inactive' => 'secondary', 'closed_temp' => 'warning']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<?php if (! empty($isOwner)): ?>
<div class="d-flex justify-content-end mb-3">
    <a href="<?= site_url('vendor/shops/new') ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New shop</a>
</div>
<?php endif; ?>

<div class="row g-3">
    <?php foreach ($shops as $s): ?>
        <div class="col-md-6 col-xl-4"><div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div><h2 class="h6 mb-0"><?= esc($s['name']) ?></h2><div class="text-secondary small"><?= esc($s['code']) ?> · <?= esc($s['pincode']) ?></div></div>
                    <span class="badge text-bg-<?= esc($statusBadge[$s['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $s['status'])) ?></span>
                </div>
                <ul class="list-unstyled small text-secondary mb-3">
                    <li><i class="bi bi-bullseye me-1"></i>Delivery radius: <?= esc($s['delivery_radius_km'] ?? '—') ?> km</li>
                    <li><i class="bi bi-clock me-1"></i>Prep time: <?= esc($s['prep_time_min'] ?? '—') ?> min</li>
                    <li><i class="bi bi-shop me-1"></i>Pickup: <?= $s['pickup_enabled'] ? 'enabled' : 'disabled' ?></li>
                </ul>
                <div class="d-flex gap-2">
                    <a href="<?= site_url('vendor/shops/' . $s['id']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-gear me-1"></i>Manage</a>
                    <?php if ($s['status'] === 'active'): ?>
                        <form method="post" action="<?= site_url('vendor/shops/' . $s['id'] . '/close') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-warning"><i class="bi bi-pause me-1"></i>Close</button></form>
                    <?php else: ?>
                        <form method="post" action="<?= site_url('vendor/shops/' . $s['id'] . '/open') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-play me-1"></i>Open</button></form>
                    <?php endif; ?>
                </div>
            </div>
        </div></div>
    <?php endforeach; ?>
    <?php if (empty($shops)): ?>
        <div class="col-12"><div class="card"><div class="card-body text-center text-secondary py-5"><i class="bi bi-geo-alt display-6 d-block mb-2"></i>You have no shops yet.</div></div></div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
