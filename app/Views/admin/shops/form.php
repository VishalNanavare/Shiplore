<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/select2/select2.min.css') ?>">
<link rel="stylesheet" href="<?= asset('plugins/select2/select2-bootstrap-5-theme.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $isEdit = $shop !== null; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<?php if ($isEdit && !empty($shop['latitude']) && !empty($shop['longitude'])): ?>
<script>window.__mapInitLat = <?= (float) $shop['latitude'] ?>; window.__mapInitLng = <?= (float) $shop['longitude'] ?>;</script>
<?php endif; ?>

<div class="row"><div class="col-lg-9">
    <div class="card"><div class="card-body p-4">
        <form method="post" action="<?= $isEdit ? site_url('admin/shops/' . $shop['id'] . '/update') : site_url('admin/shops/store') ?>">
            <?= csrf_field() ?>
            <div class="row g-3">
                <?php if (! $isEdit): ?>
                <div class="col-md-6"><label class="form-label">Vendor <span class="text-danger">*</span></label>
                    <select id="fVendor" name="vendor_id" class="form-select" required>
                        <option value="">Choose a vendor…</option>
                        <?php foreach ($vendors as $v): ?><option value="<?= esc($v['id'], 'attr') ?>" <?= (int) old('vendor_id') === (int) $v['id'] ? 'selected' : '' ?>><?= esc($v['display_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label">Shop Code <span class="text-danger">*</span></label><input name="code" class="form-control" value="<?= esc(old('code'), 'attr') ?>" required></div>
                <?php endif; ?>
                <div class="col-md-<?= $isEdit ? '6' : '3' ?>"><label class="form-label">Shop Name <span class="text-danger">*</span></label><input name="name" class="form-control" value="<?= esc($isEdit ? $shop['name'] : old('name'), 'attr') ?>" required></div>
                <div class="col-md-6"><label class="form-label">GSTIN</label><input name="gstin" class="form-control text-uppercase" maxlength="15" value="<?= esc($isEdit ? ($shop['gstin'] ?? '') : old('gstin'), 'attr') ?>" <?= $isEdit ? 'readonly' : '' ?>></div>

                <?php if (($mapsKey ?? '') !== ''): ?>
                <div class="col-12">
                    <label class="form-label">Shop location</label>
                    <input id="mapSearch" class="form-control mb-2" placeholder="Search shop name or address…" autocomplete="off">
                    <div id="map" style="height:240px" class="rounded border mb-1"></div>
                    <div class="form-text">Search or drag the pin — the address fields below fill automatically.</div>
                </div>
                <?php endif; ?>

                <div class="col-12"><label class="form-label">Address <span class="text-danger">*</span></label><input id="regAddress" name="address" class="form-control" value="<?= esc($isEdit ? ($addr['line1'] ?? '') : old('address'), 'attr') ?>" required></div>
                <div class="col-md-4"><label class="form-label">Area</label><input id="regArea" name="area" class="form-control" value="<?= esc($isEdit ? ($addr['area'] ?? '') : old('area'), 'attr') ?>"></div>
                <div class="col-md-4"><label class="form-label">City <span class="text-danger">*</span></label><input id="regCity" name="city" class="form-control" value="<?= esc($isEdit ? ($addr['city'] ?? '') : old('city'), 'attr') ?>" required></div>
                <div class="col-md-4"><label class="form-label">State</label>
                    <select id="regState" name="state_code" class="form-select">
                        <option value="">Choose state…</option>
                        <?php foreach (($states ?? []) as $code => $name): ?>
                            <option value="<?= esc($code, 'attr') ?>" <?= (($isEdit ? ($shop['state_code'] ?? '') : old('state_code', '27')) === (string) $code) ? 'selected' : '' ?>><?= esc($name) ?> (<?= esc($code) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label">Pincode <span class="text-danger">*</span></label><input id="regPincode" name="pincode" class="form-control" maxlength="6" value="<?= esc($isEdit ? $shop['pincode'] : old('pincode'), 'attr') ?>" required></div>
                <div class="col-md-3"><label class="form-label">Latitude</label><input id="regLat" name="latitude" class="form-control" value="<?= esc($isEdit ? $shop['latitude'] : old('latitude'), 'attr') ?>"></div>
                <div class="col-md-3"><label class="form-label">Longitude</label><input id="regLng" name="longitude" class="form-control" value="<?= esc($isEdit ? $shop['longitude'] : old('longitude'), 'attr') ?>"></div>

                <div class="col-md-3"><label class="form-label">Delivery radius (km)</label><input name="delivery_radius_km" type="number" step="0.5" min="0" max="<?= esc($maxRadius ?? 5, 'attr') ?>" class="form-control" value="<?= esc($isEdit ? ($shop['delivery_radius_km'] ?? '') : old('delivery_radius_km'), 'attr') ?>"><div class="form-text">Max <?= esc(rtrim(rtrim(number_format((float) ($maxRadius ?? 5), 2), '0'), '.')) ?> km. Blank = use platform max; 0 = off.</div></div>
                <div class="col-md-3"><label class="form-label">Prep time (min)</label><input name="prep_time_min" class="form-control" value="<?= esc($isEdit ? ($shop['prep_time_min'] ?? '') : old('prep_time_min'), 'attr') ?>"></div>
                <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="pickup_enabled" id="pe" value="1" <?= ($isEdit && $shop['pickup_enabled']) ? 'checked' : '' ?>><label class="form-check-label" for="pe">Pickup enabled</label></div></div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= $isEdit ? 'Update shop' : 'Create shop' ?></button>
                <a href="<?= site_url('admin/shops') ?>" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div></div>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/select2/select2.min.js') ?>"></script>
<script>
$(function () {
    var el = document.getElementById('fVendor');
    if (el && $.fn.select2) {
        $(el).select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'Search vendor…',
            allowClear: true
        });
    }
});
</script>
<?= $this->include('partials/_shop_location_scripts', ['mapsKey' => $mapsKey ?? '', 'stateNameToCode' => $stateNameToCode ?? []]) ?>
<?= $this->endSection() ?>
