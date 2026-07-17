<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Chart.js gallery — line, bar, pie, doughnut, radar and area. Loaded locally.</p>

<div class="row g-3">
    <?php foreach ([
        ['Line','cLine'],['Bar','cBar'],['Pie','cPie'],['Doughnut','cDough'],['Radar','cRadar'],['Area','cArea'],
    ] as [$t,$id]): ?>
        <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
            <h2 class="uk-section-title mb-3"><?= $t ?></h2>
            <canvas id="<?= $id ?>" height="160"></canvas>
        </div></div></div>
    <?php endforeach; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var c = window.UK.colors, L = ['Jan','Feb','Mar','Apr','May','Jun'];
    new Chart(cLine, { type:'line', data:{ labels:L, datasets:[{label:'A',data:[12,19,15,22,18,27],borderColor:c.primary,tension:.4},{label:'B',data:[8,12,10,15,13,17],borderColor:c.success,tension:.4}] }, options:{plugins:{legend:{position:'bottom'}}} });
    new Chart(cBar, { type:'bar', data:{ labels:L, datasets:[{label:'Sales',data:[320,210,280,160,190,240],backgroundColor:c.primary,borderRadius:6}] }, options:{plugins:{legend:{display:false}}} });
    new Chart(cPie, { type:'pie', data:{ labels:['A','B','C','D'], datasets:[{data:[40,25,20,15],backgroundColor:[c.primary,c.success,c.warning,c.danger]}] }, options:{plugins:{legend:{position:'bottom'}}} });
    new Chart(cDough, { type:'doughnut', data:{ labels:['A','B','C'], datasets:[{data:[55,30,15],backgroundColor:[c.info,c.primary,c.warning]}] }, options:{cutout:'62%',plugins:{legend:{position:'bottom'}}} });
    new Chart(cRadar, { type:'radar', data:{ labels:['Speed','Quality','Cost','Support','UX'], datasets:[{label:'Score',data:[80,90,70,85,75],borderColor:c.primary,backgroundColor:'rgba(91,110,245,.2)'}] } });
    new Chart(cArea, { type:'line', data:{ labels:L, datasets:[{label:'Revenue',data:[12,18,16,24,22,30],borderColor:c.success,backgroundColor:'rgba(40,199,111,.18)',fill:true,tension:.4}] }, options:{plugins:{legend:{display:false}}} });
})();
</script>
<?= $this->endSection() ?>
