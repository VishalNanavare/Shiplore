<?= $this->extend('uikit/_blank') ?>

<?= $this->section('content') ?>
<div class="card uk-card uk-blank-card mx-auto"><div class="card-body p-4 p-sm-5">
    <div class="text-center mb-4">
        <img src="<?= asset('images/logo.svg') ?>" width="46" height="46" class="mb-2">
        <h1 class="h4 mb-1">Welcome back</h1>
        <p class="text-secondary small mb-0">Sign in to continue to Shiplore</p>
    </div>
    <form>
        <div class="mb-3"><label class="form-label">Email</label>
            <div class="input-group"><span class="input-group-text"><i class="bi bi-envelope"></i></span><input type="email" class="form-control" value="admin@platform.local"></div></div>
        <div class="mb-3"><label class="form-label">Password</label>
            <div class="input-group"><span class="input-group-text"><i class="bi bi-lock"></i></span><input type="password" class="form-control" value="password"></div></div>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="rm" checked><label class="form-check-label small" for="rm">Remember me</label></div>
            <a href="<?= site_url('ui-kit/auth-forgot') ?>" class="small">Forgot password?</a>
        </div>
        <button class="btn btn-primary w-100" type="button"><i class="bi bi-box-arrow-in-right me-1"></i>Sign In</button>
    </form>
    <div class="text-center my-3 text-secondary small">or</div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary w-100"><i class="bi bi-google"></i></button>
        <button class="btn btn-outline-secondary w-100"><i class="bi bi-facebook"></i></button>
        <button class="btn btn-outline-secondary w-100"><i class="bi bi-apple"></i></button>
    </div>
    <p class="text-center small mt-4 mb-0">New here? <a href="<?= site_url('ui-kit/auth-register') ?>">Create an account</a></p>
</div></div>
<?= $this->endSection() ?>
