<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $sb = ['draft' => 'secondary', 'calculated' => 'info', 'approved' => 'primary', 'paid' => 'success', 'held' => 'warning', 'failed' => 'danger']; ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <a href="<?= site_url('admin/vendors') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back</a>
    <form method="get" class="d-flex gap-2 align-items-center">
        <input type="date" name="from" class="form-control form-control-sm" value="<?= esc($from, 'attr') ?>">
        <span class="text-secondary">→</span>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= esc($to, 'attr') ?>">
        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i></button>
        <a href="<?= site_url('admin/vendors/' . $vendor['id'] . '/statement?' . http_build_query(['from' => $from, 'to' => $to, 'export' => 'csv'])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download me-1"></i>CSV</a>
    </form>
</div>

<div class="card">
    <div class="card-header fw-semibold"><i class="bi bi-receipt me-1"></i>Statement — <?= esc($vendor['display_name'] ?? 'Vendor') ?> <span class="text-secondary small fw-normal"><?= esc($from) ?> → <?= esc($to) ?></span></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Period</th><th class="text-end">Gross</th><th class="text-end">Commission</th><th class="text-end">Refunds</th><th class="text-end">TCS</th><th class="text-end">TDS</th><th class="text-end">Fees</th><th class="text-end">Adjust.</th><th class="text-end">Net payable</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="small"><a href="<?= site_url('admin/settlements/' . $r['id']) ?>"><?= esc($r['period_start']) ?> → <?= esc($r['period_end']) ?></a></td>
                <td class="text-end">₹<?= esc(number_format((float) $r['gross'], 0)) ?></td>
                <td class="text-end">₹<?= esc(number_format((float) $r['commission_total'], 0)) ?></td>
                <td class="text-end">₹<?= esc(number_format((float) $r['refund_total'], 0)) ?></td>
                <td class="text-end">₹<?= esc(number_format((float) $r['tcs'], 0)) ?></td>
                <td class="text-end">₹<?= esc(number_format((float) $r['tds'], 0)) ?></td>
                <td class="text-end">₹<?= esc(number_format((float) $r['fees'], 0)) ?></td>
                <td class="text-end">₹<?= esc(number_format((float) $r['adjustments'], 0)) ?></td>
                <td class="text-end fw-semibold">₹<?= esc(number_format((float) $r['net_payable'], 0)) ?></td>
                <td><span class="badge text-bg-<?= esc($sb[$r['status']] ?? 'secondary', 'attr') ?>"><?= esc($r['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?><tr><td colspan="10" class="text-center text-secondary py-4">No settlements in this period.</td></tr><?php endif; ?>
        </tbody>
        <?php if (! empty($rows)): ?>
        <tfoot class="table-light fw-semibold"><tr>
            <td>Total</td>
            <td class="text-end">₹<?= esc(number_format($totals['gross'], 0)) ?></td>
            <td class="text-end">₹<?= esc(number_format($totals['commission_total'], 0)) ?></td>
            <td class="text-end">₹<?= esc(number_format($totals['refund_total'], 0)) ?></td>
            <td class="text-end">₹<?= esc(number_format($totals['tcs'], 0)) ?></td>
            <td class="text-end">₹<?= esc(number_format($totals['tds'], 0)) ?></td>
            <td class="text-end">₹<?= esc(number_format($totals['fees'], 0)) ?></td>
            <td class="text-end">₹<?= esc(number_format($totals['adjustments'], 0)) ?></td>
            <td class="text-end text-primary">₹<?= esc(number_format($totals['net_payable'], 0)) ?></td>
            <td></td>
        </tr></tfoot>
        <?php endif; ?>
    </table></div>
</div>
<?= $this->endSection() ?>
