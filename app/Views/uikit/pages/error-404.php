<?= $this->extend('uikit/_blank') ?>

<?= $this->section('content') ?>
<div class="text-center">
    <div class="uk-error-code">404</div>
    <h1 class="h4 mt-2">Page not found</h1>
    <p class="text-secondary">The page you're looking for doesn't exist or has been moved.</p>
    <a href="<?= site_url('ui-kit') ?>" class="btn btn-primary"><i class="bi bi-house-door me-1"></i>Back to home</a>
</div>
<?= $this->endSection() ?>
