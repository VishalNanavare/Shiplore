<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Toastr — non-blocking notifications. Defaults configured in <code class="uk-code">uikit.js</code>.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Types</h2>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-success" data-toastr="success">Success</button>
            <button class="btn btn-info" data-toastr="info">Info</button>
            <button class="btn btn-warning" data-toastr="warning">Warning</button>
            <button class="btn btn-danger" data-toastr="error">Error</button>
        </div>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Positions</h2>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-light" data-pos="toast-top-left">Top left</button>
            <button class="btn btn-light" data-pos="toast-top-center">Top center</button>
            <button class="btn btn-light" data-pos="toast-bottom-right">Bottom right</button>
        </div>
    </div></div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.querySelectorAll('[data-toastr]').forEach(function (b) {
    b.addEventListener('click', function () {
        toastr.options.positionClass = 'toast-top-right';
        toastr[b.dataset.toastr]('This is a ' + b.dataset.toastr + ' message.', b.dataset.toastr.toUpperCase());
    });
});
document.querySelectorAll('[data-pos]').forEach(function (b) {
    b.addEventListener('click', function () {
        toastr.options.positionClass = b.dataset.pos;
        toastr.info('Positioned at ' + b.dataset.pos);
    });
});
</script>
<?= $this->endSection() ?>
