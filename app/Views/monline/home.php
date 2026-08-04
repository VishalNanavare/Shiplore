<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="card auth-card shadow-lg" style="max-width:560px">
    <div class="card-body p-4 p-sm-5 text-center">
        <img src="<?= asset('images/logo.svg') ?>" alt="" width="46" height="46" class="mb-3">
        <h1 class="h4 auth-brand mb-1"><?= esc(service('settingsRepository')->brandName()) ?> <span class="text-primary">monline</span></h1>
        <p class="text-secondary small mb-4">Wholesale marketplace — buy directly from manufacturers.</p>

        <?php if (! empty($isBuyer)): ?>
            <div class="alert alert-light border text-start small mb-4">
                Signed in as <strong><?= esc($buyerName ?? 'your business') ?></strong>.
            </div>
            <div class="alert alert-info small mb-4">
                The marketplace is being set up. Manufacturer listings and ordering will
                appear here shortly.
            </div>
            <a class="btn btn-outline-secondary w-100" href="<?= site_url('vendor/dashboard') ?>">
                <i class="bi bi-arrow-left me-1"></i>Back to your dashboard
            </a>
        <?php else: ?>
            <?php
            /*
             * Logged out: NO pricing and NO catalogue, by requirement. Nothing on this
             * branch may render a price — the gate is server-side, not a CSS class.
             */
            ?>
            <div class="alert alert-light border text-start small mb-4">
                monline is for registered <strong>vendors and shops</strong>. Sign in with your
                vendor account to view manufacturer listings and prices.
            </div>
            <a class="btn btn-primary w-100 mb-2" href="<?= site_url('login') ?>">
                <i class="bi bi-box-arrow-in-right me-1"></i>Sign in
            </a>
            <p class="text-secondary small mb-0">
                Not registered yet? <a href="<?= site_url('register') ?>">Become a vendor</a>
                &middot; <a href="<?= site_url('manufacturer-register') ?>">Become a manufacturer</a>
            </p>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
