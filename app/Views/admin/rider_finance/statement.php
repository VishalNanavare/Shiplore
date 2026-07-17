<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $esb = ['accrued' => 'secondary', 'batched' => 'info', 'paid' => 'success', 'reversed' => 'dark']; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="<?= site_url('admin/riders') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Riders</a>
    <a href="<?= site_url('admin/rider-finance/statement/' . $riderId . '?from=' . esc($stmt['from'], 'url') . '&to=' . esc($stmt['to'], 'url') . '&export=csv') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-download me-1"></i>CSV</a>
</div>

<div class="card mb-3"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start flex-wrap">
        <div><div class="text-secondary small">Statement for</div><div class="h5 mb-0"><?= esc($rider['name'] ?? ('Rider #' . $riderId)) ?></div><div class="small text-secondary"><?= esc($rider['phone'] ?? '') ?></div></div>
        <form method="get" class="d-flex gap-2 align-items-end">
            <div><label class="form-label small mb-0">From</label><input type="date" name="from" value="<?= esc($stmt['from'], 'attr') ?>" class="form-control form-control-sm"></div>
            <div><label class="form-label small mb-0">To</label><input type="date" name="to" value="<?= esc($stmt['to'], 'attr') ?>" class="form-control form-control-sm"></div>
            <button class="btn btn-sm btn-primary">Apply</button>
        </form>
    </div>
</div></div>

<div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
    <div class="col"><div class="card h-100"><div class="card-body py-2 text-center"><div class="text-info"><i class="bi bi-truck"></i></div><div class="h5 mb-0"><?= (int) $stmt['deliveries'] ?></div><div class="small text-secondary">Deliveries</div></div></div></div>
    <div class="col"><div class="card h-100"><div class="card-body py-2 text-center"><div class="text-success"><i class="bi bi-cash-coin"></i></div><div class="h5 mb-0">₹<?= esc(number_format((float) $stmt['earnings'], 2)) ?></div><div class="small text-secondary">Earnings</div></div></div></div>
    <div class="col"><div class="card h-100"><div class="card-body py-2 text-center"><div class="text-secondary"><i class="bi bi-hourglass-split"></i></div><div class="h5 mb-0">₹<?= esc(number_format((float) $stmt['unpaid'], 2)) ?></div><div class="small text-secondary">Unpaid</div></div></div></div>
    <div class="col"><div class="card h-100"><div class="card-body py-2 text-center"><div class="text-danger"><i class="bi bi-wallet2"></i></div><div class="h5 mb-0">₹<?= esc(number_format((float) $stmt['cod']['outstanding'], 2)) ?></div><div class="small text-secondary">COD outstanding</div></div></div></div>
</div>

<div class="card"><div class="card-header fw-semibold">Earning lines · <?= esc($stmt['from']) ?> → <?= esc($stmt['to']) ?></div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Date</th><th>Order</th><th>Delivery</th><th>Model</th><th class="text-end">Order ₹</th><th class="text-end">Km</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($stmt['lines'] as $l): ?>
        <tr>
            <td class="small text-secondary"><?= esc(substr((string) $l['created_at'], 0, 16)) ?></td>
            <td><?= esc($l['order_no'] ?? '—') ?></td>
            <td>#<?= (int) $l['delivery_id'] ?></td>
            <td class="small text-capitalize"><?= esc(str_replace('_', ' ', (string) $l['model'])) ?></td>
            <td class="text-end"><?= esc(number_format((float) $l['order_value'], 0)) ?></td>
            <td class="text-end"><?= esc(number_format((float) $l['distance_km'], 1)) ?></td>
            <td class="text-end fw-semibold">₹<?= esc(number_format((float) $l['amount'], 2)) ?></td>
            <td><span class="badge text-bg-<?= esc($esb[$l['status']] ?? 'secondary', 'attr') ?>"><?= esc($l['status']) ?></span></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($stmt['lines'])): ?><tr><td colspan="8" class="text-center text-secondary py-4">No earnings in this period.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= $this->endSection() ?>
