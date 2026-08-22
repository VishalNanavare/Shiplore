<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $isEdit = $row !== null; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= site_url('admin/commission') ?>">Commission</a></li><li class="breadcrumb-item"><a href="<?= site_url('admin/commission-rules') ?>">Rules</a></li><li class="breadcrumb-item active"><?= $isEdit ? 'Edit' : 'New' ?></li></ol></nav>

<div class="card" style="max-width: 560px;">
    <div class="card-header fw-semibold"><?= $isEdit ? 'Edit' : 'New' ?> Commission Rule</div>
    <div class="card-body">
        <form method="post" action="<?= $isEdit ? site_url('admin/commission-rules/' . $row['id'] . '/update') : site_url('admin/commission-rules/store') ?>">
            <?= csrf_field() ?>
            <div class="mb-3"><label class="form-label">Commission plan ID</label><input type="number" name="commission_plan_id" class="form-control" value="<?= esc((string) old('commission_plan_id', (string) ($row['commission_plan_id'] ?? '1')), 'attr') ?>" required></div>
            <div class="row g-3 mb-3">
                <div class="col-md-4"><label class="form-label">Category ID</label><input type="number" name="category_id" class="form-control" value="<?= esc((string) old('category_id', (string) ($row['category_id'] ?? '')), 'attr') ?>"></div>
                <div class="col-md-4"><label class="form-label">Product ID</label><input type="number" name="product_id" class="form-control" value="<?= esc((string) old('product_id', (string) ($row['product_id'] ?? '')), 'attr') ?>"></div>
                <div class="col-md-4"><label class="form-label">Business type ID</label><input type="number" name="business_type_id" class="form-control" value="<?= esc((string) old('business_type_id', (string) ($row['business_type_id'] ?? '')), 'attr') ?>"></div>
            </div>
            <div class="form-text mb-3">Pick exactly one scope above.</div>
            <div class="mb-3"><label class="form-label">Type</label>
                <select name="commission_type" class="form-select">
                    <option value="percentage" <?= ($row['commission_type'] ?? 'percentage') === 'percentage' ? 'selected' : '' ?>>Percentage</option>
                    <option value="fixed" <?= ($row['commission_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed amount</option>
                </select>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label">Rate %</label><input type="number" step="0.01" name="rate" class="form-control" value="<?= esc((string) old('rate', (string) ($row['rate'] ?? '')), 'attr') ?>"></div>
                <div class="col-md-6"><label class="form-label">Fixed amount ₹</label><input type="number" step="0.01" name="fixed_amount" class="form-control" value="<?= esc((string) old('fixed_amount', (string) ($row['fixed_amount'] ?? '')), 'attr') ?>"></div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-4"><label class="form-label">Min GMV</label><input type="number" step="0.01" name="min_gmv" class="form-control" value="<?= esc((string) old('min_gmv', (string) ($row['min_gmv'] ?? '')), 'attr') ?>"></div>
                <div class="col-md-4"><label class="form-label">Max GMV</label><input type="number" step="0.01" name="max_gmv" class="form-control" value="<?= esc((string) old('max_gmv', (string) ($row['max_gmv'] ?? '')), 'attr') ?>"></div>
                <div class="col-md-4"><label class="form-label">Priority</label><input type="number" name="priority" class="form-control" value="<?= esc((string) old('priority', (string) ($row['priority'] ?? '0')), 'attr') ?>"></div>
            </div>
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create rule' ?></button>
            <a href="<?= site_url('admin/commission-rules') ?>" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
<?= $this->endSection() ?>
