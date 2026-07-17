<?= $this->extend('uikit/_blank') ?>

<?= $this->section('content') ?>
<div class="card uk-card uk-blank-card mx-auto"><div class="card-body p-4 p-sm-5">
    <div class="text-center mb-4">
        <img src="<?= asset('images/logo.svg') ?>" width="46" height="46" class="mb-2">
        <h1 class="h4 mb-1">Create your account</h1>
        <p class="text-secondary small mb-0">Start selling on Shiplore</p>
    </div>
    <form>
        <div class="row g-2">
            <div class="col-6 mb-2"><label class="form-label">First name</label><input class="form-control"></div>
            <div class="col-6 mb-2"><label class="form-label">Last name</label><input class="form-control"></div>
        </div>
        <div class="mb-2"><label class="form-label">Email</label><input type="email" class="form-control"></div>
        <div class="mb-2"><label class="form-label">Mobile</label><input class="form-control" placeholder="+91"></div>
        <div class="mb-3"><label class="form-label">Password</label><input type="password" class="form-control"></div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="tos"><label class="form-check-label small" for="tos">I agree to the <a href="#">Terms</a> & <a href="#">Privacy Policy</a></label></div>
        <button class="btn btn-primary w-100" type="button"><i class="bi bi-person-plus me-1"></i>Sign Up</button>
    </form>
    <p class="text-center small mt-4 mb-0">Already have an account? <a href="<?= site_url('ui-kit/auth-login') ?>">Sign in</a></p>
</div></div>
<?= $this->endSection() ?>
