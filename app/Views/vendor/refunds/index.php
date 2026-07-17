<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php $badge = ['initiated' => 'secondary', 'processing' => 'info', 'completed' => 'success', 'failed' => 'danger']; ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Refunds</span><span class="text-secondary small"><?= count($refunds) ?> total</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>#</th><th>Order</th><th>Method</th><th>Destination</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($refunds as $r): ?>
            <tr>
                <td class="fw-semibold">#<?= esc($r['id']) ?></td>
                <td><?= esc($r['order_no'] ?? '—') ?></td>
                <td class="text-uppercase small"><?= esc($r['method'] ?? '—') ?></td>
                <td class="text-capitalize"><?= esc($r['destination']) ?></td>
                <td>₹<?= esc(number_format((float) $r['amount'], 2)) ?></td>
                <td><span class="badge text-bg-<?= esc($badge[$r['status']] ?? 'secondary', 'attr') ?>"><?= esc($r['status']) ?></span></td>
                <td class="text-secondary small"><?= esc(substr((string) ($r['created_at'] ?? ''), 0, 10)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($refunds)): ?><tr><td colspan="7" class="text-center text-secondary py-4">No refunds on your orders.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
