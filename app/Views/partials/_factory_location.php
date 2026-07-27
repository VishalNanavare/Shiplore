<?php
/**
 * Shared factory/unit-location fields for the MANUFACTURER surfaces: Google Map picker
 * + address + GST state dropdown. Drop inside a `.row g-3`.
 * Expects: $mapsKey (string), $states (code=>name).
 *
 * This is a sibling of partials/_shop_location.php, NOT a variant of it. It is
 * deliberately identical down to the field ids (the shared map JS binds to them) but
 * omits the last two blocks that partial ends with:
 *
 *   - the `delivery_enabled` checkbox ("Deliver to nearby area only")
 *   - the `delivery_radius` input, and its show/hide script
 *
 * Requirement: a manufacturer cannot set a delivery range. There is nowhere to store
 * one either — `mshops` has no delivery_radius_km / delivery_polygon / pickup_enabled
 * columns, unlike `shops`.
 *
 * _shop_location.php is intentionally left untouched: it is included by /register,
 * admin/vendors/form and vendor/shops/new, and editing it would change all three.
 */
?>
<div class="col-12">
    <label class="form-label">Unit location</label>
    <?php if (($mapsKey ?? '') !== ''): ?>
        <input id="mapSearch" class="form-control mb-2" placeholder="Search factory name or address…" autocomplete="off">
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
<div class="col-md-4"><label class="form-label">Pincode <span class="text-danger">*</span></label><input id="regPincode" name="pincode" class="form-control" maxlength="6" value="<?= esc(old('pincode'), 'attr') ?>" required></div>
<div class="col-md-4"><label class="form-label">Latitude <span class="text-danger">*</span></label><input id="regLat" name="latitude" class="form-control" value="<?= esc(old('latitude'), 'attr') ?>" placeholder="19.0760" required></div>
<div class="col-md-4"><label class="form-label">Longitude <span class="text-danger">*</span></label><input id="regLng" name="longitude" class="form-control" value="<?= esc(old('longitude'), 'attr') ?>" placeholder="72.8777" required></div>
