<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php if (session('status')): ?>
    <div class="alert alert-success py-2"><?= esc(session('status')) ?></div>
<?php endif; ?>
<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header py-2"><strong>My details</strong></div>
            <div class="card-body">
                <form method="post" action="<?= site_url('manufacturer/me') ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="me-name">Name</label>
                        <input class="form-control" id="me-name" name="name" required
                               value="<?= esc(old('name', $user['name'] ?? ''), 'attr') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="me-email">Email</label>
                        <input class="form-control" id="me-email" name="email" type="email"
                               value="<?= esc(old('email', $user['email'] ?? ''), 'attr') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="me-phone">Mobile</label>
                        <input class="form-control" id="me-phone" type="text" disabled
                               value="<?= esc((string) ($user['phone'] ?? '—'), 'attr') ?>">
                        <div class="form-text">
                            Your mobile number is your login and is verified — contact support to change it.
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">Save changes</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card" id="password">
            <div class="card-header py-2"><strong>Change password</strong></div>
            <div class="card-body">
                <?php if (session('pwd_error')): ?>
                    <div class="alert alert-danger py-2"><?= esc(session('pwd_error')) ?></div>
                <?php endif; ?>
                <form method="post" action="<?= site_url('manufacturer/me/password') ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="pwd-current">Current password</label>
                        <input class="form-control" id="pwd-current" name="current_password" type="password" required autocomplete="current-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="pwd-new">New password</label>
                        <input class="form-control" id="pwd-new" name="new_password" type="password" required minlength="8" autocomplete="new-password">
                        <div class="form-text">At least 8 characters.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="pwd-confirm">Confirm new password</label>
                        <input class="form-control" id="pwd-confirm" name="confirm_password" type="password" required minlength="8" autocomplete="new-password">
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">Change password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
