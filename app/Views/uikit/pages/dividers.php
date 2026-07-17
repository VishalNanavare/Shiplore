<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Horizontal rules and labelled separators to break up content.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Basic</h2>
        <p class="mb-0">Content above the divider.</p>
        <hr>
        <p class="mb-0">Content below. Use <code class="uk-code">&lt;hr&gt;</code> for a simple rule.</p>
        <hr class="border-primary border-2 opacity-100 my-4">
        <p class="mb-0 small text-secondary">A colored, thicker variant.</p>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Labelled</h2>
        <div class="d-flex align-items-center my-3"><hr class="flex-grow-1"><span class="px-2 text-secondary small text-uppercase">or</span><hr class="flex-grow-1"></div>
        <div class="d-flex align-items-center my-3"><hr class="flex-grow-1"><span class="px-2 badge bg-light text-secondary border">Section</span><hr class="flex-grow-1"></div>
        <div class="d-flex align-items-center my-3"><span class="pe-2 text-secondary small"><i class="bi bi-star"></i> Featured</span><hr class="flex-grow-1"></div>
        <div class="text-center my-3"><span class="text-secondary small">•••</span></div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
