<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php
$isEdit  = ! empty($staff);
$action  = $isEdit ? site_url('manufacturer/staff/' . (int) $staff['id'] . '/update') : site_url('manufacturer/staff');
$assigned = $assigned ?? [];
?>

<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>

<form method="post" action="<?= esc($action, 'attr') ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header py-2"><strong><?= $isEdit ? 'Edit staff member' : 'New staff member' ?></strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="st-name">Name</label>
                        <input class="form-control" id="st-name" name="name" required
                               value="<?= esc(old('name', $staff['name'] ?? ''), 'attr') ?>">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="st-email">Login email</label>
                            <input class="form-control" id="st-email" name="email" type="email"
                                   <?= $isEdit ? '' : 'required' ?>
                                   value="<?= esc(old('email', $staff['email'] ?? ''), 'attr') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="st-phone">Mobile</label>
                            <input class="form-control" id="st-phone" name="phone"
                                   value="<?= esc(old('phone', $staff['phone'] ?? ''), 'attr') ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="st-password"><?= $isEdit ? 'New password' : 'Password' ?></label>
                        <input class="form-control" id="st-password" name="password" type="password" autocomplete="new-password">
                        <?php if ($isEdit): ?>
                            <div class="form-text">Leave blank to keep the current password.</div>
                        <?php endif; ?>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="st-code">Employee code</label>
                            <input class="form-control" id="st-code" name="employee_code"
                                   value="<?= esc(old('employee_code', $staff['employee_code'] ?? ''), 'attr') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="st-desig">Designation</label>
                            <input class="form-control" id="st-desig" name="designation"
                                   value="<?= esc(old('designation', $staff['designation'] ?? ''), 'attr') ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header py-2"><strong>Role</strong></div>
                <div class="card-body">
                    <?php $current = old('staff_type', $staff['staff_type'] ?? 'store_keeper'); ?>
                    <select class="form-select" name="staff_type" required aria-label="Role">
                        <?php foreach ($types as $t): ?>
                            <option value="<?= esc($t, 'attr') ?>" <?= $current === $t ? 'selected' : '' ?>>
                                <?= esc(ucwords(str_replace('_', ' ', $t))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                        Unit Manager and Store Keeper are scoped to the units you tick below.
                        Manager and Finance Viewer see the whole business.
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header py-2"><strong>Units</strong></div>
                <div class="card-body">
                    <?php if (empty($units)): ?>
                        <p class="small text-secondary mb-0">
                            You have no units yet. <a href="<?= site_url('manufacturer/units') ?>">Add one first</a> —
                            staff must be assigned to at least one unit.
                        </p>
                    <?php else: ?>
                        <?php foreach ($units as $u): ?>
                            <?php $uid = (int) $u['id']; ?>
                            <div class="form-check d-flex align-items-center gap-2">
                                <input class="form-check-input" type="checkbox" name="mshop_ids[]"
                                       id="unit-<?= $uid ?>" value="<?= $uid ?>"
                                       <?= in_array($uid, $assigned, true) ? 'checked' : '' ?>>
                                <label class="form-check-label flex-grow-1" for="unit-<?= $uid ?>">
                                    <?= esc($u['name'] ?? '') ?>
                                </label>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="primary_unit"
                                           id="primary-<?= $uid ?>" value="<?= $uid ?>"
                                           <?= (int) old('primary_unit', $assigned[0] ?? 0) === $uid ? 'checked' : '' ?>>
                                    <label class="form-check-label small text-secondary" for="primary-<?= $uid ?>">primary</label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-3">
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create staff member' ?></button>
        <a class="btn btn-outline-secondary" href="<?= site_url('manufacturer/staff') ?>">Cancel</a>
    </div>
</form>

<?= $this->endSection() ?>
