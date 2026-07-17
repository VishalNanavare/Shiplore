<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $sb = ['matched' => 'success', 'mismatch' => 'warning', 'missing' => 'danger', 'chargeback' => 'dark', 'resolved' => 'primary']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold">Payment reconciliation <span class="text-secondary small fw-normal">(<?= count($rows) ?>)</span></span>
        <div class="btn-group btn-group-sm">
            <a class="btn btn-outline-secondary <?= $status === '' ? 'active' : '' ?>" href="<?= site_url('admin/reconciliations') ?>">All</a>
            <?php foreach (array_keys($sb) as $s): ?>
                <a class="btn btn-outline-secondary <?= $status === $s ? 'active' : '' ?>" href="<?= site_url('admin/reconciliations?status=' . $s) ?>"><?= ucfirst($s) ?> <span class="badge text-bg-light"><?= (int) ($counts[$s] ?? 0) ?></span></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="table-responsive" data-ajax-region><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>#</th><th>Gateway</th><th>File ref</th><th>Order</th><th class="text-end">Expected</th><th class="text-end">Actual</th><th class="text-end">Variance</th><th>Date</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td>#<?= (int) $r['id'] ?></td>
                <td class="text-uppercase small"><?= esc($r['gateway']) ?></td>
                <td class="small text-secondary"><?= esc($r['settlement_file_ref'] ?: '—') ?></td>
                <td class="small"><?= esc($r['order_no'] ?? $r['payment_ref'] ?? '—') ?></td>
                <td class="text-end">₹<?= esc(number_format((float) $r['expected_amount'], 2)) ?></td>
                <td class="text-end">₹<?= esc(number_format((float) $r['actual_amount'], 2)) ?></td>
                <td class="text-end <?= (float) $r['variance'] != 0.0 ? 'text-danger fw-semibold' : 'text-secondary' ?>">₹<?= esc(number_format((float) $r['variance'], 2)) ?></td>
                <td class="small text-secondary"><?= esc($r['recon_date'] ?: '—') ?></td>
                <td><span class="badge text-bg-<?= esc($sb[$r['status']] ?? 'secondary', 'attr') ?>"><?= esc($r['status']) ?></span></td>
                <td class="text-end">
                    <?php if (! in_array($r['status'], ['resolved', 'matched'], true)): ?>
                        <form method="post" action="<?= site_url('admin/reconciliations/' . $r['id'] . '/resolve') ?>" class="d-inline" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-outline-success" title="Resolve"><i class="bi bi-check2"></i></button></form>
                        <form method="post" action="<?= site_url('admin/reconciliations/' . $r['id'] . '/dispute') ?>" class="d-inline" data-confirm="Flag as chargeback?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-dark" title="Chargeback"><i class="bi bi-exclamation-triangle"></i></button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?><tr><td colspan="10" class="text-center text-secondary py-4">No reconciliation records. They appear once a gateway settlement file is imported.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
