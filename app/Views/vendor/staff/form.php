<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php
$isEdit  = ! empty($staff);
$action  = $isEdit ? site_url('vendor/staff/' . $staff['id'] . '/update') : site_url('vendor/staff');
$val     = static fn (string $k, $d = '') => esc(old($k, $staff[$k] ?? $d), 'attr');
$roles   = ['branch_manager' => 'Branch Manager', 'cashier' => 'POS Cashier', 'packer' => 'Packer', 'helper' => 'Helper'];
$curType = old('staff_type', $staff['staff_type'] ?? 'cashier');
$assigned = $assigned ?? [];
?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<?php if (! empty($asRequest)): ?>
    <div class="alert alert-info d-flex align-items-center"><i class="bi bi-shield-check me-2"></i>This change will be <strong class="mx-1">submitted to the vendor for approval</strong> before it takes effect.</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0"><i class="bi bi-person-badge me-1"></i><?= $isEdit ? 'Edit staff' : 'Add staff' ?></h1>
    <a href="<?= site_url('vendor/staff') ?>" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<form method="post" action="<?= $action ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-lg-7"><div class="card"><div class="card-body">
            <h2 class="h6 mb-3">Profile &amp; login</h2>
            <div class="row g-2">
                <div class="col-md-6 mb-2"><label class="form-label small">Full name *</label><input name="name" class="form-control form-control-sm" value="<?= $val('name') ?>" required></div>
                <div class="col-md-6 mb-2"><label class="form-label small">Role *</label><select name="staff_type" class="form-select form-select-sm"><?php foreach ($roles as $k => $label): ?><option value="<?= $k ?>" <?= $curType === $k ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6 mb-2"><label class="form-label small">Login email <?= $isEdit ? '' : '*' ?></label><input name="email" type="email" class="form-control form-control-sm" value="<?= $val('email') ?>" <?= $isEdit ? '' : 'required' ?>></div>
                <div class="col-md-6 mb-2"><label class="form-label small">Password <?= $isEdit ? '(leave blank to keep)' : '*' ?></label><input name="password" type="password" class="form-control form-control-sm" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>></div>
                <div class="col-md-4 mb-2"><label class="form-label small">Phone</label><input name="phone" class="form-control form-control-sm" value="<?= $val('phone') ?>"></div>
                <div class="col-md-4 mb-2"><label class="form-label small">Employee code</label><input name="employee_code" class="form-control form-control-sm" value="<?= $val('employee_code') ?>"></div>
                <div class="col-md-4 mb-2"><label class="form-label small">Designation</label><input name="designation" class="form-control form-control-sm" value="<?= $val('designation') ?>"></div>
            </div>
            <div class="form-text">The staff member signs in to the vendor panel with this email + password; their access is limited to the shops and role below.</div>
        </div></div></div>

        <div class="col-lg-5"><div class="card"><div class="card-body">
            <h2 class="h6 mb-3">Shops <span class="text-secondary small">(at least one)</span></h2>
            <?php if (empty($shops)): ?>
                <p class="text-secondary small mb-0">You have no shops yet.</p>
            <?php else: ?>
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Assign</th><th>Shop</th><th class="text-center">Primary</th></tr></thead>
                    <tbody>
                    <?php foreach ($shops as $s): $sid = (int) $s['id']; $on = in_array($sid, array_map('intval', old('shop_ids', $assigned) ?: []), true); ?>
                        <tr>
                            <td><input class="form-check-input" type="checkbox" name="shop_ids[]" value="<?= $sid ?>" <?= $on ? 'checked' : '' ?>></td>
                            <td class="small"><?= esc($s['name']) ?></td>
                            <td class="text-center"><input class="form-check-input" type="radio" name="primary_shop" value="<?= $sid ?>" <?= (int) old('primary_shop') === $sid ? 'checked' : '' ?>></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div></div></div>
    </div>
    <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i><?= $isEdit ? 'Save changes' : 'Create staff' ?></button></div>
</form>
<?= $this->endSection() ?>
