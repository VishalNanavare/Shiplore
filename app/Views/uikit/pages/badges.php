<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Labels & counters — solid, pill, soft, outline, with icons, in headings, buttons and positioned.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Solid</h2>
        <div class="d-flex gap-2 flex-wrap mb-4"><?php foreach (['primary','secondary','success','danger','warning','info','dark'] as $c): ?><span class="badge bg-<?= $c ?>"><?= ucfirst($c) ?></span><?php endforeach; ?></div>
        <h2 class="uk-section-title mb-3">Pills</h2>
        <div class="d-flex gap-2 flex-wrap mb-4"><?php foreach (['primary','success','danger','warning','info'] as $c): ?><span class="badge rounded-pill bg-<?= $c ?>"><?= ucfirst($c) ?></span><?php endforeach; ?></div>
        <h2 class="uk-section-title mb-3">Soft</h2>
        <div class="d-flex gap-2 flex-wrap"><?php foreach (['primary','success','danger','warning','info','secondary'] as $c): ?><span class="badge bg-<?= $c ?>-subtle text-<?= $c ?>"><?= ucfirst($c) ?></span><?php endforeach; ?></div>
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Outline & with icons</h2>
        <div class="d-flex gap-2 flex-wrap mb-4"><?php foreach (['primary','success','danger','warning'] as $c): ?><span class="badge border border-<?= $c ?> text-<?= $c ?>"><?= ucfirst($c) ?></span><?php endforeach; ?></div>
        <div class="d-flex gap-2 flex-wrap mb-4">
            <span class="badge bg-success-subtle text-success"><i class="bi bi-check-circle me-1"></i>Active</span>
            <span class="badge bg-warning-subtle text-warning"><i class="bi bi-hourglass-split me-1"></i>Pending</span>
            <span class="badge bg-danger-subtle text-danger"><i class="bi bi-x-circle me-1"></i>Failed</span>
            <span class="badge bg-info-subtle text-info"><i class="bi bi-truck me-1"></i>Shipped</span>
        </div>
        <h2 class="uk-section-title mb-3">In headings</h2>
        <h5>Orders <span class="badge bg-primary">12</span></h5>
        <h6 class="mb-0">Notifications <span class="badge rounded-pill bg-danger">9+</span></h6>
    </div></div></div>
</div>

<div class="card uk-card mt-3"><div class="card-body">
    <h2 class="uk-section-title mb-3">In context</h2>
    <div class="d-flex gap-4 flex-wrap align-items-center">
        <button class="btn btn-primary position-relative">Inbox<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">4</span></button>
        <button class="btn btn-light position-relative"><i class="bi bi-bell fs-5"></i><span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle"></span></button>
        <span class="position-relative">Cart <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-primary">3</span></span>
        <a href="#" class="btn btn-outline-secondary">Profile <span class="badge bg-secondary">New</span></a>
    </div>
</div></div>
<?= $this->endSection() ?>
