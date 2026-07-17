<?= $this->extend('uikit/_blank') ?>

<?= $this->section('content') ?>
<div class="card uk-card uk-blank-card mx-auto"><div class="card-body p-4 p-sm-5">
    <div class="text-center mb-4">
        <div class="display-6 text-primary mb-2"><i class="bi bi-shield-lock"></i></div>
        <h1 class="h4 mb-1">Reset password</h1>
        <p class="text-secondary small mb-0">Choose a new password for your account.</p>
    </div>
    <form>
        <div class="mb-3"><label class="form-label">New password</label>
            <div class="input-group"><span class="input-group-text"><i class="bi bi-lock"></i></span><input type="password" class="form-control"></div></div>
        <div class="mb-3"><label class="form-label">Confirm password</label>
            <div class="input-group"><span class="input-group-text"><i class="bi bi-lock-fill"></i></span><input type="password" class="form-control"></div></div>
        <button class="btn btn-primary w-100" type="button"><i class="bi bi-check2-circle me-1"></i>Update password</button>
    </form>
    <p class="text-center small mt-4 mb-0"><a href="<?= site_url('ui-kit/auth-login') ?>">Back to sign in</a></p>
</div></div>
<?= $this->endSection() ?>
