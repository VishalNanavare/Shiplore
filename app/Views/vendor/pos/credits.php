<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0"><i class="bi bi-cash-coin me-1"></i>Credit / Udhaar</h1>
    <a href="<?= site_url('vendor/pos') ?>" class="btn btn-sm btn-primary"><i class="bi bi-shop me-1"></i>Back to POS</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-4"><div class="card"><div class="card-body py-2">
        <div class="text-secondary small text-uppercase">Outstanding</div>
        <div class="fs-4 fw-semibold text-danger">₹<?= esc(number_format($summary['outstanding'], 2)) ?></div>
    </div></div></div>
    <div class="col-sm-4"><div class="card"><div class="card-body py-2">
        <div class="text-secondary small text-uppercase">Open credits</div>
        <div class="fs-4 fw-semibold"><?= esc($summary['count']) ?></div>
    </div></div></div>
</div>

<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Customer</th><th>Invoice</th><th class="text-end">Amount</th><th class="text-end">Balance</th><th>Due</th><th style="min-width:260px">Record repayment</th></tr></thead>
    <tbody>
    <?php foreach ($credits as $c): ?>
        <tr>
            <td class="small"><?= esc($c['customer'] ?? '—') ?><div class="text-secondary"><?= esc($c['phone'] ?? '') ?></div></td>
            <td class="small text-secondary"><?= esc($c['server_invoice_no'] ?? '—') ?></td>
            <td class="text-end small">₹<?= esc(number_format((float) $c['amount'], 2)) ?></td>
            <td class="text-end fw-semibold text-danger">₹<?= esc(number_format((float) $c['balance'], 2)) ?></td>
            <td class="small"><?php if ($c['due_date']): ?><span class="badge <?= $c['due_date'] < date('Y-m-d') ? 'text-bg-danger' : 'text-bg-light' ?>"><?= esc($c['due_date']) ?></span><?php else: ?><span class="text-secondary">—</span><?php endif; ?></td>
            <td>
                <form method="post" action="<?= site_url('vendor/pos/credits/' . $c['id'] . '/repay') ?>" class="row g-1 align-items-center">
                    <?= csrf_field() ?>
                    <div class="col-auto"><input name="amount" type="number" min="1" step="0.01" max="<?= esc($c['balance'], 'attr') ?>" class="form-control form-control-sm" style="width:90px" placeholder="Amount" value="<?= esc(number_format((float) $c['balance'], 2, '.', ''), 'attr') ?>" required></div>
                    <div class="col-auto"><select name="method" class="form-select form-select-sm"><option value="cash">Cash</option><option value="upi">UPI</option><option value="card">Card</option><option value="wallet">Wallet</option></select></div>
                    <div class="col-auto"><button class="btn btn-sm btn-success">Repay</button></div>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($credits)): ?><tr><td colspan="6" class="text-center text-secondary py-4">No outstanding credit. 🎉</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= $this->endSection() ?>
