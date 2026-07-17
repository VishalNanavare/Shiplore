<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php if ($m = session('status')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-body">
                <h2 class="h6 mb-3"><i class="bi bi-person me-1"></i>Personal Info</h2>
                <form method="post" action="<?= site_url('vendor/me') ?>" style="max-width:480px">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="name">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name"
                               value="<?= esc(old('name', $user['name'] ?? ''), 'attr') ?>"
                               maxlength="191" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="email">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?= esc(old('email', $user['email'] ?? ''), 'attr') ?>"
                               maxlength="191">
                        <div class="form-text">Leave blank to keep current email.</div>
                    </div>

                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card" id="password">
            <div class="card-body">
                <h2 class="h6 mb-3"><i class="bi bi-shield-lock me-1"></i>Change Password</h2>
                <?php if ($pe = session('pwd_error')): ?>
                    <div class="alert alert-danger py-2"><?= esc($pe) ?></div>
                <?php endif; ?>
                <form method="post" action="<?= site_url('vendor/me/password') ?>" style="max-width:480px">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="new_password">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password"
                               minlength="8" required autocomplete="new-password">
                        <div class="form-text">Minimum 8 characters.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="confirm_password">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                               minlength="8" required autocomplete="new-password">
                    </div>

                    <button type="submit" class="btn btn-primary">Change Password</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
