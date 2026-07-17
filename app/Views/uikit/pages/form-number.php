<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Number inputs — native spinner, custom stepper, and quantity control.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Native & ranges</h2>
        <div class="mb-3"><label class="form-label">Quantity</label><input type="number" class="form-control" value="1" min="0"></div>
        <div class="mb-3"><label class="form-label">Step 0.5</label><input type="number" class="form-control" value="2.5" step="0.5"></div>
        <div class="mb-0"><label class="form-label">With unit</label><div class="input-group"><input type="number" class="form-control" value="500"><span class="input-group-text">g</span></div></div>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Custom stepper</h2>
        <label class="form-label">Cart quantity</label>
        <div class="input-group" style="max-width:160px">
            <button class="btn btn-outline-secondary uk-step-btn" data-step="-1" type="button"><i class="bi bi-dash"></i></button>
            <input class="form-control text-center" id="ukQty" value="1" inputmode="numeric">
            <button class="btn btn-outline-secondary uk-step-btn" data-step="1" type="button"><i class="bi bi-plus"></i></button>
        </div>
        <p class="text-secondary small mt-2 mb-0">Clamped between 1 and 99.</p>
    </div></div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var qty = document.getElementById('ukQty');
    document.querySelectorAll('.uk-step-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            var v = (parseInt(qty.value, 10) || 0) + parseInt(b.dataset.step, 10);
            qty.value = Math.min(99, Math.max(1, v));
        });
    });
})();
</script>
<?= $this->endSection() ?>
