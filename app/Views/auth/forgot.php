<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="card auth-card shadow-lg">
    <div class="card-body p-4 p-sm-5">
        <div class="text-center mb-4">
            <img src="<?= asset('images/logo.svg') ?>" alt="" width="48" height="48" class="mb-2">
            <h1 class="h4 auth-brand mb-1">Forgot Password</h1>
            <p class="text-secondary small mb-0">Enter your email and we'll send a reset link.</p>
        </div>

        <?php if ($m = session('status')): ?><div class="alert alert-success py-2"><?= esc($m) ?></div><?php endif; ?>
        <?php if ($link = session('reset_link')): ?>
            <div class="alert alert-info py-2 small">Dev: <a href="<?= esc($link, 'attr') ?>">reset link</a></div>
        <?php endif; ?>

        <form method="post" action="<?= site_url('forgot-password') ?>" autocomplete="off">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="email">Email</label>
                <div class="input-group"><span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" class="form-control" id="email" name="email" required></div>
            </div>
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-send me-1"></i> Send reset link</button>
        </form>

        <p class="text-center small mt-4 mb-0"><a href="<?= site_url('/') ?>"><i class="bi bi-arrow-left me-1"></i>Back to sign in</a></p>
    </div>
</div>
<?= $this->endSection() ?>
