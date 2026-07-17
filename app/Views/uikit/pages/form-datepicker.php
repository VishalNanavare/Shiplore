<?= $this->extend('uikit/_layout') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/flatpickr/flatpickr.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Date &amp; time selection powered by Flatpickr (loaded locally).</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Pickers</h2>
        <div class="mb-3"><label class="form-label">Date</label><input class="form-control" id="fpDate" placeholder="Select date"></div>
        <div class="mb-3"><label class="form-label">Date &amp; time</label><input class="form-control" id="fpDateTime" placeholder="Select date & time"></div>
        <div class="mb-0"><label class="form-label">Time only</label><input class="form-control" id="fpTime" placeholder="Select time"></div>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Range &amp; inline</h2>
        <div class="mb-3"><label class="form-label">Date range</label><input class="form-control" id="fpRange" placeholder="Start – End"></div>
        <label class="form-label">Inline</label>
        <div id="fpInline"></div>
    </div></div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/flatpickr/flatpickr.min.js') ?>"></script>
<script>
flatpickr('#fpDate', { dateFormat: 'd M Y' });
flatpickr('#fpDateTime', { enableTime: true, dateFormat: 'd M Y H:i' });
flatpickr('#fpTime', { enableTime: true, noCalendar: true, dateFormat: 'H:i' });
flatpickr('#fpRange', { mode: 'range', dateFormat: 'd M Y' });
flatpickr('#fpInline', { inline: true });
</script>
<?= $this->endSection() ?>
