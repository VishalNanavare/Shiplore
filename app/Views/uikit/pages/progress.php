<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Progress indicators — colors, labels, heights, striped, animated, stacked, with stats and circular.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Colors & labels</h2>
        <?php foreach (['primary'=>25,'success'=>50,'info'=>75,'warning'=>90,'danger'=>100] as $c=>$v): ?>
            <div class="progress mb-3" role="progressbar"><div class="progress-bar bg-<?= $c ?>" style="width:<?= $v ?>%"><?= $v ?>%</div></div>
        <?php endforeach; ?>
        <h2 class="uk-section-title mb-3">Heights</h2>
        <div class="progress mb-2" style="height:3px"><div class="progress-bar" style="width:40%"></div></div>
        <div class="progress mb-2" style="height:8px"><div class="progress-bar bg-success" style="width:60%"></div></div>
        <div class="progress" style="height:16px"><div class="progress-bar bg-info" style="width:80%"></div></div>
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Striped, animated & stacked</h2>
        <div class="progress mb-3"><div class="progress-bar progress-bar-striped bg-success" style="width:60%"></div></div>
        <div class="progress mb-3"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:80%"></div></div>
        <div class="progress mb-4"><div class="progress-bar bg-success" style="width:35%"></div><div class="progress-bar bg-warning" style="width:25%"></div><div class="progress-bar bg-danger" style="width:15%"></div></div>
        <h2 class="uk-section-title mb-3">With labels above</h2>
        <?php foreach (['Storage'=>['68%','primary'],'Bandwidth'=>['42%','info']] as $l=>$d): ?>
            <div class="d-flex justify-content-between small mb-1"><span><?= $l ?></span><span class="text-secondary"><?= $d[0] ?></span></div>
            <div class="progress mb-3" style="height:6px"><div class="progress-bar bg-<?= $d[1] ?>" style="width:<?= $d[0] ?>"></div></div>
        <?php endforeach; ?>
    </div></div></div>
</div>

<div class="card uk-card mt-3"><div class="card-body">
    <h2 class="uk-section-title mb-3">Circular (SVG)</h2>
    <div class="d-flex gap-4 flex-wrap">
        <?php foreach (['primary'=>72,'success'=>88,'warning'=>45,'danger'=>30] as $c=>$pct): $deg = $pct*3.6; ?>
            <div class="text-center">
                <div class="rounded-circle d-grid" style="width:96px;height:96px;place-items:center;background:conic-gradient(var(--bs-<?= $c ?>) <?= $deg ?>deg,#eceef5 0)">
                    <div class="rounded-circle bg-white d-grid" style="width:72px;height:72px;place-items:center"><span class="fw-semibold text-<?= $c ?>"><?= $pct ?>%</span></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div></div>
<?= $this->endSection() ?>
