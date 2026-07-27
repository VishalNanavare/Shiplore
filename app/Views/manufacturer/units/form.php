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
            // No delivery checkbox and no radius input — a manufacturer has no delivery
            // range, and mshops has no column to store one. See partials/_factory_location.
            echo $this->include('partials/_factory_location');
            ?>

            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create unit' ?></button>
                <a class="btn btn-outline-secondary" href="<?= site_url('manufacturer/units') ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
