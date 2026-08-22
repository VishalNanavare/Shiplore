<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $isEdit = $row !== null; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= site_url('admin/commission') ?>">Commission</a></li><li class="breadcrumb-item"><a href="<?= site_url('admin/vendor-commission-overrides') ?>">Vendor Overrides</a></li><li class="breadcrumb-item active"><?= $isEdit ? 'Edit' : 'New' ?></li></ol></nav>

<div class="card" style="max-width: 480px;">
    <div class="card-header fw-semibold"><?= $isEdit ? 'Edit' : 'New' ?> Override</div>
    <div class="card-body">
        <form method="post" action="<?= $isEdit ? site_url('admin/vendor-commission-overrides/' . $row['id'] . '/update') : site_url('admin/vendor-commission-overrides/store') ?>">
            <?= csrf_field() ?>
            <div class="mb-3"><label class="form-label">Vendor ID</label><input type="number" name="vendor_id" class="form-control" value="<?= esc((string) old('vendor_id', (string) ($row['vendor_id'] ?? '')), 'attr') ?>" required></div>
            <div class="mb-3"><label class="form-label">Category ID (blank = vendor-wide)</label><input type="number" name="category_id" class="form-control" value="<?= esc((string) old('category_id', (string) ($row['category_id'] ?? '')), 'attr') ?>"></div>
            <div class="mb-3"><label class="form-label">Rate %</label><input type="number" step="0.01" name="rate" class="form-control" value="<?= esc((string) old('rate', (string) ($row['rate'] ?? '')), 'attr') ?>" required></div>
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label">Valid from</label><input type="date" name="valid_from" class="form-control" value="<?= esc((string) old('valid_from', (string) ($row['valid_from'] ?? '')), 'attr') ?>" required></div>
                <div class="col-md-6"><label class="form-label">Valid to (blank = no expiry)</label><input type="date" name="valid_to" class="form-control" value="<?= esc((string) old('valid_to', (string) ($row['valid_to'] ?? '')), 'attr') ?>"></div>
            </div>
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create override' ?></button>
            <a href="<?= site_url('admin/vendor-commission-overrides') ?>" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
<?= $this->endSection() ?>
