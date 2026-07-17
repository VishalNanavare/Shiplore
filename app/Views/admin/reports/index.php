<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="row g-3 mb-3">
    <?php
    $cards = [
        ['Gross Sales', '₹' . number_format((float) $summary['gross_sales'], 0), 'currency-rupee', 'primary'],
        ['Orders', number_format((int) $summary['orders_total']), 'bag-check', 'success'],
        ['Commission', '₹' . number_format((float) $summary['commission_earned'], 0), 'percent', 'warning'],
        ['Refunds', '₹' . number_format((float) $summary['refunds_total'], 0), 'arrow-counterclockwise', 'danger'],
        ['Settlements Due', '₹' . number_format((float) $summary['settlements_due'], 0), 'cash-stack', 'info'],
        ['Active Vendors', number_format((int) $summary['active_vendors']), 'shop', 'primary'],
        ['Customers', number_format((int) $summary['customers']), 'people', 'success'],
        ['Pending Reviews', number_format((int) $summary['pending_reviews']), 'patch-check', 'warning'],
    ];
    foreach ($cards as [$label, $val, $icon, $color]): ?>
        <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
            <span class="rounded d-grid bg-<?= $color ?>-subtle text-<?= $color ?>" style="width:44px;height:44px;place-items:center;font-size:1.2rem"><i class="bi bi-<?= $icon ?>"></i></span>
            <div><div class="text-secondary small"><?= $label ?></div><div class="h5 mb-0"><?= $val ?></div></div>
        </div></div></div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-5"><div class="card h-100"><div class="card-header fw-semibold">Sales by Channel</div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Channel</th><th class="text-end">Orders</th><th class="text-end">Revenue</th></tr></thead>
            <tbody>
            <?php foreach ($byChannel as $c): ?>
                <tr><td class="text-uppercase"><?= esc($c['channel']) ?></td><td class="text-end"><?= esc($c['orders']) ?></td><td class="text-end">₹<?= esc(number_format((float) $c['revenue'], 2)) ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($byChannel)): ?><tr><td colspan="3" class="text-center text-secondary py-3">No data.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div></div>
    <div class="col-lg-7"><div class="card h-100"><div class="card-header fw-semibold">Top Vendors by Revenue</div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Vendor</th><th class="text-end">Orders</th><th class="text-end">Revenue</th></tr></thead>
            <tbody>
            <?php foreach ($topVendors as $v): ?>
                <tr><td class="fw-medium"><?= esc($v['vendor'] ?? '—') ?></td><td class="text-end"><?= esc($v['orders']) ?></td><td class="text-end">₹<?= esc(number_format((float) $v['revenue'], 2)) ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($topVendors)): ?><tr><td colspan="3" class="text-center text-secondary py-3">No data.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div></div>
</div>
<?= $this->endSection() ?>
