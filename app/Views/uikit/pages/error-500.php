<?= $this->extend('uikit/_blank') ?>

<?= $this->section('content') ?>
<div class="text-center">
    <div class="uk-error-code text-danger">500</div>
    <h1 class="h4 mt-2">Internal server error</h1>
    <p class="text-secondary">Something went wrong on our end. We've been notified and are looking into it.</p>
    <a href="<?= site_url('ui-kit') ?>" class="btn btn-primary"><i class="bi bi-arrow-clockwise me-1"></i>Try again</a>
</div>
<?= $this->endSection() ?>
