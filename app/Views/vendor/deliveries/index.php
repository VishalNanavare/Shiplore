<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<?php
$sla  = ['on_time' => 'success', 'at_risk' => 'warning', 'breached' => 'danger', 'unknown' => 'secondary'];
$stat = ['pending' => 'secondary', 'assigned' => 'info', 'picked_up' => 'primary', 'out_for_delivery' => 'primary', 'delivered' => 'success', 'failed' => 'danger', 'returned' => 'dark'];
?>
<div class="row g-3 mb-4">
    <?php foreach ([['Total', $reports['total'], 'truck', 'primary'], ['SLA breaches', $reports['sla_breaches'], 'exclamation-triangle', 'danger'], ['COD due', '₹' . $reports['cod_due'], 'cash-coin', 'success']] as [$l, $v, $i, $c]): ?>
        <div class="col-md-4"><div class="card"><div class="card-body d-flex align-items-center gap-3">
            <span class="rounded d-grid bg-<?= $c ?>-subtle text-<?= $c ?>" style="width:46px;height:46px;place-items:center;font-size:1.2rem"><i class="bi bi-<?= $i ?>"></i></span>
            <div><div class="text-secondary small"><?= esc($l) ?></div><div class="h5 mb-0"><?= esc($v) ?></div></div>
        </div></div></div>
    <?php endforeach; ?>
</div>

<div class="card"><div class="card-header d-flex justify-content-between align-items-center">
    <span class="fw-semibold">Deliveries</span>
    <span class="d-flex align-items-center gap-2">
        <?= view('partials/_shop_filter', ['shopOptions' => $shopOptions ?? [], 'shopId' => $shopId ?? null, 'filterKeep' => $filterKeep ?? []]) ?>
        <div class="btn-group btn-group-sm">
            <?php foreach (['' => 'All', 'active' => 'Active', 'delivered' => 'Delivered', 'failed' => 'Failed'] as $k => $label): ?>
                <a href="<?= site_url('vendor/deliveries' . ($k ? '?status=' . $k : '')) ?>" class="btn btn-outline-secondary <?= ($status ?? '') === $k ? 'active' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </span>
</div><div class="table-responsive"><table class="table align-middle mb-0">
    <thead class="table-light"><tr><th>Order</th><th>Shop</th><th>Customer</th><th>Rider</th><th>SLA</th><th>COD</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($deliveries as $d): ?>
        <tr>
            <td class="fw-medium"><?= esc($d['order_no']) ?><div class="text-secondary small"><?= esc($d['sub_order_no']) ?></div></td>
            <td class="small"><?= esc($d['shop']) ?></td>
            <td class="small"><?= esc($d['customer']) ?><div class="text-secondary"><i class="bi bi-telephone me-1"></i><?= esc($d['customer_phone_masked']) ?></div></td>
            <td class="small"><?= $d['rider'] ? esc($d['rider']) : '<span class="text-secondary">— unassigned —</span>' ?></td>
            <td><span class="badge bg-<?= $sla[$d['sla_status']] ?? 'secondary' ?>-subtle text-<?= $sla[$d['sla_status']] ?? 'secondary' ?>"><?= esc(str_replace('_', ' ', $d['sla_status'])) ?></span></td>
            <td><?= (float) $d['cod_amount'] > 0 ? '₹' . esc($d['cod_amount']) : '—' ?></td>
            <td><span class="badge text-bg-<?= $stat[$d['status']] ?? 'secondary' ?>"><?= esc(str_replace('_', ' ', $d['status'])) ?></span></td>
            <td class="text-end"><a href="<?= site_url('vendor/deliveries/' . $d['id']) ?>" class="btn btn-sm btn-outline-primary">Manage</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($deliveries)): ?><tr><td colspan="8" class="text-center text-secondary py-4">No deliveries<?= $status ? ' in this view' : '' ?>.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= $this->endSection() ?>
