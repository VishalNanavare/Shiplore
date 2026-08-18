<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<form class="row g-3">
    <div class="col-lg-4"><div class="card uk-card"><div class="card-body text-center">
        <span class="rounded-circle bg-primary-subtle text-primary d-grid mx-auto mb-3" style="width:84px;height:84px;place-items:center;font-weight:700;font-size:1.8rem">RI</span>
        <div class="d-flex gap-2 justify-content-center"><button class="btn btn-sm btn-primary" type="button">Upload</button><button class="btn btn-sm btn-light" type="button">Reset</button></div>
        <p class="text-secondary small mt-2 mb-0">JPG or PNG, max 2 MB</p>
    </div></div></div>
    <div class="col-lg-8">
        <div class="card uk-card mb-3"><div class="card-body">
            <h2 class="uk-section-title mb-3">Account</h2>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">First name</label><input class="form-control" value="Riya"></div>
                <div class="col-md-6"><label class="form-label">Last name</label><input class="form-control" value="Iyer"></div>
                <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" value="riya@example.com"></div>
                <div class="col-md-6"><label class="form-label">Mobile</label><input class="form-control" value="+91 98xxx"></div>
                <div class="col-md-6"><label class="form-label">Role</label><select class="form-select"><option>Admin</option><option>Vendor</option><option>Staff</option></select></div>
                <div class="col-md-6"><label class="form-label">Status</label><select class="form-select"><option>Active</option><option>Pending</option><option>Suspended</option></select></div>
            </div>
        </div></div>
        <div class="card uk-card"><div class="card-body">
            <h2 class="uk-section-title mb-3">Address</h2>
            <div class="row g-3">
                <div class="col-12"><label class="form-label">Street</label><input class="form-control"></div>
                <div class="col-md-5"><label class="form-label">City</label><input class="form-control"></div>
                <div class="col-md-4"><label class="form-label">State</label><select class="form-select"><option>MH</option><option>KA</option></select></div>
                <div class="col-md-3"><label class="form-label">PIN</label><input class="form-control"></div>
            </div>
            <div class="mt-3"><button class="btn btn-primary" type="button">Save changes</button> <button class="btn btn-light" type="button">Cancel</button></div>
        </div></div>
    </div>
</form>
<?= $this->endSection() ?>
