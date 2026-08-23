<?php
/**
 * Shared staff profile + shop-assignment fields — used by both the standalone
 * add/edit form (vendor/staff/form.php) and the "Add Staff" modal on
 * vendor/staff/index.php. Callers provide $shops and $assigned; $editStaff
 * defaults to null (create mode) when the caller has no staff row to edit.
 *
 * Deliberately NOT called $staff: index.php's own $staff is the staff LIST
 * array for its table, already in scope when this partial is included there
 * (CI4's $this->include() shares the full view data). Naming this $editStaff
 * avoids silently reading that list as an "editing" row.
 */
$editStaff = $editStaff ?? null;
$val       = static fn (string $k, $d = '') => esc(old($k, $editStaff[$k] ?? $d), 'attr');
$roles     = ['branch_manager' => 'Branch Manager', 'cashier' => 'POS Cashier', 'packer' => 'Packer', 'helper' => 'Helper'];
$curType   = old('staff_type', $editStaff['staff_type'] ?? 'cashier');
$isEdit    = ! empty($editStaff);
?>
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
