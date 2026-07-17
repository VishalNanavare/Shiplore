<?php
/**
 * Shared shop-location fields: Google Map picker + address + GST state dropdown
 * + delivery radius. Drop inside a `.row g-3`. Used by /register and
 * admin/vendors/new so both stay identical.
 * Expects: $mapsKey (string), $states (code=>name), $maxRadius (float).
 */
$maxRadius = (float) ($maxRadius ?? 5);
$defRadius = (old('delivery_radius') !== null && old('delivery_radius') !== '') ? old('delivery_radius') : rtrim(rtrim(number_format($maxRadius, 2), '0'), '.');
?>
<div class="col-12">
    <label class="form-label">Shop location</label>
    <?php if (($mapsKey ?? '') !== ''): ?>
        <input id="mapSearch" class="form-control mb-2" placeholder="Search shop name or address…" autocomplete="off">
        <div id="map" style="height:240px" class="rounded border mb-1"></div>
        <div class="form-text">Search or drag the pin — the address fields below fill automatically.</div>
    <?php else: ?>
        <div class="alert alert-warning small mb-0">Map search isn't set up. Add a key in <strong>Admin → Integrations → Google Maps</strong>, or fill the fields manually.</div>
    <?php endif; ?>
</div>
<div class="col-12"><label class="form-label">Address <span class="text-danger">*</span></label><input id="regAddress" name="address" class="form-control" value="<?= esc(old('address'), 'attr') ?>" required></div>
<div class="col-md-4"><label class="form-label">Area / Locality</label><input id="regArea" name="area" class="form-control" value="<?= esc(old('area'), 'attr') ?>"></div>
<div class="col-md-4"><label class="form-label">City <span class="text-danger">*</span></label><input id="regCity" name="city" class="form-control" value="<?= esc(old('city'), 'attr') ?>" required></div>
<div class="col-md-4"><label class="form-label">State <span class="text-danger">*</span></label>
    <select id="regState" name="state_code" class="form-select" required>
        <option value="">Choose state…</option>
        <?php foreach (($states ?? []) as $code => $name): ?>
            <option value="<?= esc($code, 'attr') ?>" <?= old('state_code') === (string) $code ? 'selected' : '' ?>><?= esc($name) ?> (<?= esc($code) ?>)</option>
        <?php endforeach; ?>
    </select>
</div>
<div class="col-md-3"><label class="form-label">Pincode <span class="text-danger">*</span></label><input id="regPincode" name="pincode" class="form-control" maxlength="6" value="<?= esc(old('pincode'), 'attr') ?>" required></div>
<div class="col-md-3"><label class="form-label">Latitude <span class="text-danger">*</span></label><input id="regLat" name="latitude" class="form-control" value="<?= esc(old('latitude'), 'attr') ?>" placeholder="19.0760" required></div>
<div class="col-md-3"><label class="form-label">Longitude <span class="text-danger">*</span></label><input id="regLng" name="longitude" class="form-control" value="<?= esc(old('longitude'), 'attr') ?>" placeholder="72.8777" required></div>
<div class="col-md-3">
    <label class="form-label d-block">Nearby delivery</label>
    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="delivery_enabled" id="regDeliveryEnabled" value="1" <?= old('delivery_enabled', '1') ? 'checked' : '' ?>><label class="form-check-label small" for="regDeliveryEnabled">Deliver to nearby area only</label></div>
</div>
<div class="col-md-3" id="regRadiusWrap">
    <label class="form-label">Delivery radius (km)</label>
    <input id="regRadius" name="delivery_radius" type="number" step="0.5" min="0.5" max="<?= esc($maxRadius, 'attr') ?>" value="<?= esc($defRadius, 'attr') ?>" class="form-control">
    <div class="form-text">Max <?= esc(rtrim(rtrim(number_format($maxRadius, 2), '0'), '.')) ?> km.</div>
</div>
<script>(function () { var cb = document.getElementById('regDeliveryEnabled'), w = document.getElementById('regRadiusWrap'), r = document.getElementById('regRadius'); function t() { if (!cb || !w) { return; } w.style.display = cb.checked ? '' : 'none'; if (r) { r.required = cb.checked; } } if (cb) { cb.addEventListener('change', t); t(); } })();</script>
