<?= $this->extend('uikit/_blank') ?>

<?= $this->section('content') ?>
<div class="card uk-card uk-blank-card mx-auto"><div class="card-body p-4 p-sm-5">
    <div class="text-center mb-4">
        <div class="display-6 text-primary mb-2"><i class="bi bi-question-circle"></i></div>
        <h1 class="h4 mb-1">Forgot password?</h1>
        <p class="text-secondary small mb-0">Enter your email and we'll send a reset link.</p>
    </div>
    <form>
        <div class="mb-3"><label class="form-label">Email</label>
            <div class="input-group"><span class="input-group-text"><i class="bi bi-envelope"></i></span><input type="email" class="form-control" placeholder="you@example.com"></div></div>
        <button class="btn btn-primary w-100" type="button"><i class="bi bi-send me-1"></i>Send reset link</button>
    </form>
    <p class="text-center small mt-4 mb-0"><a href="<?= site_url('ui-kit/auth-login') ?>"><i class="bi bi-arrow-left me-1"></i>Back to sign in</a></p>
</div></div>
<?= $this->endSection() ?>
