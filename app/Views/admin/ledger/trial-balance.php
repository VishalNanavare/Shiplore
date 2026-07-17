<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $balanced = round($totDebit, 2) === round($totCredit, 2); ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="<?= site_url('admin/ledger') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Ledger entries</a>
    <span class="badge text-bg-<?= $balanced ? 'success' : 'danger' ?> fs-6"><?= $balanced ? 'Balanced' : 'Out of balance' ?></span>
</div>

<div class="card">
    <div class="card-header fw-semibold"><i class="bi bi-journal-text me-1"></i>Trial balance</div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Code</th><th>Account</th><th>Type</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $bal = (float) $r['debit'] - (float) $r['credit']; ?>
            <tr<?= $r['parent_id'] ? ' class="small"' : '' ?>>
                <td><code><?= esc($r['code']) ?></code></td>
                <td<?= $r['parent_id'] ? ' style="padding-left:1.5rem"' : ' class="fw-medium"' ?>><?= esc($r['name']) ?></td>
                <td class="text-capitalize small text-secondary"><?= esc($r['type']) ?></td>
                <td class="text-end">₹<?= esc(number_format((float) $r['debit'], 2)) ?></td>
                <td class="text-end">₹<?= esc(number_format((float) $r['credit'], 2)) ?></td>
                <td class="text-end <?= $bal < 0 ? 'text-danger' : '' ?>"><?= $bal < 0 ? '−' : '' ?>₹<?= esc(number_format(abs($bal), 2)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?><tr><td colspan="6" class="text-center text-secondary py-4">No ledger accounts.</td></tr><?php endif; ?>
        </tbody>
        <tfoot class="table-light fw-semibold"><tr>
            <td colspan="3" class="text-end">Total</td>
            <td class="text-end">₹<?= esc(number_format($totDebit, 2)) ?></td>
            <td class="text-end">₹<?= esc(number_format($totCredit, 2)) ?></td>
            <td class="text-end <?= $balanced ? 'text-success' : 'text-danger' ?>">₹<?= esc(number_format($totDebit - $totCredit, 2)) ?></td>
        </tr></tfoot>
    </table></div>
</div>
<?= $this->endSection() ?>
