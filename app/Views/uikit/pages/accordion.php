<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Collapse & accordion — default, flush, always-open, with icons, and inline collapse.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Default accordion</h2>
        <div class="accordion" id="acc1">
            <?php
            $items = ['What is Shiplore?'=>'A multi-vendor omnichannel commerce platform.','How do payouts work?'=>'Settlements run on the configured cycle per vendor.','Is GST handled?'=>'Yes — inclusive and exclusive per HSN.'];
            $i = 0; foreach ($items as $q => $a): $i++; ?>
                <div class="accordion-item">
                    <h2 class="accordion-header"><button class="accordion-button <?= $i>1?'collapsed':'' ?>" data-bs-toggle="collapse" data-bs-target="#a1<?= $i ?>"><?= esc($q) ?></button></h2>
                    <div id="a1<?= $i ?>" class="accordion-collapse collapse <?= $i===1?'show':'' ?>" data-bs-parent="#acc1"><div class="accordion-body small"><?= esc($a) ?></div></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Flush + icons (always open)</h2>
        <div class="accordion accordion-flush">
            <?php $rows = ['shield-check'=>['Security','We encrypt data at rest and in transit.'],'truck'=>['Shipping','Free delivery on eligible orders.'],'arrow-counterclockwise'=>['Returns','7-day no-questions returns.']]; $i=0;
            foreach ($rows as $ic=>$r): $i++; ?>
                <div class="accordion-item">
                    <h2 class="accordion-header"><button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#a2<?= $i ?>"><i class="bi bi-<?= $ic ?> me-2 text-primary"></i><?= $r[0] ?></button></h2>
                    <div id="a2<?= $i ?>" class="accordion-collapse collapse"><div class="accordion-body small text-secondary"><?= $r[1] ?></div></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div></div>
</div>

<div class="card uk-card mt-3"><div class="card-body">
    <h2 class="uk-section-title mb-3">Inline collapse</h2>
    <div class="d-flex gap-2 mb-3">
        <button class="btn btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#col1">Toggle A</button>
        <button class="btn btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#col1">Toggle (same)</button>
        <button class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target=".multi">Toggle both</button>
    </div>
    <div class="row">
        <div class="col"><div class="collapse multi" id="col1"><div class="card card-body small">Panel revealed via <code class="uk-code">data-bs-toggle="collapse"</code>.</div></div></div>
        <div class="col"><div class="collapse multi"><div class="card card-body small">A second panel toggled by class selector.</div></div></div>
    </div>
</div></div>
<?= $this->endSection() ?>
