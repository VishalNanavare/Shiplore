<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php $badge = ['draft' => 'secondary', 'calculated' => 'info', 'approved' => 'primary', 'paid' => 'success', 'held' => 'warning', 'failed' => 'danger']; ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">My Settlements</span><span class="text-secondary small"><?= count($settlements) ?> total</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Period</th><th>Gross</th><th>Commission</th><th>Refunds</th><th>Net Payable</th><th>Status</th><th class="text-end"></th></tr></thead>
        <tbody>
        <?php foreach ($settlements as $s): ?>
            <tr>
                <td class="small"><?= esc($s['period_start']) ?> → <?= esc($s['period_end']) ?></td>
                <td>₹<?= esc(number_format((float) $s['gross'], 2)) ?></td>
                <td>₹<?= esc(number_format((float) $s['commission_total'], 2)) ?></td>
                <td>₹<?= esc(number_format((float) $s['refund_total'], 2)) ?></td>
                <td class="fw-semibold">₹<?= esc(number_format((float) $s['net_payable'], 2)) ?></td>
                <td><span class="badge text-bg-<?= esc($badge[$s['status']] ?? 'secondary', 'attr') ?>"><?= esc($s['status']) ?></span></td>
                <td class="text-end"><a href="<?= site_url('vendor/settlements/' . $s['id']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($settlements)): ?><tr><td colspan="7" class="text-center text-secondary py-4">No settlements yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
