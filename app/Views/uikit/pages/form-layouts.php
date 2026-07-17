<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Form arrangements — vertical, horizontal, grid, inline, floating labels and a settings card.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Grid form</h2>
        <form class="row g-3">
            <div class="col-md-6"><label class="form-label">First name</label><input class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Last name</label><input class="form-control"></div>
            <div class="col-12"><label class="form-label">Address</label><input class="form-control"></div>
            <div class="col-md-5"><label class="form-label">City</label><input class="form-control"></div>
            <div class="col-md-4"><label class="form-label">State</label><select class="form-select"><option>MH</option><option>KA</option></select></div>
            <div class="col-md-3"><label class="form-label">PIN</label><input class="form-control"></div>
            <div class="col-12"><button class="btn btn-primary" type="button">Save</button></div>
        </form>
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Horizontal form</h2>
        <form>
            <div class="row mb-3"><label class="col-sm-3 col-form-label">Email</label><div class="col-sm-9"><input class="form-control"></div></div>
            <div class="row mb-3"><label class="col-sm-3 col-form-label">Password</label><div class="col-sm-9"><input type="password" class="form-control"></div></div>
            <div class="row mb-3"><label class="col-sm-3 col-form-label">Plan</label><div class="col-sm-9"><select class="form-select"><option>Starter</option><option>Growth</option></select></div></div>
            <div class="row"><div class="col-sm-9 offset-sm-3"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="rm"><label class="form-check-label" for="rm">Remember me</label></div><button class="btn btn-primary" type="button">Sign in</button></div></div>
        </form>
    </div></div></div>
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Floating labels</h2>
        <form class="row g-3">
            <div class="col-12"><div class="form-floating"><input class="form-control" id="fl1" placeholder="x"><label for="fl1">Email address</label></div></div>
            <div class="col-12"><div class="form-floating"><input type="password" class="form-control" id="fl2" placeholder="x"><label for="fl2">Password</label></div></div>
            <div class="col-12"><div class="form-floating"><select class="form-select" id="fl3"><option>One</option><option>Two</option></select><label for="fl3">Plan</label></div></div>
            <div class="col-12"><button class="btn btn-primary" type="button">Continue</button></div>
        </form>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Inline form</h2>
        <form class="row row-cols-lg-auto g-2 align-items-center mb-4">
            <div class="col-12"><div class="input-group"><span class="input-group-text">@</span><input class="form-control" placeholder="Username"></div></div>
            <div class="col-12"><select class="form-select"><option>Filter</option><option>Active</option></select></div>
            <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="il"><label class="form-check-label" for="il">Verified</label></div></div>
            <div class="col-12"><button class="btn btn-primary" type="button">Apply</button></div>
        </form>
        <h2 class="uk-section-title mb-3">Settings card</h2>
        <div class="d-flex justify-content-between align-items-center py-2 border-bottom"><div><div class="small fw-medium">Email notifications</div><div class="text-secondary small">Order & payout updates</div></div><div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" checked></div></div>
        <div class="d-flex justify-content-between align-items-center py-2"><div><div class="small fw-medium">Two-factor auth</div><div class="text-secondary small">Extra account security</div></div><div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox"></div></div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
