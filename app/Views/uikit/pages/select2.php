<?= $this->extend('uikit/_layout') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/select2/select2.min.css') ?>">
<link rel="stylesheet" href="<?= asset('plugins/select2/select2-bootstrap-5-theme.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Select2 with the Bootstrap 5 theme — single, multiple, tagging, grouping, templating and remote (AJAX). Loaded locally.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Single & placeholder</h2>
        <div class="mb-3"><label class="form-label">Basic</label>
            <select class="form-select s2"><option>Apparel</option><option>Grocery</option><option>Electronics</option><option>Footwear</option></select></div>
        <div class="mb-3"><label class="form-label">With placeholder & clear</label>
            <select class="s2-clear form-select"><option></option><option>Mumbai</option><option>Pune</option><option>Bengaluru</option><option>Delhi</option></select></div>
        <div class="mb-0"><label class="form-label">Grouped</label>
            <select class="form-select s2">
                <optgroup label="Electronics"><option>Earbuds</option><option>Smart Watch</option></optgroup>
                <optgroup label="Apparel"><option>T-Shirt</option><option>Jacket</option></optgroup>
            </select></div>
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Multiple & tags</h2>
        <div class="mb-3"><label class="form-label">Multiple</label>
            <select class="s2 form-select" multiple>
                <option selected>Black</option><option>White</option><option selected>Blue</option><option>Red</option><option>Green</option>
            </select></div>
        <div class="mb-3"><label class="form-label">Tagging (create new)</label>
            <select class="s2-tags form-select" multiple><option selected>new-arrival</option><option selected>sale</option></select></div>
        <div class="mb-0"><label class="form-label">With icons (templating)</label>
            <select class="s2-icons form-select">
                <option value="success" data-icon="check-circle">Active</option>
                <option value="warning" data-icon="hourglass-split">Pending</option>
                <option value="danger" data-icon="x-circle">Suspended</option>
            </select></div>
    </div></div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/select2/select2.min.js') ?>"></script>
<script>
$(function () {
    var opts = { theme: 'bootstrap-5' };
    $('.s2').select2(opts);
    $('.s2-clear').select2($.extend({ placeholder: 'Select a city', allowClear: true }, opts));
    $('.s2-tags').select2($.extend({ tags: true, tokenSeparators: [','] }, opts));
    function fmt(o) {
        if (!o.id) return o.text;
        var icon = $(o.element).data('icon');
        return $('<span><i class="bi bi-' + icon + ' me-2"></i>' + o.text + '</span>');
    }
    $('.s2-icons').select2($.extend({ templateResult: fmt, templateSelection: fmt }, opts));
});
</script>
<?= $this->endSection() ?>
