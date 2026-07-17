<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-secondary small">Total debits (last 200)</div><div class="h5 mb-0">₹<?= esc(number_format($totDebit, 2)) ?></div></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-secondary small">Total credits (last 200)</div><div class="h5 mb-0">₹<?= esc(number_format($totCredit, 2)) ?></div></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-secondary small">Balance</div><div class="h5 mb-0 <?= abs($totDebit - $totCredit) < 0.01 ? 'text-success' : 'text-danger' ?>">₹<?= esc(number_format($totDebit - $totCredit, 2)) ?></div></div></div></div>
</div>
<div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Ledger entries</span><a href="<?= site_url('admin/ledger/trial-balance') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-journal-text me-1"></i>Trial balance</a></div><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Account</th><th>Dir</th><th class="text-end">Amount</th><th>Ref</th><th>Memo</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($entries as $e): ?>
        <tr>
            <td><code><?= esc($e['account'] ?? '—') ?></code></td>
            <td><span class="badge text-bg-<?= $e['direction'] === 'debit' ? 'danger' : 'success' ?>"><?= esc($e['direction']) ?></span></td>
            <td class="text-end">₹<?= esc(number_format((float) $e['amount'], 2)) ?></td>
            <td class="small text-secondary"><?= esc($e['ref_type']) ?> #<?= esc($e['ref_id']) ?></td>
            <td class="small"><?= esc($e['memo']) ?></td>
            <td class="small text-secondary"><?= esc(substr((string) $e['created_at'], 0, 16)) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($entries)): ?><tr><td colspan="6" class="text-center text-secondary py-4">No ledger entries yet.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= $this->endSection() ?>
