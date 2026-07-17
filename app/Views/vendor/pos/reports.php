<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php $r = static fn ($v) => '₹' . number_format((float) $v, 2); ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0"><i class="bi bi-graph-up me-1"></i>POS Reports</h1>
    <a href="<?= site_url('vendor/pos') ?>" class="btn btn-sm btn-primary"><i class="bi bi-shop me-1"></i>Back to POS</a>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-auto"><label class="form-label small">Store</label><select name="shop_id" class="form-select form-select-sm"><option value="">All stores</option><?php foreach ($shops as $s): ?><option value="<?= esc($s['id'], 'attr') ?>" <?= (int) $shopId === (int) $s['id'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><label class="form-label small">From</label><input type="date" name="from" value="<?= esc($from, 'attr') ?>" class="form-control form-control-sm"></div>
    <div class="col-auto"><label class="form-label small">To</label><input type="date" name="to" value="<?= esc($to, 'attr') ?>" class="form-control form-control-sm"></div>
    <div class="col-auto"><button class="btn btn-sm btn-primary">Apply</button></div>
    <div class="col-auto"><a class="btn btn-sm btn-outline-success" href="<?= site_url('vendor/pos/reports/export?from=' . esc($from, 'url') . '&to=' . esc($to, 'url') . ($shopId ? '&shop_id=' . (int) $shopId : '')) ?>"><i class="bi bi-download me-1"></i>Export CSV</a></div>
</form>

<div class="row g-3 mb-3">
    <div class="col-sm-3"><div class="card"><div class="card-body py-2"><div class="text-secondary small text-uppercase">Sales</div><div class="fs-4 fw-semibold"><?= $r($summary['sales']) ?></div></div></div></div>
    <div class="col-sm-3"><div class="card"><div class="card-body py-2"><div class="text-secondary small text-uppercase">Bills</div><div class="fs-4 fw-semibold"><?= esc($summary['bills']) ?></div></div></div></div>
    <div class="col-sm-3"><div class="card"><div class="card-body py-2"><div class="text-secondary small text-uppercase">GST collected</div><div class="fs-4 fw-semibold"><?= $r($summary['tax']) ?></div></div></div></div>
    <div class="col-sm-3"><div class="card"><div class="card-body py-2"><div class="text-secondary small text-uppercase">Udhaar outstanding</div><div class="fs-4 fw-semibold text-danger"><?= $r($credit['outstanding']) ?></div></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-5"><div class="card h-100"><div class="card-header fw-semibold">By payment method</div><div class="table-responsive"><table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Method</th><th class="text-end">Txns</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($byMethod as $row): ?><tr><td class="text-capitalize"><?= esc($row['tender_type']) ?></td><td class="text-end"><?= esc($row['txns']) ?></td><td class="text-end"><?= $r($row['amount']) ?></td></tr><?php endforeach; ?>
        <?php if (empty($byMethod)): ?><tr><td colspan="3" class="text-center text-secondary py-3">No sales in range.</td></tr><?php endif; ?>
        </tbody>
    </table></div></div></div>

    <div class="col-lg-7"><div class="card h-100"><div class="card-header fw-semibold">Top products</div><div class="table-responsive"><table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($top as $row): ?><tr><td class="small"><?= esc($row['title']) ?> <span class="text-secondary"><?= esc($row['sku']) ?></span></td><td class="text-end"><?= esc(rtrim(rtrim((string) $row['qty'], '0'), '.')) ?></td><td class="text-end"><?= $r($row['amount']) ?></td></tr><?php endforeach; ?>
        <?php if (empty($top)): ?><tr><td colspan="3" class="text-center text-secondary py-3">No sales in range.</td></tr><?php endif; ?>
        </tbody>
    </table></div></div></div>
</div>

<div class="card mt-3"><div class="card-header fw-semibold">Daily sales</div><div class="table-responsive"><table class="table table-sm mb-0">
    <thead class="table-light"><tr><th>Day</th><th class="text-end">Bills</th><th class="text-end">Sales</th></tr></thead>
    <tbody>
    <?php foreach ($byDay as $row): ?><tr><td><?= esc($row['day']) ?></td><td class="text-end"><?= esc($row['bills']) ?></td><td class="text-end"><?= $r($row['sales']) ?></td></tr><?php endforeach; ?>
    <?php if (empty($byDay)): ?><tr><td colspan="3" class="text-center text-secondary py-3">No sales in range.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= $this->endSection() ?>
