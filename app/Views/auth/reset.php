<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="card auth-card shadow-lg">
    <div class="card-body p-4 p-sm-5">
        <div class="text-center mb-4">
            <img src="<?= asset('images/logo.svg') ?>" alt="" width="48" height="48" class="mb-2">
            <h1 class="h4 auth-brand mb-1">Reset Password</h1>
            <p class="text-secondary small mb-0">Choose a new password.</p>
        </div>

        <?php if ($m = session('error')): ?><div class="alert alert-danger py-2"><?= esc($m) ?></div><?php endif; ?>

        <form method="post" action="<?= site_url('reset-password') ?>" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="email" value="<?= esc($email ?? '', 'attr') ?>">
            <input type="hidden" name="token" value="<?= esc($token ?? '', 'attr') ?>">
            <div class="mb-3">
                <label class="form-label" for="password">New password</label>
                <div class="input-group"><span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password" minlength="8" required></div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password_confirm">Confirm password</label>
                <div class="input-group"><span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="8" required></div>
            </div>
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2-circle me-1"></i> Update password</button>
        </form>

        <p class="text-center small mt-4 mb-0"><a href="<?= site_url('/') ?>">Back to sign in</a></p>
    </div>
</div>
<?= $this->endSection() ?>
