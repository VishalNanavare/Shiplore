<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<!-- KPI row -->
<div class="row g-3 mb-3">
    <?php
    $kpis = [
        ['Sessions', '24,830', '+12.4%', 'success', 'bi-people', 'primary'],
        ['Avg. Time', '3m 42s', '+4.1%', 'success', 'bi-clock', 'info'],
        ['Bounce Rate', '38.2%', '-2.3%', 'success', 'bi-arrow-down-up', 'warning'],
        ['Conversions', '1,204', '-1.8%', 'danger', 'bi-bullseye', 'danger'],
    ];
    foreach ($kpis as [$label, $val, $delta, $deltaColor, $icon, $color]): ?>
        <div class="col-sm-6 col-xl-3"><div class="card uk-card h-100"><div class="card-body uk-stat">
            <div class="uk-stat-icon bg-<?= $color ?>-subtle text-<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
            <div><div class="text-secondary small"><?= $label ?></div><div class="h4 mb-0"><?= $val ?></div><span class="small text-<?= $deltaColor ?>"><?= $delta ?> vs last week</span></div>
        </div></div></div>
    <?php endforeach; ?>
</div>

<!-- Trend + traffic -->
<div class="row g-3 mb-3">
    <div class="col-lg-8"><div class="card uk-card h-100"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="uk-section-title mb-0">Revenue & Sessions</h2>
            <div class="btn-group btn-group-sm"><button class="btn btn-outline-secondary">7d</button><button class="btn btn-primary">30d</button><button class="btn btn-outline-secondary">90d</button></div></div>
        <canvas id="chartTrend" height="105"></canvas>
    </div></div></div>
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Traffic Source</h2>
        <canvas id="chartTraffic" height="180"></canvas>
    </div></div></div>
</div>

<!-- Devices + goals + countries -->
<div class="row g-3 mb-3">
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Sessions by Device</h2>
        <?php foreach ([['Desktop','laptop','primary',58],['Mobile','phone','success',34],['Tablet','tablet','warning',8]] as $d): ?>
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="uk-stat-icon bg-<?= $d[2] ?>-subtle text-<?= $d[2] ?>" style="width:36px;height:36px;font-size:1rem"><i class="bi bi-<?= $d[1] ?>"></i></span>
                <div class="flex-grow-1"><div class="d-flex justify-content-between small"><span><?= $d[0] ?></span><span class="text-secondary"><?= $d[3] ?>%</span></div>
                    <div class="progress" style="height:5px"><div class="progress-bar bg-<?= $d[2] ?>" style="width:<?= $d[3] ?>%"></div></div></div>
            </div>
        <?php endforeach; ?>
    </div></div></div>
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body text-center">
        <h2 class="uk-section-title mb-3 text-start">Monthly Goal</h2>
        <canvas id="chartGoal" height="160"></canvas>
        <div class="mt-2"><span class="h4">68%</span><div class="text-secondary small">₹6.8L of ₹10L target</div></div>
    </div></div></div>
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Top Countries</h2>
        <?php foreach ([['India','🇮🇳','62%','primary'],['USA','🇺🇸','18%','info'],['UK','🇬🇧','9%','success'],['UAE','🇦🇪','6%','warning'],['Other','🌐','5%','secondary']] as $c): ?>
            <div class="d-flex align-items-center justify-content-between py-1">
                <span class="small"><span class="me-2"><?= $c[1] ?></span><?= $c[0] ?></span>
                <span class="d-flex align-items-center gap-2" style="width:120px"><div class="progress flex-grow-1" style="height:5px"><div class="progress-bar bg-<?= $c[3] ?>" style="width:<?= $c[2] ?>"></div></div><span class="text-secondary small" style="width:34px;text-align:right"><?= $c[2] ?></span></span>
            </div>
        <?php endforeach; ?>
    </div></div></div>
</div>

<!-- Category bar + top pages -->
<div class="row g-3">
    <div class="col-lg-5"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Sales by Category</h2>
        <canvas id="chartCat" height="180"></canvas>
    </div></div></div>
    <div class="col-lg-7"><div class="card uk-card h-100"><div class="card-body p-0">
        <h2 class="uk-section-title p-3 pb-0">Top Pages</h2>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead><tr><th>Page</th><th>Views</th><th>Unique</th><th class="text-end">Bounce</th></tr></thead>
            <tbody>
            <?php foreach ([['/home','8,240','6,120','32%'],['/products','5,901','4,330','41%'],['/checkout','3,120','2,780','22%'],['/blog','2,440','1,990','55%'],['/contact','1,210','980','48%']] as $r): ?>
                <tr><td class="fw-medium"><?= $r[0] ?></td><td><?= $r[1] ?></td><td><?= $r[2] ?></td><td class="text-end"><?= $r[3] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div></div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var c = window.UK.colors;
    new Chart(document.getElementById('chartTrend'), {
        type: 'line',
        data: { labels: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], datasets: [
            { label: 'Revenue', data: [12,19,15,22,18,27,24], borderColor: c.primary, backgroundColor: 'rgba(91,110,245,.12)', fill: true, tension: .4 },
            { label: 'Sessions', data: [8,12,10,15,13,17,16], borderColor: c.info, backgroundColor: 'transparent', tension: .4 } ] },
        options: { plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
    });
    new Chart(document.getElementById('chartTraffic'), { type: 'doughnut',
        data: { labels: ['Organic','Direct','Referral','Social'], datasets: [{ data: [45,25,18,12], backgroundColor: [c.primary,c.success,c.warning,c.danger] }] },
        options: { plugins: { legend: { position: 'bottom' } }, cutout: '62%' } });
    new Chart(document.getElementById('chartGoal'), { type: 'doughnut',
        data: { labels: ['Done','Left'], datasets: [{ data: [68,32], backgroundColor: [c.success,'#eceef5'] }] },
        options: { plugins: { legend: { display: false } }, cutout: '72%', circumference: 220, rotation: 250 } });
    new Chart(document.getElementById('chartCat'), { type: 'bar',
        data: { labels: ['Apparel','Grocery','Electronics','Footwear','Home'], datasets: [{ label: 'Sales', data: [320,210,280,160,190], backgroundColor: c.primary, borderRadius: 6 }] },
        options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } } });
})();
</script>
<?= $this->endSection() ?>
