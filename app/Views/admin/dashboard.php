<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="h4 mb-0">Welcome back, <?= esc($userName ?? 'User') ?></h2>
        <p class="text-secondary mb-0">Live overview of the marketplace.</p>
    </div>
    <a href="<?= site_url('ui-kit') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-palette me-1"></i> UI Kit</a>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Vendors', (int) ($metrics['vendors'] ?? 0), 'bi-shop', site_url('admin/vendors')],
        ['Pending Approvals', (int) ($metrics['pending_vendors'] ?? 0), 'bi-patch-question', site_url('admin/vendors?status=submitted')],
        ['Orders', (int) ($metrics['orders'] ?? 0), 'bi-bag-check', null],
        ['Customers', (int) ($metrics['customers'] ?? 0), 'bi-people', null],
        ['Manufacturers', (int) ($metrics['manufacturers'] ?? 0), 'bi-building', site_url('admin/manufacturers')],
        ['Pending Manufacturers', (int) ($metrics['pending_manufacturers'] ?? 0), 'bi-patch-exclamation', site_url('admin/manufacturers?status=submitted')],
        ['Open B2B Orders', (int) ($metrics['open_purchase_orders'] ?? 0), 'bi-receipt', site_url('admin/purchase-orders')],
    ] as [$label, $value, $icon, $link]): ?>
        <div class="col-6 col-xl-3">
            <div class="card kpi-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="kpi-icon"><i class="bi <?= esc($icon, 'attr') ?>"></i></div>
                    <div>
                        <div class="text-secondary small"><?= esc($label) ?></div>
                        <div class="kpi-value"><?= esc((string) $value) ?></div>
                    </div>
                    <?php if ($link): ?><a href="<?= esc($link, 'attr') ?>" class="stretched-link" aria-label="<?= esc($label, 'attr') ?>"></a><?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header fw-semibold">Recent Orders</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Order</th><th>Vendor</th><th>Amount</th><th>Status</th></tr></thead>
            <tbody>
                <?php if (empty($recentOrders)): ?>
                    <tr><td colspan="4" class="text-center text-secondary py-4">No orders yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentOrders as $o): ?>
                        <tr>
                            <td><?= esc($o['sub_order_no']) ?></td>
                            <td><?= esc($o['vendor'] ?? '—') ?></td>
                            <td>&#8377;<?= esc(number_format((float) $o['grand_total'], 2)) ?></td>
                            <td><span class="badge text-bg-secondary"><?= esc(str_replace('_', ' ', (string) $o['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
