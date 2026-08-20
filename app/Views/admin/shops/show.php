<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$sstatus = (string) ($shop['status'] ?? '');
$sBadge  = ['active' => 'success', 'open' => 'success', 'inactive' => 'secondary', 'closed_temp' => 'warning'][$sstatus] ?? 'secondary';
$gBadge  = ['verified' => 'success', 'pending' => 'warning', 'failed' => 'danger', 'unverified' => 'secondary'][(string) ($shop['gstin_status'] ?? 'unverified')] ?? 'secondary';
$money   = static fn ($n): string => '₹' . number_format((float) $n, 2);
$lat     = (float) ($shop['latitude'] ?? 0);
$lng     = (float) ($shop['longitude'] ?? 0);
$hasGeo  = $lat !== 0.0 && $lng !== 0.0;
$tile    = static function (string $icon, string $label, string $value, string $tone): string {
    return '<div class="col"><div class="card h-100"><div class="card-body py-3">'
        . '<div class="d-flex align-items-center gap-2 text-' . $tone . '"><i class="bi ' . $icon . ' fs-5"></i>'
        . '<span class="text-secondary small text-uppercase" style="letter-spacing:.03em">' . esc($label) . '</span></div>'
        . '<div class="h4 mb-0 mt-1 fw-semibold">' . $value . '</div></div></div></div>';
};
$isActive = in_array($sstatus, ['active', 'open'], true);
?>
<?php if ($f = session('success')): ?><div class="alert alert-success"><?= esc($f) ?></div><?php endif; ?>
<?php if ($f = session('error')): ?><div class="alert alert-danger"><?= esc($f) ?></div><?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <a href="<?= site_url('admin/shops') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back to shops</a>
    <div class="d-flex flex-wrap gap-2">
        <form method="post" action="<?= site_url('admin/portal/enter/shop/' . $shop['id']) ?>" class="m-0"
              data-confirm="Open the shop portal for <?= esc($shop['name'] ?? 'this shop', 'attr') ?>? You will be signed in as its vendor until you return to admin.">
            <?= csrf_field() ?>
            <button class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-up-right me-1"></i>Go to Shop Portal</button>
        </form>
        <a href="<?= site_url('admin/shops/' . $shop['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
        <?php if ($isActive): ?>
            <form method="post" action="<?= site_url('admin/shops/' . $shop['id'] . '/deactivate') ?>" class="m-0"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-pause-circle me-1"></i>Deactivate</button></form>
        <?php else: ?>
            <form method="post" action="<?= site_url('admin/shops/' . $shop['id'] . '/activate') ?>" class="m-0"><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-play-circle me-1"></i>Activate</button></form>
        <?php endif; ?>
    </div>
</div>

<!-- Header -->
<div class="card mb-3"><div class="card-body d-flex flex-wrap align-items-center gap-3">
    <span class="rounded bg-primary-subtle text-primary d-grid" style="width:64px;height:64px;place-items:center;font-size:1.6rem"><i class="bi bi-shop"></i></span>
    <div class="me-auto">
        <h1 class="h4 mb-1"><?= esc($shop['name'] ?? '—') ?></h1>
        <div class="text-secondary small">
            Code <?= esc($shop['code'] ?? '—') ?> · #<?= (int) $shop['id'] ?>
            <?php if ($vendor): ?> · <a href="<?= site_url('admin/vendors/' . $vendor['id']) ?>" class="text-decoration-none"><i class="bi bi-building me-1"></i><?= esc($vendor['display_name']) ?></a><?php endif; ?>
        </div>
    </div>
    <div class="text-end">
        <span class="badge text-bg-<?= $sBadge ?> text-capitalize mb-1"><?= esc(str_replace('_', ' ', $sstatus)) ?></span><br>
        <span class="badge text-bg-<?= $gBadge ?>"><i class="bi bi-patch-check me-1"></i>GST <?= esc($shop['gstin_status'] ?? 'unverified') ?></span>
    </div>
</div></div>

<!-- KPI tiles -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-1">
    <?= $tile('bi-box-seam', 'Active products', (string) (int) $products, 'info') ?>
    <?= $tile('bi-bag-check', 'Total orders', (string) (int) ($orders['cnt'] ?? 0), 'primary') ?>
    <?= $tile('bi-currency-rupee', 'Revenue', $money($orders['revenue'] ?? 0), 'success') ?>
    <?= $tile('bi-truck', 'Deliveries', (string) (int) ($deliveries['cnt'] ?? 0), 'warning') ?>
</div>

