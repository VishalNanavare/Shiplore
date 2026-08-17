<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php
$isEdit = ! empty($unit);
$action = $isEdit ? 'manufacturer/units/' . (int) $unit['id'] . '/update' : 'manufacturer/units/store';
$addr   = $isEdit ? (array) json_decode((string) ($unit['address_json'] ?? '{}'), true) : [];
?>

<div class="card">
    <div class="card-header"><?= $isEdit ? 'Edit Unit' : 'New Unit' ?></div>
    <div class="card-body">
        <form method="post" action="<?= site_url($action) ?>" class="row g-3">
            <?= csrf_field() ?>

            <div class="col-md-6">
                <label class="form-label">Unit name <span class="text-danger">*</span></label>
                <input name="name" class="form-control" required
                       value="<?= esc(old('name', $isEdit ? ($unit['name'] ?? '') : ''), 'attr') ?>">
                <div class="form-text">E.g. "Bhiwandi Plant 1".</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">GSTIN</label>
                <input name="gstin" class="form-control" maxlength="15"
                       value="<?= esc(old('gstin', $isEdit ? ($unit['gstin'] ?? '') : ''), 'attr') ?>">
            </div>

            <?php
            // Address only. Serviceability is a separate section below, because it is
            // separately permissioned and only meaningful once the unit exists.
            echo $this->include('partials/_factory_location');
            ?>

            <?php if ($isEdit && ! empty($canServiceability)): ?>
                <div class="col-12"><hr class="my-2"></div>
                <div class="col-12">
                    <h6 class="mb-1"><i class="bi bi-truck me-1"></i>Delivery &amp; pickup</h6>
                    <p class="small text-secondary mb-2">
                        How goods leave this unit on a dispatched purchase order. Delivery is off
                        until you turn it on.
                    </p>
                </div>

                <div class="col-md-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="delEnabled"
                               name="delivery_enabled" value="1"
                               <?= old('delivery_enabled', (string) ($unit['delivery_enabled'] ?? '0')) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="delEnabled">We deliver from this unit</label>
                    </div>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="pickEnabled"
                               name="pickup_enabled" value="1"
                               <?= old('pickup_enabled', (string) ($unit['pickup_enabled'] ?? '1')) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pickEnabled">Buyers may collect</label>
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="delRadius">Delivery radius (km)</label>
                    <input class="form-control" id="delRadius" name="delivery_radius_km" type="number" step="0.1" min="0"
                           value="<?= esc(old('delivery_radius_km', (string) ($unit['delivery_radius_km'] ?? '')), 'attr') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="prepTime">Dispatch prep time (min)</label>
                    <input class="form-control" id="prepTime" name="prep_time_min" type="number" step="1" min="0"
                           value="<?= esc(old('prep_time_min', (string) ($unit['prep_time_min'] ?? '')), 'attr') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="minOrder">Minimum order value (₹)</label>
                    <input class="form-control" id="minOrder" name="min_order_value" type="number" step="0.01" min="0"
                           value="<?= esc(old('min_order_value', (string) ($unit['min_order_value'] ?? '')), 'attr') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="delFee">Delivery fee (₹)</label>
                    <input class="form-control" id="delFee" name="delivery_fee" type="number" step="0.01" min="0"
                           value="<?= esc(old('delivery_fee', (string) ($unit['delivery_fee'] ?? '')), 'attr') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="freeAbove">Free delivery above (₹)</label>
                    <input class="form-control" id="freeAbove" name="free_delivery_above" type="number" step="0.01" min="0"
                           value="<?= esc(old('free_delivery_above', (string) ($unit['free_delivery_above'] ?? '')), 'attr') ?>">
                </div>
            <?php endif; ?>

            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create unit' ?></button>
                <a class="btn btn-outline-secondary" href="<?= site_url('manufacturer/units') ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
