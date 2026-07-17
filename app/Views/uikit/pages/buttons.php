<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Solid, outline, soft, gradient, sizes, rounded, icon, social, groups, toggles and states.</p>

<div class="card uk-card mb-3"><div class="card-body">
    <h2 class="uk-section-title mb-3">Solid</h2>
    <div class="d-flex gap-2 flex-wrap mb-4"><?php foreach (['primary','secondary','success','danger','warning','info','dark','light','link'] as $c): ?><button class="btn btn-<?= $c ?>"><?= ucfirst($c) ?></button><?php endforeach; ?></div>
    <h2 class="uk-section-title mb-3">Outline</h2>
    <div class="d-flex gap-2 flex-wrap mb-4"><?php foreach (['primary','secondary','success','danger','warning','info','dark'] as $c): ?><button class="btn btn-outline-<?= $c ?>"><?= ucfirst($c) ?></button><?php endforeach; ?></div>
    <h2 class="uk-section-title mb-3">Soft / tonal</h2>
    <div class="d-flex gap-2 flex-wrap"><?php foreach (['primary','success','danger','warning','info','secondary'] as $c): ?><button class="btn bg-<?= $c ?>-subtle text-<?= $c ?> border-0"><?= ucfirst($c) ?></button><?php endforeach; ?></div>
</div></div>

<div class="row g-3 mb-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Sizes</h2>
        <div class="d-flex gap-2 flex-wrap align-items-center mb-3"><button class="btn btn-primary btn-lg">Large</button><button class="btn btn-primary">Default</button><button class="btn btn-primary btn-sm">Small</button></div>
        <h2 class="uk-section-title mb-3">Rounded & block</h2>
        <div class="d-flex gap-2 flex-wrap mb-3"><button class="btn btn-primary rounded-pill px-4">Pill</button><button class="btn btn-outline-primary rounded-pill px-4">Pill outline</button></div>
        <div class="d-grid"><button class="btn btn-primary">Block / full width</button></div>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">With icons & icon-only</h2>
        <div class="d-flex gap-2 flex-wrap mb-3">
            <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
            <button class="btn btn-danger"><i class="bi bi-trash me-1"></i>Delete</button>
            <button class="btn btn-primary">Next<i class="bi bi-arrow-right ms-1"></i></button>
            <button class="btn btn-light"><i class="bi bi-gear"></i></button>
            <button class="btn btn-primary rounded-circle" style="width:40px;height:40px"><i class="bi bi-plus-lg"></i></button>
        </div>
        <h2 class="uk-section-title mb-3">Social</h2>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn text-white" style="background:#1877f2"><i class="bi bi-facebook"></i></button>
            <button class="btn text-white" style="background:#1da1f2"><i class="bi bi-twitter"></i></button>
            <button class="btn text-white" style="background:#ea4335"><i class="bi bi-google"></i></button>
            <button class="btn text-white" style="background:#333"><i class="bi bi-github"></i></button>
            <button class="btn text-white" style="background:#0a66c2"><i class="bi bi-linkedin"></i></button>
        </div>
    </div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Groups & toolbar</h2>
        <div class="btn-group mb-3"><button class="btn btn-outline-secondary">Left</button><button class="btn btn-outline-secondary active">Mid</button><button class="btn btn-outline-secondary">Right</button></div>
        <div class="btn-toolbar gap-2">
            <div class="btn-group"><button class="btn btn-light"><i class="bi bi-type-bold"></i></button><button class="btn btn-light"><i class="bi bi-type-italic"></i></button><button class="btn btn-light"><i class="bi bi-type-underline"></i></button></div>
            <div class="btn-group"><button class="btn btn-light"><i class="bi bi-text-left"></i></button><button class="btn btn-light"><i class="bi bi-text-center"></i></button></div>
        </div>
        <div class="mt-3"><div class="btn-group"><button class="btn btn-primary">Save</button><button class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button><ul class="dropdown-menu"><li><a class="dropdown-item" href="#">Save & new</a></li></ul></div></div>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">States & toggles</h2>
        <div class="d-flex gap-2 flex-wrap mb-3">
            <button class="btn btn-primary active">Active</button>
            <button class="btn btn-primary" disabled>Disabled</button>
            <button class="btn btn-primary"><span class="spinner-border spinner-border-sm me-1"></span>Loading</button>
        </div>
        <div class="btn-group" role="group">
            <input type="checkbox" class="btn-check" id="bt1" checked><label class="btn btn-outline-primary" for="bt1">Bold</label>
            <input type="checkbox" class="btn-check" id="bt2"><label class="btn btn-outline-primary" for="bt2">Italic</label>
        </div>
        <div class="mt-3 btn-group" role="group">
            <input type="radio" class="btn-check" name="bvw" id="bv1" checked><label class="btn btn-outline-secondary" for="bv1"><i class="bi bi-grid"></i></label>
            <input type="radio" class="btn-check" name="bvw" id="bv2"><label class="btn btn-outline-secondary" for="bv2"><i class="bi bi-list"></i></label>
        </div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