<div class="row g-3 mt-0">
    <!-- Left column -->
    <div class="col-lg-6">
        <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-geo-alt me-1"></i>Location</div><div class="card-body">
            <dl class="row mb-3 small">
                <dt class="col-4 text-secondary">Address</dt><dd class="col-8"><?= esc($addr['line1'] ?? '—') ?></dd>
                <dt class="col-4 text-secondary">Area</dt><dd class="col-8"><?= esc($addr['area'] ?? '—') ?></dd>
                <dt class="col-4 text-secondary">City</dt><dd class="col-8"><?= esc($addr['city'] ?? '—') ?></dd>
                <dt class="col-4 text-secondary">State</dt><dd class="col-8"><?= esc($stateName) ?></dd>
                <dt class="col-4 text-secondary">Pincode</dt><dd class="col-8"><?= esc($shop['pincode'] ?? '—') ?></dd>
                <dt class="col-4 text-secondary">Coordinates</dt><dd class="col-8"><?= $hasGeo ? esc($lat . ', ' . $lng) : '—' ?></dd>
            </dl>
            <?php if ($hasGeo): ?>
                <iframe src="https://www.google.com/maps?q=<?= $lat ?>,<?= $lng ?>&z=15&output=embed" style="width:100%;height:220px;border:0;border-radius:.5rem" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                <a class="small d-inline-block mt-1" href="https://www.google.com/maps?q=<?= $lat ?>,<?= $lng ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Open in Google Maps</a>
            <?php else: ?>
                <div class="text-secondary small">No map coordinates set for this shop.</div>
            <?php endif; ?>
        </div></div>

        <div class="card"><div class="card-header fw-semibold"><i class="bi bi-sliders me-1"></i>Operations</div><div class="card-body">
            <dl class="row mb-0 small">
                <dt class="col-6 text-secondary">Prep time</dt><dd class="col-6"><?= $shop['prep_time_min'] !== null && $shop['prep_time_min'] !== '' ? esc($shop['prep_time_min']) . ' min' : '—' ?></dd>
                <dt class="col-6 text-secondary">Delivery radius</dt><dd class="col-6"><?= $shop['delivery_radius_km'] !== null && $shop['delivery_radius_km'] !== '' ? esc($shop['delivery_radius_km']) . ' km' : 'Platform default' ?></dd>
                <dt class="col-6 text-secondary">Pickup</dt><dd class="col-6"><?= ! empty($shop['pickup_enabled']) ? '<span class="badge text-bg-success">Enabled</span>' : '<span class="badge text-bg-secondary">Off</span>' ?></dd>
                <dt class="col-6 text-secondary">Staff assigned</dt><dd class="col-6"><?= (int) $staffCount ?></dd>
                <dt class="col-6 text-secondary">Created</dt><dd class="col-6"><?= esc(substr((string) ($shop['created_at'] ?? ''), 0, 10) ?: '—') ?></dd>
            </dl>
        </div></div>
    </div>

    <!-- Right column -->
    <div class="col-lg-6">
        <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-bag me-1"></i>Orders</div><div class="card-body">
            <div class="row text-center g-2">
                <div class="col"><div class="h5 mb-0"><?= (int) ($orders['cnt'] ?? 0) ?></div><div class="text-secondary small">Total</div></div>
                <div class="col"><div class="h5 mb-0 text-success"><?= (int) ($orders['completed'] ?? 0) ?></div><div class="text-secondary small">Completed</div></div>
                <div class="col"><div class="h5 mb-0 text-warning"><?= (int) ($orders['open'] ?? 0) ?></div><div class="text-secondary small">Open</div></div>
                <div class="col"><div class="h5 mb-0 text-danger"><?= (int) ($orders['cancelled'] ?? 0) ?></div><div class="text-secondary small">Cancelled</div></div>
            </div>
            <hr>
            <div class="d-flex justify-content-between"><span class="text-secondary">Lifetime revenue</span><span class="fw-semibold"><?= $money($orders['revenue'] ?? 0) ?></span></div>
        </div></div>

        <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-truck me-1"></i>Deliveries</div><div class="card-body">
            <div class="row text-center g-2">
                <div class="col"><div class="h5 mb-0"><?= (int) ($deliveries['cnt'] ?? 0) ?></div><div class="text-secondary small">Total</div></div>
                <div class="col"><div class="h5 mb-0 text-success"><?= (int) ($deliveries['delivered'] ?? 0) ?></div><div class="text-secondary small">Delivered</div></div>
                <div class="col"><div class="h5 mb-0 text-info"><?= (int) ($deliveries['in_transit'] ?? 0) ?></div><div class="text-secondary small">In transit</div></div>
                <div class="col"><div class="h5 mb-0 text-danger"><?= (int) ($deliveries['failed'] ?? 0) ?></div><div class="text-secondary small">Failed</div></div>
            </div>
        </div></div>

        <div class="card"><div class="card-header fw-semibold"><i class="bi bi-building me-1"></i>Vendor &amp; compliance</div><div class="card-body">
            <dl class="row mb-0 small">
                <dt class="col-5 text-secondary">Vendor</dt><dd class="col-7"><?php if ($vendor): ?><a href="<?= site_url('admin/vendors/' . $vendor['id']) ?>" class="text-decoration-none"><?= esc($vendor['display_name']) ?></a> <span class="badge text-bg-<?= ($vendor['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>"><?= esc($vendor['status']) ?></span><?php else: ?>—<?php endif; ?></dd>
                <dt class="col-5 text-secondary">GSTIN</dt><dd class="col-7"><?= esc($shop['gstin'] ?: '—') ?></dd>
                <dt class="col-5 text-secondary">GST status</dt><dd class="col-7 text-capitalize"><?= esc($shop['gstin_status'] ?? 'unverified') ?></dd>
            </dl>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
