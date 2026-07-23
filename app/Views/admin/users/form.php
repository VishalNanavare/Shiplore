<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
// Shared by "Add Staff" and "Edit". $user is null when creating.
$editing = ($user ?? null) !== null;
$action  = $editing ? site_url('admin/users/' . $user['id'] . '/update') : site_url('admin/users/store');
$val     = static fn (string $k, string $fallback = ''): string => (string) (old($k) ?? $fallback);
?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<div class="row"><div class="col-lg-6">
    <div class="card"><div class="card-body p-4">
        <form method="post" action="<?= $action ?>">
            <?= csrf_field() ?>
            <div class="mb-3"><label class="form-label">Name <span class="text-danger">*</span></label>
                <input name="name" class="form-control" value="<?= esc($val('name', $editing ? (string) $user['name'] : ''), 'attr') ?>" required></div>
            <div class="mb-3"><label class="form-label">Email <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control" value="<?= esc($val('email', $editing ? (string) $user['email'] : ''), 'attr') ?>" required></div>
            <div class="row g-3">
                <div class="col-6">
                    <label class="form-label">Mobile</label>
                    <input name="phone" class="form-control" placeholder="+91…"
                           value="<?= esc($val('phone', $editing ? (string) ($user['phone'] ?? '') : ''), 'attr') ?>">
                    <div class="form-text">
                        Stored as +91XXXXXXXXXX. This number can be used to sign in with a phone OTP,
                        so changing it clears the verified flag.
                    </div>
                </div>
                <div class="col-6">
                    <label class="form-label">Password <?php if (! $editing): ?><span class="text-danger">*</span><?php endif; ?></label>
                    <input type="password" name="password" class="form-control" minlength="8" <?= $editing ? '' : 'required' ?>>
                    <?php if ($editing): ?><div class="form-text">Leave blank to keep the current password.</div><?php endif; ?>
                </div>
            </div>
            <?php if ($editing): ?>
                <div class="mt-2">
                    <?php if (($user['phone_verified_at'] ?? null) !== null): ?>
                        <span class="badge text-bg-success"><i class="bi bi-patch-check"></i> Mobile verified</span>
                    <?php elseif (trim((string) ($user['phone'] ?? '')) !== ''): ?>
                        <span class="badge text-bg-secondary"><i class="bi bi-patch-exclamation"></i> Mobile not verified</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="mb-3 mt-3"><label class="form-label">Role <span class="text-danger">*</span></label>
                <select name="role_id" class="form-select" required>
                    <option value="">— choose a role —</option>
                    <?php foreach ($roles as $r): ?>
                        <?php $sel = (string) $val('role_id', $editing ? (string) ($user['role_id'] ?? '') : ''); ?>
                        <option value="<?= esc($r['id'], 'attr') ?>" <?= (string) $r['id'] === $sel ? 'selected' : '' ?>><?= esc($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">A staff user without a role can sign in but cannot do anything.</div>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= $editing ? 'Save changes' : 'Create user' ?></button>
                <a href="<?= site_url('admin/users') ?>" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div></div>
</div></div>
<?= $this->endSection() ?>
