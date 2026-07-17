<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Inputs, sizes, input groups, selects, checks/radios/switches, range, floating labels and validation states.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Text inputs & sizes</h2>
        <div class="mb-2"><input class="form-control form-control-lg" placeholder="Large"></div>
        <div class="mb-2"><input class="form-control" placeholder="Default"></div>
        <div class="mb-3"><input class="form-control form-control-sm" placeholder="Small"></div>
        <div class="mb-2"><input class="form-control" value="Readonly" readonly></div>
        <div class="mb-3"><input class="form-control" placeholder="Disabled" disabled></div>
        <h2 class="uk-section-title mb-3">Input groups</h2>
        <div class="input-group mb-2"><span class="input-group-text"><i class="bi bi-envelope"></i></span><input class="form-control" placeholder="Email"></div>
        <div class="input-group mb-2"><span class="input-group-text">₹</span><input class="form-control" placeholder="0.00"><span class="input-group-text">.00</span></div>
        <div class="input-group"><input class="form-control" placeholder="Search…"><button class="btn btn-primary">Go</button></div>
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Selects & textarea</h2>
        <div class="mb-2"><select class="form-select"><option>Choose…</option><option>Apparel</option><option>Grocery</option></select></div>
        <div class="mb-2"><select class="form-select" multiple size="3"><option selected>Black</option><option>White</option><option selected>Blue</option></select></div>
        <div class="mb-3"><textarea class="form-control" rows="2" placeholder="Message"></textarea></div>
        <h2 class="uk-section-title mb-3">Floating labels</h2>
        <div class="form-floating mb-2"><input class="form-control" id="fe1" placeholder="x"><label for="fe1">Email address</label></div>
        <div class="form-floating"><textarea class="form-control" id="fe2" placeholder="x" style="height:80px"></textarea><label for="fe2">Comments</label></div>
    </div></div></div>
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Checks, radios & switches</h2>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="x1" checked><label class="form-check-label" for="x1">Checkbox</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="x2" disabled><label class="form-check-label" for="x2">Disabled</label></div>
        <div class="form-check form-check-inline mt-2"><input class="form-check-input" type="radio" name="r" id="r1" checked><label class="form-check-label" for="r1">Radio A</label></div>
        <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="r" id="r2"><label class="form-check-label" for="r2">Radio B</label></div>
        <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" id="sw" checked><label class="form-check-label" for="sw">Switch</label></div>
        <label class="form-label mt-3">Range</label><input type="range" class="form-range">
        <label class="form-label mt-2">Color</label><input type="color" class="form-control form-control-color" value="#5b6ef5">
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Validation states</h2>
        <div class="mb-3"><label class="form-label">Valid</label><input class="form-control is-valid" value="Looks good"><div class="valid-feedback">Looks good!</div></div>
        <div class="mb-3"><label class="form-label">Invalid</label><input class="form-control is-invalid" value="bad"><div class="invalid-feedback">Please fix this field.</div></div>
        <div class="mb-0"><label class="form-label">With help text</label><input class="form-control" placeholder="Username"><div class="form-text">Must be 3–20 characters.</div></div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
