<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Client-side validation — on submit, individual states, tooltip feedback and input-group validation.</p>

<div class="row g-3">
    <div class="col-lg-7"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Validate on submit</h2>
        <form class="needs-validation row g-3" novalidate id="ukForm">
            <div class="col-md-6"><label class="form-label">Name</label><input class="form-control" required><div class="invalid-feedback">Please enter a name.</div></div>
            <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" required><div class="invalid-feedback">Enter a valid email.</div></div>
            <div class="col-md-6"><label class="form-label">GSTIN</label><div class="input-group has-validation"><span class="input-group-text">IN</span><input class="form-control" pattern="[0-9A-Za-z]{15}" required><div class="invalid-feedback">15-character GSTIN required.</div></div></div>
            <div class="col-md-6"><label class="form-label">State</label><select class="form-select" required><option value="">Choose…</option><option>MH</option><option>KA</option></select><div class="invalid-feedback">Select a state.</div></div>
            <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="agree" required><label class="form-check-label" for="agree">Agree to terms</label><div class="invalid-feedback">You must agree first.</div></div></div>
            <div class="col-12"><button class="btn btn-primary" type="submit">Submit</button> <button class="btn btn-light" type="reset">Reset</button></div>
        </form>
    </div></div></div>

    <div class="col-lg-5"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Individual states</h2>
        <div class="mb-3"><label class="form-label">Valid</label><input class="form-control is-valid" value="Looks good"><div class="valid-feedback">Looks good!</div></div>
        <div class="mb-3"><label class="form-label">Invalid</label><input class="form-control is-invalid" value="bad"><div class="invalid-feedback">Please fix this field.</div></div>
        <div class="mb-3"><label class="form-label">Select (invalid)</label><select class="form-select is-invalid"><option>Choose…</option></select><div class="invalid-feedback">Required.</div></div>
        <h2 class="uk-section-title mb-2">Tooltip feedback</h2>
        <form class="position-relative"><label class="form-label">Username</label><input class="form-control is-invalid"><div class="invalid-tooltip">Username already taken.</div></form>
    </div></div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.getElementById('ukForm').addEventListener('submit', function (e) {
    e.preventDefault();
    if (!this.checkValidity()) { e.stopPropagation(); }
    else if (window.toastr) toastr.success('Form is valid!');
    this.classList.add('was-validated');
});
</script>
<?= $this->endSection() ?>
