<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php $soBadge = ['pending' => 'secondary', 'confirmed' => 'primary', 'accepted' => 'primary', 'packed' => 'info', 'ready' => 'info', 'out_for_delivery' => 'warning', 'delivered' => 'success', 'completed' => 'success', 'cancelled' => 'danger', 'returned' => 'danger']; ?>
<div class="row g-3 mb-3">
    <?php
    $cards = [
        ['My Shops', $metrics['shops'], 'bi-geo-alt', 'primary', 'vendor/shops'],
        ['Products', $metrics['products'], 'bi-box-seam', 'info', 'vendor/products'],
        ['Open Orders', $metrics['open_orders'], 'bi-bag', 'warning', 'vendor/orders'],
        ['Sales (fulfilled)', '₹' . number_format((float) $metrics['sales'], 0), 'bi-cash-stack', 'success', 'vendor/settlements'],
    ];
    foreach ($cards as [$label, $val, $icon, $color, $link]): ?>
        <div class="col-sm-6 col-xl-3"><a href="<?= site_url($link) ?>" class="card text-reset text-decoration-none h-100"><div class="card-body d-flex align-items-center gap-3">
            <span class="rounded d-grid bg-<?= $color ?>-subtle text-<?= $color ?>" style="width:48px;height:48px;place-items:center;font-size:1.3rem"><i class="bi <?= $icon ?>"></i></span>
            <div><div class="text-secondary small"><?= $label ?></div><div class="h4 mb-0"><?= $val ?></div></div>
        </div></a></div>
    <?php endforeach; ?>
</div>

<?php
/*
 * monline — the wholesale marketplace where this vendor buys stock from manufacturers.
 * A banner rather than a 5th KPI card: it is a call to action, not a metric, and the
 * card grid above renders every link through site_url(), which would be wrong for a
 * cross-subdomain destination.
 *
 * monline is its own route group, gated to the monline. subdomain — it does NOT
 * resolve on vendor./shop. (see PanelSubdomainIsolationTest), so a plain site_url()
 * here would carry this panel's host with monline's path. panel_url() builds the
 * link on monline's own subdomain explicitly instead.
 */
?>
<div class="card mb-3 border-primary-subtle">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <span class="rounded d-grid bg-primary-subtle text-primary" style="width:48px;height:48px;place-items:center;font-size:1.3rem">
            <i class="bi bi-shop-window"></i>
        </span>
        <div class="flex-grow-1">
            <div class="fw-semibold">Buy stock on monline</div>
            <div class="text-secondary small">Order directly from manufacturers at wholesale prices.</div>
        </div>
        <a class="btn btn-outline-primary" href="<?= panel_url('monline', 'monline/orders') ?>">My purchase orders</a>
        <a class="btn btn-primary" href="<?= panel_url('monline', 'monline/browse') ?>">Browse catalogue</a>
    </div>
</div>

<?php if (! empty($perShop) && count($perShop) > 1): ?>
<div class="card mb-3">
    <div class="card-header fw-semibold">By store</div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Store</th><th>Status</th><th class="text-end">Open orders</th><th class="text-end">Sales (fulfilled)</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($perShop as $s): ?>
            <tr>
                <td class="fw-medium"><?= esc($s['name']) ?></td>
                <td><span class="badge text-bg-<?= ($s['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>"><?= esc($s['status']) ?></span></td>
                <td class="text-end"><?= esc($s['open_orders']) ?></td>
                <td class="text-end">₹<?= esc(number_format((float) $s['sales'], 0)) ?></td>
                <td class="text-end"><a href="<?= site_url('vendor/orders?shop_id=' . $s['id']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-bag"></i></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Recent Orders</span><a href="<?= site_url('vendor/orders') ?>" class="small">View all</a></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Sub-order</th><th>Order</th><th>Customer</th><th>Total</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recentOrders as $o): ?>
            <tr>
                <td class="fw-semibold small"><?= esc($o['sub_order_no']) ?></td>
                <td class="small"><?= esc($o['order_no'] ?? '—') ?></td>
                <td><?= esc($o['customer'] ?? '—') ?></td>
                <td>₹<?= esc(number_format((float) $o['grand_total'], 2)) ?></td>
                <td><span class="badge text-bg-<?= esc($soBadge[$o['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $o['status'])) ?></span></td>
                <td class="text-end"><a href="<?= site_url('vendor/orders/' . $o['id']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($recentOrders)): ?><tr><td colspan="6" class="text-center text-secondary py-4">No orders yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
