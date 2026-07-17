<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Multi-step forms — horizontal stepper, vertical wizard and a validated wizard.</p>

<!-- 1. Horizontal numbered -->
<div class="card uk-card mb-4"><div class="card-body">
    <h2 class="uk-section-title mb-3">Horizontal stepper</h2>
    <div class="d-flex justify-content-between mb-4" data-wiz="h">
        <?php $steps = ['Account'=>'person','Personal'=>'card-text','Address'=>'geo-alt','Review'=>'check2']; $k=0; foreach ($steps as $s=>$ic): ?>
            <div class="text-center flex-fill wiz-step" data-i="<?= $k ?>">
                <span class="rounded-circle d-grid mx-auto mb-1 <?= $k===0?'bg-primary text-white':'bg-light text-secondary' ?>" style="width:40px;height:40px;place-items:center"><i class="bi bi-<?= $ic ?>"></i></span>
                <div class="small fw-medium"><?= $s ?></div>
            </div>
        <?php $k++; endforeach; ?>
    </div>
    <div class="wiz-pane" data-p="0"><div class="row g-3"><div class="col-md-6"><label class="form-label">Username</label><input class="form-control"></div><div class="col-md-6"><label class="form-label">Email</label><input class="form-control"></div></div></div>
    <div class="wiz-pane d-none" data-p="1"><div class="row g-3"><div class="col-md-6"><label class="form-label">First name</label><input class="form-control"></div><div class="col-md-6"><label class="form-label">Mobile</label><input class="form-control"></div></div></div>
    <div class="wiz-pane d-none" data-p="2"><div class="row g-3"><div class="col-12"><label class="form-label">Address</label><input class="form-control"></div><div class="col-md-6"><label class="form-label">City</label><input class="form-control"></div><div class="col-md-6"><label class="form-label">PIN</label><input class="form-control"></div></div></div>
    <div class="wiz-pane d-none" data-p="3"><div class="alert alert-success mb-0"><i class="bi bi-check-circle me-1"></i>All set — review and submit.</div></div>
    <div class="d-flex justify-content-between mt-4"><button class="btn btn-light wiz-prev" disabled>Previous</button><button class="btn btn-primary wiz-next">Next</button></div>
</div></div>

<!-- 2. Vertical -->
<div class="card uk-card mb-4"><div class="card-body">
    <h2 class="uk-section-title mb-3">Vertical wizard</h2>
    <div class="row">
        <div class="col-md-3">
            <div class="nav flex-column" data-wiz="v">
                <?php foreach (['Cart','Shipping','Payment'] as $i=>$s): ?>
                    <div class="d-flex align-items-center gap-2 py-2 wiz-step" data-i="<?= $i ?>">
                        <span class="rounded-circle d-grid <?= $i===0?'bg-primary text-white':'bg-light text-secondary' ?>" style="width:32px;height:32px;place-items:center;font-weight:600"><?= $i+1 ?></span>
                        <span class="small fw-medium"><?= $s ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-md-9 border-start ps-4">
            <div class="wiz-pane" data-p="0"><h6>Your cart</h6><p class="text-secondary small mb-0">3 items · ₹5,397</p></div>
            <div class="wiz-pane d-none" data-p="1"><div class="row g-3"><div class="col-12"><label class="form-label">Address</label><input class="form-control"></div></div></div>
            <div class="wiz-pane d-none" data-p="2"><div class="form-check"><input class="form-check-input" type="radio" name="vpay" checked><label class="form-check-label small">UPI</label></div><div class="form-check"><input class="form-check-input" type="radio" name="vpay"><label class="form-check-label small">Card</label></div></div>
            <div class="d-flex justify-content-between mt-4"><button class="btn btn-light wiz-prev" disabled>Back</button><button class="btn btn-primary wiz-next">Continue</button></div>
        </div>
    </div>
</div></div>

