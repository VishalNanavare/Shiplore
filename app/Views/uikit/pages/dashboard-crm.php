<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="row g-3 mb-3">
    <?php
    $kpis = [
        ['New Leads', '486', 'bi-person-plus', 'primary','+8%','success'],
        ['Open Deals', '128', 'bi-briefcase', 'info','+3%','success'],
        ['Won Rate', '54%', 'bi-trophy', 'success','+5%','success'],
        ['Pipeline', '₹42.8L', 'bi-funnel', 'warning','-2%','danger'],
    ];
    foreach ($kpis as [$label, $val, $icon, $color, $delta, $dc]): ?>
        <div class="col-sm-6 col-xl-3"><div class="card uk-card h-100"><div class="card-body uk-stat">
            <div class="uk-stat-icon bg-<?= $color ?>-subtle text-<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
            <div><div class="text-secondary small"><?= $label ?></div><div class="h4 mb-0"><?= $val ?></div><span class="small text-<?= $dc ?>"><?= $delta ?></span></div>
        </div></div></div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Deals by Stage</h2>
        <canvas id="chartFunnel" height="120"></canvas>
    </div></div></div>
    <div class="col-lg-5"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Lead Sources</h2>
        <canvas id="chartSources" height="120"></canvas>
    </div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8"><div class="card uk-card h-100"><div class="card-body p-0">
        <h2 class="uk-section-title p-3 pb-0">Top Deals</h2>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead><tr><th>Company</th><th>Owner</th><th>Value</th><th>Stage</th><th class="text-end">Probability</th></tr></thead>
            <tbody>
            <?php foreach ([
                ['Acme Corp','R. Iyer','₹4.2L','Negotiation','warning','80%'],
                ['Globex','S. Nair','₹2.9L','Proposal','info','60%'],
                ['Initech','A. Khan','₹1.1L','Qualified','primary','35%'],
                ['Umbrella','M. Das','₹3.6L','Won','success','100%'],
                ['Soylent','K. Menon','₹1.8L','Proposal','info','55%'],
            ] as $d): ?>
                <tr><td class="fw-medium"><?= $d[0] ?></td><td><?= $d[1] ?></td><td><?= $d[2] ?></td><td><span class="badge bg-<?= $d[4] ?>-subtle text-<?= $d[4] ?>"><?= $d[3] ?></span></td><td class="text-end"><?= $d[5] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div></div></div>
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Top Agents</h2>
        <?php foreach ([['RI','Riya Iyer','42 deals','primary',1],['SN','Sahil Nair','38 deals','success',2],['AK','Aman Khan','29 deals','warning',3],['MD','Meera Das','21 deals','info',4]] as $a): ?>
            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="text-secondary fw-bold" style="width:18px">#<?= $a[4] ?></span>
                <span class="rounded-circle bg-<?= $a[3] ?>-subtle text-<?= $a[3] ?> d-grid" style="width:36px;height:36px;place-items:center;font-weight:600;font-size:.72rem"><?= $a[0] ?></span>
                <div class="flex-grow-1"><div class="small fw-medium"><?= $a[1] ?></div><div class="text-secondary small"><?= $a[2] ?></div></div>
            </div>
        <?php endforeach; ?>
    </div></div></div>
</div>

<div class="card uk-card"><div class="card-body">
    <h2 class="uk-section-title mb-3">Recent Activity</h2>
    <div class="uk-timeline mt-1">
        <?php foreach ([
            ['Deal won — Acme Corp','₹4.2L · 2h ago'],['Call scheduled — Globex','Tomorrow 11:00'],
            ['New lead — Initech','via website · 5h ago'],['Proposal sent — Umbrella','₹1.8L · yesterday'],
        ] as $t): ?>
            <div class="uk-timeline-item"><div class="fw-medium small"><?= $t[0] ?></div><div class="text-secondary small"><?= $t[1] ?></div></div>
        <?php endforeach; ?>
    </div>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var c = window.UK.colors;
    new Chart(document.getElementById('chartFunnel'), { type: 'bar',
        data: { labels: ['Lead','Qualified','Proposal','Negotiation','Won'], datasets: [{ label: 'Deals', data: [486,260,150,90,68], backgroundColor: [c.grey,c.info,c.primary,c.warning,c.success], borderRadius: 6 }] },
        options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } } });
    new Chart(document.getElementById('chartSources'), { type: 'polarArea',
        data: { labels: ['Website','Referral','Ads','Events'], datasets: [{ data: [45,25,20,10], backgroundColor: [c.primary,c.success,c.warning,c.info] }] },
        options: { plugins: { legend: { position: 'right' } } } });
})();
</script>
<?= $this->endSection() ?>
