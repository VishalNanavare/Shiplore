<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php
$statusBadge = ['active' => 'success', 'inactive' => 'secondary', 'closed_temp' => 'warning'];
$days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <a href="<?= site_url('vendor/shops') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back to shops</a>
    <div class="ms-auto d-flex flex-wrap gap-2">
        <?php if (! empty($canEditShop)): ?>
            <a href="<?= site_url('vendor/shops/' . $shop['id'] . '/edit') ?>" class="btn btn-sm btn-primary"><i class="bi bi-sliders me-1"></i>Edit settings</a>
        <?php endif; ?>
        <?php if (($shop['status'] ?? '') === 'active'): ?>
            <form method="post" action="<?= site_url('vendor/shops/' . $shop['id'] . '/close') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-warning"><i class="bi bi-pause me-1"></i>Close temporarily</button></form>
        <?php else: ?>
            <form method="post" action="<?= site_url('vendor/shops/' . $shop['id'] . '/open') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-play me-1"></i>Open</button></form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h5 mb-0"><?= esc($shop['name']) ?></h2>
                <span class="badge text-bg-<?= esc($statusBadge[$shop['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $shop['status'])) ?></span>
            </div>
            <dl class="row mb-0 small">
                <dt class="col-5 text-secondary">Code</dt><dd class="col-7"><?= esc($shop['code']) ?></dd>
                <dt class="col-5 text-secondary">Pincode</dt><dd class="col-7"><?= esc($shop['pincode']) ?> (<?= esc($shop['state_code']) ?>)</dd>
                <dt class="col-5 text-secondary">Delivery radius</dt><dd class="col-7"><?= esc($shop['delivery_radius_km'] ?? '—') ?> km</dd>
                <dt class="col-5 text-secondary">Prep time</dt><dd class="col-7"><?= esc($shop['prep_time_min'] ?? '—') ?> min</dd>
                <dt class="col-5 text-secondary">Pickup</dt><dd class="col-7"><?= $shop['pickup_enabled'] ? 'Enabled' : 'Disabled' ?></dd>
                <dt class="col-5 text-secondary">GST</dt><dd class="col-7"><?= esc($shop['gstin'] ?? '—') ?> <span class="badge bg-light text-secondary border"><?= esc($shop['gstin_status']) ?></span></dd>
            </dl>
        </div></div>
    </div>
    <div class="col-lg-7"><div class="card"><div class="card-header fw-semibold">Opening Hours</div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Day</th><th>Open</th><th>Close</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($hours as $h): ?>
                <tr><td><?= esc($days[(int) $h['day_of_week']] ?? $h['day_of_week']) ?></td>
                    <td><?= esc(substr((string) ($h['open_time'] ?? ''), 0, 5) ?: '—') ?></td>
                    <td><?= esc(substr((string) ($h['close_time'] ?? ''), 0, 5) ?: '—') ?></td>
                    <td><?= $h['is_closed'] ? '<span class="badge bg-danger-subtle text-danger">Closed</span>' : '<span class="badge bg-success-subtle text-success">Open</span>' ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($hours)): ?><tr><td colspan="4" class="text-center text-secondary py-3">No hours configured — open 24/7 by default.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div></div>
</div>

<div class="row g-3 mt-1">
    <div class="col-12"><div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-people me-1"></i>Staff at this shop</span>
            <?php if (! empty($canManageStaff)): ?>
                <div class="d-flex gap-2">
                    <a href="<?= site_url('vendor/staff/new?shop=' . $shop['id']) ?>" class="btn btn-sm btn-primary"><i class="bi bi-person-plus me-1"></i>Add staff</a>
                    <a href="<?= site_url('vendor/staff') ?>" class="btn btn-sm btn-outline-secondary">Manage all</a>
                </div>
            <?php endif; ?>
        </div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Name</th><th>Role</th><th>Contact</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach (($staff ?? []) as $st): ?>
                <tr>
                    <td><?= esc($st['name'] ?: '—') ?><?= ! empty($st['is_primary']) ? ' <span class="badge bg-primary-subtle text-primary">Primary</span>' : '' ?></td>
                    <td class="text-capitalize"><?= esc(str_replace('_', ' ', (string) $st['staff_type'])) ?><?= ! empty($st['designation']) ? ' · ' . esc($st['designation']) : '' ?></td>
                    <td class="small"><?= esc($st['email'] ?: ($st['phone'] ?: '—')) ?></td>
                    <td><span class="badge text-bg-<?= ($st['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>"><?= esc($st['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($staff)): ?>
                <tr><td colspan="4" class="text-center text-secondary py-3">No staff assigned to this shop yet.<?= ! empty($canManageStaff) ? ' Use "Add staff".' : '' ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </div></div>
</div>
<?= $this->endSection() ?>
