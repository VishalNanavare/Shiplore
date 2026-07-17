<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Modern, animated charts powered by ApexCharts (loaded locally).</p>

<div class="row g-3">
    <div class="col-lg-7"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Revenue (area)</h2>
        <div id="axArea"></div>
    </div></div></div>
    <div class="col-lg-5"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Order status (donut)</h2>
        <div id="axDonut"></div>
    </div></div></div>
    <div class="col-lg-7"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Sales by category (bar)</h2>
        <div id="axBar"></div>
    </div></div></div>
    <div class="col-lg-5"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Goal completion (radial)</h2>
        <div id="axRadial"></div>
    </div></div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/apexcharts/apexcharts.min.js') ?>"></script>
<script>
var c = window.UK.colors;
new ApexCharts(document.querySelector('#axArea'), {
    chart: { type: 'area', height: 300, toolbar: { show: false } },
    series: [{ name: 'Revenue', data: [120,150,140,180,170,210,230,250] }, { name: 'Orders', data: [80,90,85,110,100,130,140,150] }],
    xaxis: { categories: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug'] },
    colors: [c.primary, c.info], dataLabels: { enabled: false }, stroke: { curve: 'smooth', width: 2 }, fill: { opacity: .2 }, legend: { position: 'top' }
}).render();

new ApexCharts(document.querySelector('#axDonut'), {
    chart: { type: 'donut', height: 300 }, series: [62,15,18,5], labels: ['Paid','Pending','Shipped','Refunded'],
    colors: [c.success, c.warning, c.info, c.danger], legend: { position: 'bottom' }
}).render();

new ApexCharts(document.querySelector('#axBar'), {
    chart: { type: 'bar', height: 300, toolbar: { show: false } },
    series: [{ name: 'Sales', data: [320,210,280,160,190,240] }],
    xaxis: { categories: ['Apparel','Grocery','Electronics','Footwear','Home','Sports'] },
    colors: [c.primary], plotOptions: { bar: { borderRadius: 5, columnWidth: '50%' } }, dataLabels: { enabled: false }
}).render();

new ApexCharts(document.querySelector('#axRadial'), {
    chart: { type: 'radialBar', height: 300 }, series: [68, 84, 52], labels: ['Sales','Tasks','Budget'],
    colors: [c.primary, c.success, c.warning], plotOptions: { radialBar: { dataLabels: { total: { show: true, label: 'Avg' } } } }
}).render();
</script>
<?= $this->endSection() ?>
