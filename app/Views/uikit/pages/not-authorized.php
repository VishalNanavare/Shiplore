<?= $this->extend('uikit/_blank') ?>

<?= $this->section('content') ?>
<div class="text-center">
    <div class="uk-error-code text-warning">403</div>
    <h1 class="h4 mt-2">Access denied</h1>
    <p class="text-secondary">You don't have permission to view this page. Contact an administrator if you believe this is a mistake.</p>
    <div class="d-flex justify-content-center gap-2">
        <a href="<?= site_url('ui-kit') ?>" class="btn btn-primary"><i class="bi bi-house-door me-1"></i>Back to home</a>
        <a href="#" class="btn btn-outline-secondary"><i class="bi bi-headset me-1"></i>Request access</a>
    </div>
</div>
<?= $this->endSection() ?>
