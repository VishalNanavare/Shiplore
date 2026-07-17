<?= $this->extend('uikit/_layout') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/nouislider/nouislider.min.css') ?>">
<style>.noUi-connect{background:var(--uk-primary)}.noUi-horizontal{height:8px}.noUi-handle{border-radius:50%}</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Range sliders powered by noUiSlider (loaded locally).</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Single value</h2>
        <div class="d-flex justify-content-between small mb-2"><span class="text-secondary">Volume</span><span id="sVal" class="fw-medium">40</span></div>
        <div id="sSingle"></div>
        <h2 class="uk-section-title mt-4 mb-3">Stepped</h2>
        <div id="sStep" class="mb-2"></div>
        <div class="small text-secondary">Snaps to steps of 10.</div>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Price range filter</h2>
        <div class="d-flex justify-content-between small mb-2"><span id="pLo" class="fw-medium">₹500</span><span id="pHi" class="fw-medium">₹4,000</span></div>
        <div id="sRange"></div>
        <button class="btn btn-sm btn-primary mt-4">Apply filter</button>
    </div></div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/nouislider/nouislider.min.js') ?>"></script>
<script>
var s1 = document.getElementById('sSingle');
noUiSlider.create(s1, { start: 40, connect: 'lower', range: { min: 0, max: 100 } });
s1.noUiSlider.on('update', function (v) { document.getElementById('sVal').textContent = Math.round(v[0]); });

noUiSlider.create(document.getElementById('sStep'), { start: 50, connect: 'lower', step: 10, range: { min: 0, max: 100 } });

var sr = document.getElementById('sRange');
noUiSlider.create(sr, { start: [500, 4000], connect: true, range: { min: 0, max: 5000 } });
sr.noUiSlider.on('update', function (v) {
    document.getElementById('pLo').textContent = '₹' + Math.round(v[0]).toLocaleString('en-IN');
    document.getElementById('pHi').textContent = '₹' + Math.round(v[1]).toLocaleString('en-IN');
});
</script>
<?= $this->endSection() ?>
