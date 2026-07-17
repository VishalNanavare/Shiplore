<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Bootstrap native toasts — lightweight push notifications triggered via JS.</p>

<div class="card uk-card mb-3"><div class="card-body">
    <h2 class="uk-section-title mb-3">Trigger</h2>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-primary" id="btnToast">Show toast</button>
        <button class="btn btn-success" id="btnToastSuccess">Success toast</button>
    </div>
    <p class="text-secondary small mt-2 mb-0">For richer notifications (positions, progress bar) see the <a href="<?= site_url('ui-kit/toastr') ?>">Toastr</a> extension.</p>
</div></div>

<div class="card uk-card"><div class="card-body">
    <h2 class="uk-section-title mb-3">Static example</h2>
    <div class="toast show" role="alert">
        <div class="toast-header"><span class="rounded bg-primary me-2" style="width:18px;height:18px"></span>
            <strong class="me-auto">Shiplore</strong><small>just now</small>
            <button class="btn-close" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body">A new order has arrived.</div>
    </div>
</div></div>

<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="liveToast" class="toast" role="alert">
        <div class="toast-header"><span class="rounded bg-primary me-2" style="width:18px;height:18px"></span>
            <strong class="me-auto">Notification</strong><small>now</small><button class="btn-close" data-bs-dismiss="toast"></button></div>
        <div class="toast-body">This is a Bootstrap toast.</div>
    </div>
    <div id="liveToastOk" class="toast align-items-center text-bg-success border-0" role="alert">
        <div class="d-flex"><div class="toast-body"><i class="bi bi-check-circle me-1"></i>Saved successfully.</div>
            <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.getElementById('btnToast').addEventListener('click', function () { bootstrap.Toast.getOrCreateInstance(document.getElementById('liveToast')).show(); });
document.getElementById('btnToastSuccess').addEventListener('click', function () { bootstrap.Toast.getOrCreateInstance(document.getElementById('liveToastOk')).show(); });
</script>
<?= $this->endSection() ?>
