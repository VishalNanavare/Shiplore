<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<!-- KPI row -->
<div class="row g-3 mb-3">
    <?php
    $kpis = [
        ['Revenue', '₹8,42,300', 'bi-currency-rupee', 'primary', '+18%','success'],
        ['Orders', '1,932', 'bi-bag-check', 'success', '+9%','success'],
        ['Customers', '6,540', 'bi-people', 'info', '+5%','success'],
        ['Refunds', '₹21,400', 'bi-arrow-counterclockwise', 'danger', '-3%','danger'],
    ];
    foreach ($kpis as [$label, $val, $icon, $color, $delta, $dc]): ?>
        <div class="col-sm-6 col-xl-3"><div class="card uk-card h-100"><div class="card-body uk-stat">
            <div class="uk-stat-icon bg-<?= $color ?>-subtle text-<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
            <div><div class="text-secondary small"><?= $label ?></div><div class="h4 mb-0"><?= $val ?></div><span class="small text-<?= $dc ?>"><?= $delta ?> MoM</span></div>
        </div></div></div>
    <?php endforeach; ?>
</div>

<!-- Sales + status -->
<div class="row g-3 mb-3">
    <div class="col-lg-8"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Sales Overview</h2>
        <canvas id="chartSales" height="105"></canvas>
    </div></div></div>
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Order Status</h2>
        <canvas id="chartStatus" height="180"></canvas>
    </div></div></div>
</div>

<!-- Top products + activity + stock alerts -->
<div class="row g-3 mb-3">
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Top Products</h2>
        <?php foreach ([['Wireless Earbuds','842 sold',86,'primary'],['Running Shoes','610 sold',72,'success'],['Smart Watch','540 sold',64,'info'],['Coffee Beans','390 sold',48,'warning']] as $p): ?>
            <div class="d-flex justify-content-between small mb-1"><span class="fw-medium"><?= $p[0] ?></span><span class="text-secondary"><?= $p[1] ?></span></div>
            <div class="progress mb-3" style="height:6px"><div class="progress-bar bg-<?= $p[3] ?>" style="width:<?= $p[2] ?>%"></div></div>
        <?php endforeach; ?>
    </div></div></div>
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Recent Activity</h2>
        <div class="uk-timeline mt-1">
            <?php foreach ([['New order #10293','₹2,450 · 2m ago'],['Refund issued #10288','₹430 · 18m ago'],['New customer signup','Aarav S. · 40m ago'],['Stock replenished','Earbuds +200 · 1h ago']] as $a): ?>
                <div class="uk-timeline-item"><div class="small fw-medium"><?= $a[0] ?></div><div class="text-secondary small"><?= $a[1] ?></div></div>
            <?php endforeach; ?>
        </div>
    </div></div></div>
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Low Stock Alerts</h2>
        <?php foreach ([['Wireless Earbuds',4,'danger'],['Smart Watch',9,'warning'],['Yoga Mat',12,'warning'],['Backpack',18,'info']] as $s): ?>
            <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                <span class="small fw-medium"><?= $s[0] ?></span>
                <span class="badge bg-<?= $s[2] ?>-subtle text-<?= $s[2] ?>"><?= $s[1] ?> left</span>
            </div>
        <?php endforeach; ?>
        <button class="btn btn-sm btn-outline-primary w-100 mt-3">Restock all</button>
    </div></div></div>
</div>

<!-- Recent orders -->
<div class="card uk-card"><div class="card-body p-0">
    <div class="d-flex justify-content-between align-items-center p-3 pb-0"><h2 class="uk-section-title mb-0">Recent Orders</h2><a href="<?= site_url('ui-kit/invoice-list') ?>" class="small">View all</a></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ([
            ['#10293','Aarav Sharma','08 Jun','₹2,450','Paid','success'],
            ['#10294','Diya Patel','08 Jun','₹980','Pending','warning'],
            ['#10295','Vivaan Mehta','07 Jun','₹5,120','Paid','success'],
            ['#10296','Anaya Rao','07 Jun','₹430','Refunded','danger'],
            ['#10297','Kabir Singh','06 Jun','₹1,760','Shipped','info'],
        ] as $o): ?>
            <tr><td class="fw-medium"><?= $o[0] ?></td><td><?= $o[1] ?></td><td><?= $o[2] ?></td><td><?= $o[3] ?></td><td><span class="badge bg-<?= $o[5] ?>-subtle text-<?= $o[5] ?>"><?= $o[4] ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var c = window.UK.colors;
    new Chart(document.getElementById('chartSales'), { type: 'line',
        data: { labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug'], datasets: [{ label: 'Revenue (₹k)', data: [120,150,140,180,170,210,230,250], borderColor: c.primary, backgroundColor: 'rgba(91,110,245,.12)', fill: true, tension: .4 }] },
        options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } } });
    new Chart(document.getElementById('chartStatus'), { type: 'pie',
        data: { labels: ['Paid','Pending','Shipped','Refunded'], datasets: [{ data: [62,15,18,5], backgroundColor: [c.success,c.warning,c.info,c.danger] }] },
        options: { plugins: { legend: { position: 'bottom' } } } });
})();
</script>
<?= $this->endSection() ?>
