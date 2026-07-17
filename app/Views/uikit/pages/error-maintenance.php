<?= $this->extend('uikit/_blank') ?>

<?= $this->section('content') ?>
<div class="text-center">
    <div class="display-1 text-warning mb-2"><i class="bi bi-cone-striped"></i></div>
    <h1 class="h3">Under maintenance</h1>
    <p class="text-secondary">We're performing scheduled maintenance and will be back shortly. Thanks for your patience.</p>
    <div class="d-flex justify-content-center gap-2 mt-3">
        <span class="badge bg-light text-secondary border"><i class="bi bi-clock me-1"></i>ETA: 30 min</span>
        <a href="#" class="btn btn-sm btn-outline-primary">Status page</a>
    </div>
</div>
<?= $this->endSection() ?>
