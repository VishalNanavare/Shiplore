<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Series of content — basic, links, active/disabled, badges, contextual, flush, numbered, checkboxes and rich items.</p>

<div class="row g-3">
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Links & badges</h2>
        <div class="list-group mb-4">
            <a href="#" class="list-group-item list-group-item-action active d-flex justify-content-between"><span>Inbox</span><span class="badge bg-light text-dark">14</span></a>
            <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between"><span>Drafts</span><span class="badge bg-secondary">2</span></a>
            <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between"><span>Sent</span><span class="badge bg-secondary">120</span></a>
            <a href="#" class="list-group-item list-group-item-action disabled">Archived</a>
        </div>
        <h2 class="uk-section-title mb-3">Numbered</h2>
        <ol class="list-group list-group-numbered">
            <li class="list-group-item">First step</li><li class="list-group-item">Second step</li><li class="list-group-item">Third step</li>
        </ol>
    </div></div></div>

    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Contextual</h2>
        <div class="list-group mb-4">
            <div class="list-group-item list-group-item-primary">Primary</div>
            <div class="list-group-item list-group-item-success">Order paid</div>
            <div class="list-group-item list-group-item-warning">Awaiting stock</div>
            <div class="list-group-item list-group-item-danger">Payment failed</div>
        </div>
        <h2 class="uk-section-title mb-3">With checkboxes</h2>
        <div class="list-group">
            <label class="list-group-item d-flex gap-2"><input class="form-check-input m-0" type="checkbox" checked><span>Enable notifications</span></label>
            <label class="list-group-item d-flex gap-2"><input class="form-check-input m-0" type="checkbox"><span>Weekly digest</span></label>
            <label class="list-group-item d-flex gap-2"><input class="form-check-input m-0" type="checkbox"><span>Marketing emails</span></label>
        </div>
    </div></div></div>

    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Rich items</h2>
        <div class="list-group">
            <?php foreach ([['New vendor application','Demo Mart submitted GST docs','3d','warning','Pending'],['Order delivered','#10293 signed by customer','1d','success','Done'],['Refund requested','#10288 · ₹430','2h','danger','Action']] as $r): ?>
                <a href="#" class="list-group-item list-group-item-action text-reset text-decoration-none">
                    <div class="d-flex w-100 justify-content-between"><h6 class="mb-1 small"><?= $r[0] ?></h6><small class="text-secondary"><?= $r[2] ?></small></div>
                    <p class="mb-1 small text-secondary"><?= $r[1] ?></p>
                    <span class="badge bg-<?= $r[3] ?>-subtle text-<?= $r[3] ?>"><?= $r[4] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
