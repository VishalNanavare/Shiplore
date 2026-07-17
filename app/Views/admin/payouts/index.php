<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $sb = ['created' => 'info', 'processing' => 'warning', 'completed' => 'success', 'partial' => 'warning', 'failed' => 'danger']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<?php $missing = array_values(array_filter($eligible, static fn ($e) => empty($e['bank_account_id']))); $ready = array_values(array_filter($eligible, static fn ($e) => ! empty($e['bank_account_id']))); ?>
<div class="row g-3 mb-3">
    <div class="col-md-8">
        <div class="card h-100"><div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <div class="fw-semibold">Approved settlements awaiting payout</div>
                <div class="text-secondary small"><?= count($ready) ?> ready · ₹<?= esc(number_format(array_sum(array_map(static fn ($e) => (float) $e['net_payable'], $ready)), 2)) ?><?php if ($missing): ?> · <span class="text-danger"><?= count($missing) ?> need bank details</span><?php endif; ?></div>
            </div>
            <form method="post" action="<?= site_url('admin/payouts/run') ?>" data-confirm="Create a payout batch for vendors with bank details on file?">
                <?= csrf_field() ?>
                <button class="btn btn-primary" <?= empty($ready) ? 'disabled' : '' ?>><i class="bi bi-bank me-1"></i>Run payout batch</button>
            </form>
        </div></div>
    </div>
    <div class="col-md-4"><a href="<?= site_url('admin/settlements') ?>" class="card h-100 text-decoration-none text-reset"><div class="card-body d-flex align-items-center"><i class="bi bi-arrow-left-right me-2 text-primary fs-4"></i><span>Back to settlements</span></div></a></div>
</div>

<?php if ($missing): ?>
<div class="card mb-3 border-warning"><div class="card-header bg-warning-subtle fw-semibold"><i class="bi bi-exclamation-triangle me-1"></i>Vendors missing bank details (won't be paid until added)</div>
    <div class="card-body">
        <?php foreach ($missing as $mv): ?>
            <form method="post" action="<?= site_url('admin/payouts/bank-account') ?>" class="row g-2 align-items-end mb-2 pb-2 border-bottom">
                <?= csrf_field() ?>
                <input type="hidden" name="vendor_id" value="<?= esc($mv['vendor_id'], 'attr') ?>">
                <div class="col-md-3"><label class="form-label small mb-0"><?= esc($mv['vendor']) ?> <span class="text-secondary">(₹<?= esc(number_format((float) $mv['net_payable'], 0)) ?>)</span></label><input name="holder_name" class="form-control form-control-sm" placeholder="Account holder" required></div>
                <div class="col-md-3"><input name="account_no" class="form-control form-control-sm" placeholder="Account number" required></div>
                <div class="col-md-2"><input name="ifsc" class="form-control form-control-sm text-uppercase" placeholder="IFSC" maxlength="11" required></div>
                <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Save bank a/c</button></div>
            </form>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header fw-semibold">Payout batches</div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Batch</th><th>Gateway</th><th>Vendors</th><th class="text-end">Total</th><th>Status</th><th>Created</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($batches as $b): ?>
            <tr>
                <td class="fw-semibold">#<?= (int) $b['id'] ?></td>
                <td class="text-uppercase small"><?= esc($b['gateway']) ?></td>
                <td><?= (int) $b['count'] ?></td>
                <td class="text-end">₹<?= esc(number_format((float) $b['total_amount'], 2)) ?></td>
                <td><span class="badge text-bg-<?= esc($sb[$b['status']] ?? 'secondary', 'attr') ?>"><?= esc($b['status']) ?></span></td>
                <td class="small text-secondary"><?= esc(substr((string) $b['created_at'], 0, 16)) ?></td>
                <td class="text-end"><a href="<?= site_url('admin/payouts/' . $b['id']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($batches)): ?><tr><td colspan="7" class="text-center text-secondary py-4">No payout batches yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
