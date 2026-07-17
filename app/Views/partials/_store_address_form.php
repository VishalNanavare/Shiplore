<?php
/**
 * _store_address_form.php — Blinkit-style map + complete-address form. Shared by
 * the checkout add-address page and the account "Add address" modal.
 *
 * @var string $action      form POST target
 * @var array  $e           address being edited (or [])
 * @var array  $profile      customer profile (name/phone prefill)
 * @var string $mapsKey     Google Maps browser key ('' = degrade to manual + geolocation)
 * @var string $submitLabel button text
 * @var string $formAttrs   extra attributes for the <form> (e.g. data-ajax-refresh)
 */
$e          = $e ?? [];
$pf         = $profile ?? [];
$submitLabel = $submitLabel ?? 'Save Address';
$formAttrs  = $formAttrs ?? '';
$labelV = old('label', $e['label'] ?? 'Home');
$line1V = old('line1', $e['line1'] ?? '');
// line2 is stored as "Floor N, landmark" — split it back for editing.
$savedFloor    = '';
$savedLandmark = (string) ($e['line2'] ?? '');
if ($savedLandmark !== '' && preg_match('/^Floor\s+(.+?)(?:,\s*(.*))?$/u', $savedLandmark, $mm)) {
    $savedFloor    = $mm[1];
    $savedLandmark = $mm[2] ?? '';
}
$floorV    = old('floor', $savedFloor);
$landmarkV = old('landmark', $savedLandmark);
$nameV  = old('name', ($e['recipient_name'] ?? '') ?: ($pf['name'] ?? ''));
$phoneV = old('phone', ($e['phone'] ?? '') ?: ($pf['phone'] ?? ''));
$cityV  = old('city', $e['city'] ?? '');
$stateV = old('state_code', $e['state_code'] ?? '');
$pinV   = old('pincode', $e['pincode'] ?? '');
$fmtV   = old('formatted', $e['formatted_address'] ?? '');
$latV   = old('lat', $e['latitude'] ?? '');
$lngV   = old('lng', $e['longitude'] ?? '');
$areaV  = $fmtV ?: trim((string) $cityV . ' ' . (string) $pinV);
?>
<form method="post" action="<?= $action ?>" id="caForm"<?= $formAttrs !== '' ? ' ' . $formAttrs : '' ?>>
    <?= csrf_field() ?>
    <?php if (! empty($e['id'])): ?><input type="hidden" name="address_id" value="<?= (int) $e['id'] ?>"><?php endif; ?>
    <input type="hidden" name="lat" id="caLat" value="<?= esc((string) $latV, 'attr') ?>">
    <input type="hidden" name="lng" id="caLng" value="<?= esc((string) $lngV, 'attr') ?>">
    <input type="hidden" name="city" id="caCity" value="<?= esc((string) $cityV, 'attr') ?>">
    <input type="hidden" name="state_code" id="caState" value="<?= esc((string) $stateV, 'attr') ?>">
    <input type="hidden" name="pincode" id="caPin" value="<?= esc((string) $pinV, 'attr') ?>">
    <input type="hidden" name="formatted" id="caFormatted" value="<?= esc((string) $fmtV, 'attr') ?>">
    <input type="hidden" name="label" id="caLabel" value="<?= esc((string) $labelV, 'attr') ?>">

    <div class="row g-0">
        <div class="col-md-6">
            <div class="p-3 pb-0">
                <div class="position-relative mb-2">
                    <span class="position-absolute top-50 translate-middle-y ms-2 text-secondary" style="left:.25rem"><i class="bi bi-search"></i></span>
                    <input id="caSearch" class="form-control ps-5" placeholder="Search area, street or landmark…" autocomplete="off">
                </div>
            </div>
            <div id="caMap" class="st-camap" data-maps="<?= $mapsKey !== '' ? '1' : '0' ?>" data-lat="<?= esc((string) $latV, 'attr') ?>" data-lng="<?= esc((string) $lngV, 'attr') ?>"></div>
            <div class="p-3">
                <button type="button" id="caCurrent" class="btn btn-outline-primary btn-sm mb-2"><i class="bi bi-crosshair me-1"></i>Go to current location</button>
                <div class="st-deliverto d-flex align-items-start gap-2">
                    <i class="bi bi-geo-alt-fill text-primary fs-5"></i>
                    <div class="min-w-0">
                        <div class="small text-secondary">Delivering your order to</div>
                        <div class="fw-semibold text-truncate" id="caAreaName"><?= esc($cityV ?: 'Pick a location') ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 border-start">
            <div class="p-3">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Save address as <span class="text-danger">*</span></label>
                    <div class="st-saveas d-flex gap-2 flex-wrap" id="caSaveAs">
                        <?php foreach (['Home' => 'house-door', 'Work' => 'briefcase', 'Hotel' => 'building', 'Other' => 'geo-alt'] as $opt => $ic): ?>
                            <button type="button" class="st-saveas-chip<?= $labelV === $opt ? ' active' : '' ?>" data-label="<?= $opt ?>"><i class="bi bi-<?= $ic ?> me-1"></i><?= $opt ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mb-2"><input name="line1" class="form-control" placeholder="Flat / House no / Building name *" value="<?= esc((string) $line1V, 'attr') ?>" required></div>
                <div class="mb-2"><input name="floor" class="form-control" placeholder="Floor (optional)" value="<?= esc((string) $floorV, 'attr') ?>"></div>
                <div class="mb-2">
                    <label class="form-label small text-secondary mb-1">Area / Sector / Locality *</label>
                    <input id="caArea" class="form-control bg-light" value="<?= esc((string) $areaV, 'attr') ?>" placeholder="Set from the map" readonly>
                </div>
                <div class="mb-3"><input name="landmark" class="form-control" placeholder="Nearby landmark (optional)" value="<?= esc((string) $landmarkV, 'attr') ?>"></div>
                <div class="text-secondary small mb-2">Enter your details for a seamless delivery experience</div>
                <div class="mb-2"><input name="name" class="form-control" placeholder="Your name *" value="<?= esc((string) $nameV, 'attr') ?>" required></div>
                <div class="mb-3"><input name="phone" class="form-control" inputmode="tel" placeholder="Your phone number (optional)" value="<?= esc((string) $phoneV, 'attr') ?>"></div>
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_default" id="caDefault" value="1" <?= ! empty($e['is_default']) ? 'checked' : '' ?>><label class="form-check-label small" for="caDefault">Set as default address</label></div>
                <button type="submit" class="btn btn-success w-100 fw-semibold" id="caSave"><i class="bi bi-check2 me-1"></i><?= esc($submitLabel) ?></button>
            </div>
        </div>
    </div>
</form>