<!-- 3. Validated -->
<div class="card uk-card"><div class="card-body">
    <h2 class="uk-section-title mb-3">Validated wizard</h2>
    <div class="progress mb-4" style="height:6px"><div class="progress-bar" id="vwBar" style="width:50%"></div></div>
    <form id="vwForm" novalidate>
        <div class="wiz-pane" data-p="0"><div class="row g-3">
            <div class="col-md-6"><label class="form-label">Full name *</label><input class="form-control" required><div class="invalid-feedback">Required.</div></div>
            <div class="col-md-6"><label class="form-label">Email *</label><input type="email" class="form-control" required><div class="invalid-feedback">Valid email required.</div></div>
        </div></div>
        <div class="wiz-pane d-none" data-p="1"><div class="row g-3">
            <div class="col-md-6"><label class="form-label">GSTIN *</label><input class="form-control" pattern="[0-9A-Za-z]{15}" required><div class="invalid-feedback">15 characters.</div></div>
            <div class="col-md-6"><label class="form-label">State *</label><select class="form-select" required><option value="">Choose…</option><option>MH</option><option>KA</option></select><div class="invalid-feedback">Select a state.</div></div>
        </div></div>
        <div class="d-flex justify-content-between mt-4"><button type="button" class="btn btn-light wiz-prev" disabled>Previous</button><button type="button" class="btn btn-primary" id="vwNext">Next</button></div>
    </form>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
// Generic stepper for [data-wiz] groups (panes are the nearest following .wiz-pane set in the same card)
document.querySelectorAll('.card').forEach(function (card) {
    var nav = card.querySelector('[data-wiz]');
    if (!nav) return;
    var steps = card.querySelectorAll('.wiz-step'), panes = card.querySelectorAll('.wiz-pane');
    var prev = card.querySelector('.wiz-prev'), next = card.querySelector('.wiz-next');
    if (!next) return; // validated wizard handled separately
    var i = 0;
    function paint() {
        panes.forEach(function (p, k) { p.classList.toggle('d-none', k !== i); });
        steps.forEach(function (s, k) { var c = s.querySelector('span'); c.className = c.className.replace(/bg-\S+|text-\S+/g, ''); c.classList.add(k <= i ? 'bg-primary' : 'bg-light', k <= i ? 'text-white' : 'text-secondary', 'rounded-circle', 'd-grid'); });
        prev.disabled = i === 0; next.textContent = i === panes.length - 1 ? 'Submit' : (next.dataset.label || 'Next');
    }
    next.dataset.label = next.textContent;
    next.addEventListener('click', function () { if (i < panes.length - 1) { i++; paint(); } else if (window.toastr) toastr.success('Completed!'); });
    prev.addEventListener('click', function () { if (i > 0) { i--; paint(); } });
});
// Validated wizard
(function () {
    var form = document.getElementById('vwForm'); if (!form) return;
    var panes = form.querySelectorAll('.wiz-pane'), prev = form.querySelector('.wiz-prev'), next = document.getElementById('vwNext'), bar = document.getElementById('vwBar'), i = 0;
    function show() { panes.forEach(function (p, k) { p.classList.toggle('d-none', k !== i); }); prev.disabled = i === 0; next.textContent = i === panes.length - 1 ? 'Submit' : 'Next'; bar.style.width = ((i + 1) / panes.length * 100) + '%'; }
    next.addEventListener('click', function () {
        var inputs = panes[i].querySelectorAll('input,select'), ok = true;
        inputs.forEach(function (el) { if (!el.checkValidity()) { el.classList.add('is-invalid'); ok = false; } else el.classList.remove('is-invalid'); });
        if (!ok) return;
        if (i < panes.length - 1) { i++; show(); } else if (window.toastr) toastr.success('Submitted!');
    });
    prev.addEventListener('click', function () { if (i > 0) { i--; show(); } });
})();
</script>
<?= $this->endSection() ?>
