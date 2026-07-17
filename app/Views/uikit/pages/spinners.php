<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Loading indicators — border & grow spinners, colors, sizes, buttons, alignment and overlays.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Border</h2>
        <div class="d-flex gap-3 align-items-center flex-wrap mb-4"><?php foreach (['primary','success','warning','danger','info','secondary','dark'] as $c): ?><div class="spinner-border text-<?= $c ?>"></div><?php endforeach; ?></div>
        <h2 class="uk-section-title mb-3">Grow</h2>
        <div class="d-flex gap-3 align-items-center flex-wrap"><?php foreach (['primary','success','warning','danger','info'] as $c): ?><div class="spinner-grow text-<?= $c ?>"></div><?php endforeach; ?></div>
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Sizes</h2>
        <div class="d-flex gap-3 align-items-center mb-4">
            <div class="spinner-border spinner-border-sm text-primary"></div>
            <div class="spinner-border text-primary"></div>
            <div class="spinner-border text-primary" style="width:3rem;height:3rem"></div>
            <div class="spinner-grow spinner-grow-sm text-success"></div>
            <div class="spinner-grow text-success" style="width:3rem;height:3rem"></div>
        </div>
        <h2 class="uk-section-title mb-3">In buttons</h2>
        <div class="d-flex gap-2 flex-wrap mb-4">
            <button class="btn btn-primary" disabled><span class="spinner-border spinner-border-sm me-1"></span>Loading…</button>
            <button class="btn btn-success" disabled><span class="spinner-grow spinner-grow-sm me-1"></span>Saving…</button>
            <button class="btn btn-outline-primary" disabled><span class="spinner-border spinner-border-sm"></span></button>
        </div>
        <h2 class="uk-section-title mb-3">With text</h2>
        <div class="d-flex align-items-center gap-2 text-secondary"><div class="spinner-border spinner-border-sm text-primary"></div> Fetching data…</div>
    </div></div></div>
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Centered in a box</h2>
        <div class="border rounded d-grid" style="height:120px;place-items:center"><div class="spinner-border text-primary"></div></div>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Card overlay</h2>
        <div class="position-relative border rounded" style="height:120px">
            <div class="position-absolute inset-0 d-grid" style="inset:0;place-items:center;background:rgba(255,255,255,.6)"><div class="spinner-border text-primary"></div></div>
            <div class="p-3 small text-secondary">Content behind a loading overlay…</div>
        </div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
