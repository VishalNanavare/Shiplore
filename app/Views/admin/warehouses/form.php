<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $isEdit = $row !== null; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="row"><div class="col-lg-7">
    <div class="card"><div class="card-body p-4">
        <form method="post" action="<?= $isEdit ? site_url('admin/warehouses/' . $row['id'] . '/update') : site_url('admin/warehouses/store') ?>">
            <?= csrf_field() ?>
            <div class="row g-3">
                <?php if (! $isEdit): ?>
                <div class="col-md-6"><label class="form-label">Vendor <span class="text-danger">*</span></label>
                    <select name="vendor_id" class="form-select" required><option value="">Choose…</option>
                        <?php foreach ($vendors as $v): ?><option value="<?= esc($v['id'], 'attr') ?>"><?= esc($v['display_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label">Code <span class="text-danger">*</span></label><input name="code" class="form-control" value="<?= esc(old('code'), 'attr') ?>" required></div>
                <?php endif; ?>
                <div class="col-md-<?= $isEdit ? '12' : '3' ?>"><label class="form-label">Name <span class="text-danger">*</span></label><input name="name" class="form-control" value="<?= esc($isEdit ? $row['name'] : old('name'), 'attr') ?>" required></div>
                <div class="col-12"><label class="form-label">Address</label><input name="address" class="form-control" value="<?= esc($isEdit ? ($addr['line1'] ?? '') : old('address'), 'attr') ?>"></div>
                <div class="col-md-4"><label class="form-label">City</label><input name="city" class="form-control" value="<?= esc($isEdit ? ($addr['city'] ?? '') : old('city'), 'attr') ?>"></div>
                <div class="col-md-2"><label class="form-label">State</label><input name="state_code" class="form-control" maxlength="2" value="<?= esc($isEdit ? ($row['state_code'] ?? '') : old('state_code'), 'attr') ?>"></div>
                <div class="col-md-2"><label class="form-label">Pincode</label><input name="pincode" class="form-control" maxlength="6" value="<?= esc($isEdit ? ($row['pincode'] ?? '') : old('pincode'), 'attr') ?>"></div>
                <div class="col-md-2"><label class="form-label">Lat</label><input name="latitude" class="form-control" value="<?= esc($isEdit ? ($row['latitude'] ?? '') : old('latitude'), 'attr') ?>"></div>
                <div class="col-md-2"><label class="form-label">Lng</label><input name="longitude" class="form-control" value="<?= esc($isEdit ? ($row['longitude'] ?? '') : old('longitude'), 'attr') ?>"></div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= $isEdit ? 'Update' : 'Create' ?></button>
                <a href="<?= site_url('admin/warehouses') ?>" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div></div>
</div></div>
<?= $this->endSection() ?>
