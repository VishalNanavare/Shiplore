<?= $this->extend('monline/_layout') ?>
<?= $this->section('content') ?>
<?php
$statusFilter = trim((string) service('request')->getGet('status'));

/*
 * Terminal-good / terminal-bad / needs-your-action / in-flight. The amber tier
 * matters: without it a dispatched PO — the one waiting on the buyer to confirm
 * receipt — looks identical to one merely placed.
 */
$statusClass = static function (string $s): string {
    if (in_array($s, ['received', 'closed'], true)) { return 'mo-status mo-status-ok'; }
    if (in_array($s, ['rejected', 'cancelled'], true)) { return 'mo-status mo-status-bad'; }
    if (in_array($s, ['dispatched', 'partially_received'], true)) { return 'mo-status mo-status-warn'; }

    return 'mo-status';
};
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h5 mb-0">Purchase orders</h1>
    <?php if ($statusFilter !== ''): ?>
        <a href="<?= site_url('monline/orders') ?>" class="badge rounded-pill bg-light border mo-chip text-dark">
            Status: <?= esc(str_replace('_', ' ', $statusFilter)) ?> <i class="bi bi-x"></i><span class="visually-hidden">remove filter</span>
        </a>
    <?php endif; ?>
</div>

<?php if (empty($orders)): ?>
    <?php // Branch BEFORE the card — a header row for columns with no data reads as broken. ?>
    <div class="mo-empty mo-empty-card">
        <i class="bi bi-receipt"></i>
        <?php if ($statusFilter !== ''): ?>
            <div class="fw-semibold text-dark mb-1 fs-6">No <?= esc(str_replace('_', ' ', $statusFilter)) ?> purchase orders</div>
            <p class="small mb-3">You may have orders in other states.</p>
            <a href="<?= site_url('monline/orders') ?>" class="btn btn-sm btn-primary">Show all purchase orders</a>
        <?php else: ?>
            <div class="fw-semibold text-dark mb-1 fs-6">No purchase orders yet</div>
            <p class="small mb-3">Orders you place with manufacturers will appear here, one per manufacturer.</p>
            <a href="<?= site_url('monline/browse') ?>" class="btn btn-sm btn-primary">Browse the catalogue</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table mo-table mb-0 align-middle">
                <thead><tr><th>PO</th><th>Manufacturer</th><th>Deliver to</th><th class="text-end">Total</th><th>Status</th><th>Placed</th></tr></thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td><a class="fw-semibold text-decoration-none" href="<?= site_url('monline/orders/' . (int) $o['id']) ?>"><?= esc($o['po_no']) ?></a></td>
                            <td class="small"><?= esc((string) ($o['seller_name'] ?? '')) ?></td>
                            <td class="small"><?= esc((string) ($o['shop_name'] ?? '')) ?></td>
                            <td class="text-end">₹<?= esc(number_format((float) $o['grand_total'], 2)) ?></td>
                            <td><span class="<?= $statusClass((string) $o['status']) ?>"><?= esc(str_replace('_', ' ', (string) $o['status'])) ?></span></td>
                            <td class="small text-secondary"><?= esc(substr((string) ($o['placed_at'] ?? ''), 0, 10)) ?: '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?= $this->endSection() ?>
