<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="row g-3 mb-3">
    <?php
    $kpis = [
        ['Active Projects', '12', 'bi-kanban', 'primary'],
        ['Tasks Done', '348/420', 'bi-check2-square', 'success'],
        ['Hours Logged', '1,284', 'bi-clock-history', 'info'],
        ['Budget Used', '68%', 'bi-wallet2', 'warning'],
    ];
    foreach ($kpis as [$label, $val, $icon, $color]): ?>
        <div class="col-sm-6 col-xl-3"><div class="card uk-card h-100"><div class="card-body uk-stat">
            <div class="uk-stat-icon bg-<?= $color ?>-subtle text-<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
            <div><div class="text-secondary small"><?= $label ?></div><div class="h4 mb-0"><?= $val ?></div></div>
        </div></div></div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Burndown</h2>
        <canvas id="chartBurn" height="105"></canvas>
    </div></div></div>
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Team</h2>
        <?php foreach ([['RI','Riya Iyer','Lead','primary'],['SN','Sahil Nair','Dev','success'],['AK','Aman Khan','Design','info'],['MD','Meera Das','QA','warning']] as $m): ?>
            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="rounded-circle bg-<?= $m[3] ?>-subtle text-<?= $m[3] ?> d-grid" style="width:38px;height:38px;place-items:center;font-weight:600"><?= $m[0] ?></span>
                <div class="flex-grow-1"><div class="fw-medium small"><?= $m[1] ?></div><div class="text-secondary small"><?= $m[2] ?></div></div>
                <span class="badge bg-success-subtle text-success">online</span>
            </div>
        <?php endforeach; ?>
    </div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Project Progress</h2>
        <?php foreach ([['Mobile App Revamp',82,'success','12 Jun'],['POS Offline Sync',64,'primary','20 Jun'],['Vendor Onboarding',45,'info','28 Jun'],['GST Integration',28,'warning','05 Jul']] as $p): ?>
            <div class="d-flex justify-content-between small mb-1"><span class="fw-medium"><?= $p[0] ?></span><span class="text-secondary">Due <?= $p[3] ?> · <?= $p[1] ?>%</span></div>
            <div class="progress mb-3" style="height:8px"><div class="progress-bar bg-<?= $p[2] ?>" style="width:<?= $p[1] ?>%"></div></div>
        <?php endforeach; ?>
    </div></div></div>
    <div class="col-lg-5"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Upcoming Deadlines</h2>
        <?php foreach ([['Sprint review','12 Jun','danger'],['Client demo','15 Jun','warning'],['Release v2.1','20 Jun','primary'],['Retrospective','22 Jun','secondary']] as $d): ?>
            <div class="d-flex align-items-center gap-3 py-2 border-bottom">
                <div class="text-center" style="width:42px"><div class="fw-bold text-<?= $d[2] ?>"><?= explode(' ',$d[1])[0] ?></div><div class="text-secondary" style="font-size:.62rem"><?= explode(' ',$d[1])[1] ?></div></div>
                <span class="small fw-medium flex-grow-1"><?= $d[0] ?></span>
                <span class="badge bg-<?= $d[2] ?>-subtle text-<?= $d[2] ?>">soon</span>
            </div>
        <?php endforeach; ?>
    </div></div></div>
</div>

<div class="card uk-card"><div class="card-body">
    <h2 class="uk-section-title mb-3">Task Activity</h2>
    <div class="uk-timeline mt-1">
        <?php foreach ([['Riya merged PR #482','Auth + sessions · 1h ago'],['Sahil moved "POS sync" to Review','2h ago'],['Aman uploaded new mockups','Dashboard v2 · 4h ago'],['Meera closed 6 QA tickets','yesterday']] as $t): ?>
            <div class="uk-timeline-item"><div class="fw-medium small"><?= $t[0] ?></div><div class="text-secondary small"><?= $t[1] ?></div></div>
        <?php endforeach; ?>
    </div>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var c = window.UK.colors;
    new Chart(document.getElementById('chartBurn'), { type: 'line',
        data: { labels: ['W1','W2','W3','W4','W5','W6'], datasets: [
            { label: 'Ideal', data: [420,336,252,168,84,0], borderColor: c.grey, borderDash: [6,4], tension: 0 },
            { label: 'Actual', data: [420,360,300,210,150,72], borderColor: c.primary, backgroundColor: 'rgba(91,110,245,.12)', fill: true, tension: .35 } ] },
        options: { plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } } });
})();
</script>
<?= $this->endSection() ?>
