<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php
$days  = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$hours = $hours ?? [];
$addr  = $address ?? [];
$hhmm  = static fn ($t) => $t ? substr((string) $t, 0, 5) : '';
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0"><i class="bi bi-shop me-1"></i>Edit shop — <?= esc($shop['name']) ?></h1>
    <a href="<?= site_url('vendor/shops') ?>" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<div class="row g-3">
    <!-- Details -->
    <div class="col-lg-6"><div class="card"><div class="card-body">
        <h2 class="h6 mb-3">Details</h2>
        <form method="post" action="<?= site_url('vendor/shops/' . $shop['id'] . '/save') ?>">
            <?= csrf_field() ?>
            <div class="mb-2"><label class="form-label small">Shop name *</label><input name="name" class="form-control form-control-sm" value="<?= esc($shop['name'], 'attr') ?>" required></div>
            <div class="row g-2">
                <div class="col-12 mb-2"><label class="form-label small">Address</label><input name="address_line" class="form-control form-control-sm" value="<?= esc($addr['line'] ?? '', 'attr') ?>"></div>
                <div class="col-md-5 mb-2"><label class="form-label small">City</label><input name="city" class="form-control form-control-sm" value="<?= esc($addr['city'] ?? '', 'attr') ?>"></div>
                <div class="col-md-4 mb-2"><label class="form-label small">Pincode</label><input name="pincode" class="form-control form-control-sm" value="<?= esc($shop['pincode'] ?? '', 'attr') ?>"></div>
                <div class="col-md-3 mb-2"><label class="form-label small">State</label><input name="state_code" maxlength="2" class="form-control form-control-sm" value="<?= esc($shop['state_code'] ?? '', 'attr') ?>"></div>
                <div class="col-md-6 mb-2"><label class="form-label small">Contact phone</label><input name="phone" class="form-control form-control-sm" value="<?= esc($addr['phone'] ?? '', 'attr') ?>"></div>
            </div>
            <hr class="my-2">
            <div class="row g-2">
                <div class="col-md-4 mb-2"><label class="form-label small">Delivery radius (km)</label><input name="delivery_radius_km" type="number" step="any" class="form-control form-control-sm" value="<?= esc($shop['delivery_radius_km'] ?? '', 'attr') ?>"></div>
                <div class="col-md-4 mb-2"><label class="form-label small">Prep time (min)</label><input name="prep_time_min" type="number" class="form-control form-control-sm" value="<?= esc($shop['prep_time_min'] ?? '', 'attr') ?>"></div>
                <div class="col-md-4 mb-2 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="pickup_enabled" id="pe" value="1" <?= ! empty($shop['pickup_enabled']) ? 'checked' : '' ?>><label class="form-check-label small" for="pe">Pickup enabled</label></div></div>
                <div class="col-md-6 mb-2"><label class="form-label small">Latitude</label><input name="latitude" class="form-control form-control-sm" value="<?= esc($shop['latitude'] ?? '', 'attr') ?>"></div>
                <div class="col-md-6 mb-2"><label class="form-label small">Longitude</label><input name="longitude" class="form-control form-control-sm" value="<?= esc($shop['longitude'] ?? '', 'attr') ?>"></div>
            </div>
            <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-check2 me-1"></i>Save details</button>
        </form>
    </div></div></div>

    <!-- Hours + holidays -->
    <div class="col-lg-6">
        <?php if (! empty($canHours)): ?>
        <div class="card mb-3"><div class="card-body">
            <h2 class="h6 mb-3"><i class="bi bi-clock me-1"></i>Opening hours</h2>
            <form method="post" action="<?= site_url('vendor/shops/' . $shop['id'] . '/hours') ?>">
                <?= csrf_field() ?>
                <table class="table table-sm align-middle mb-2">
                    <thead class="table-light"><tr><th>Day</th><th>Open</th><th>Close</th><th class="text-center">Closed</th></tr></thead>
                    <tbody>
                    <?php foreach ($days as $d => $label): $row = $hours[$d] ?? []; $closed = ! empty($row['is_closed']); ?>
                        <tr>
                            <td class="small"><?= esc($label) ?></td>
                            <td><input type="time" name="open_<?= $d ?>" class="form-control form-control-sm" value="<?= esc($hhmm($row['open_time'] ?? ''), 'attr') ?>"></td>
                            <td><input type="time" name="close_<?= $d ?>" class="form-control form-control-sm" value="<?= esc($hhmm($row['close_time'] ?? ''), 'attr') ?>"></td>
                            <td class="text-center"><input type="checkbox" class="form-check-input" name="closed_<?= $d ?>" value="1" <?= $closed ? 'checked' : '' ?>></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1"></i>Save hours</button>
            </form>
        </div></div>

        <div class="card"><div class="card-body">
            <h2 class="h6 mb-3"><i class="bi bi-calendar-x me-1"></i>Holidays</h2>
            <?php if (! empty($holidays)): ?>
                <ul class="list-group list-group-flush mb-2">
                    <?php foreach ($holidays as $h): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1">
                            <span class="small"><?= esc($h['holiday_date']) ?> <span class="text-secondary"><?= esc($h['reason'] ?? '') ?></span></span>
                            <form method="post" action="<?= site_url('vendor/shops/' . $shop['id'] . '/holidays/' . $h['id'] . '/delete') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0">&times;</button></form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <form method="post" action="<?= site_url('vendor/shops/' . $shop['id'] . '/holidays') ?>" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <div class="col-auto"><label class="form-label small">Date</label><input type="date" name="holiday_date" class="form-control form-control-sm" required></div>
                <div class="col"><label class="form-label small">Reason</label><input name="reason" class="form-control form-control-sm" placeholder="e.g. Diwali"></div>
                <div class="col-auto"><button class="btn btn-outline-primary btn-sm">Add</button></div>
            </form>
        </div></div>
        <?php else: ?>
            <div class="card"><div class="card-body text-secondary small">You don't have permission to manage opening hours for this shop.</div></div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
