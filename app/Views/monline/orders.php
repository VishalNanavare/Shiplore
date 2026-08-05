<?= $this->extend('monline/_layout') ?>
<?= $this->section('content') ?>

<h1 class="h5 mb-3">Purchase orders</h1>
<?php
// Terminal-good vs terminal-bad vs in-flight — mirrors the status pill treatment
// used across the storefront's order screens.
$statusClass = static function (string $s): string {
    if (in_array($s, ['received', 'closed'], true)) { return 'mo-status mo-status-ok'; }
    if (in_array($s, ['rejected', 'cancelled'], true)) { return 'mo-status mo-status-bad'; }

    return 'mo-status';
};
?>

<div class="card">
    <div class="table-responsive">
        <table class="table mo-table mb-0 align-middle">
            <thead><tr><th>PO</th><th>Manufacturer</th><th>Deliver to</th><th class="text-end">Total</th><th>Status</th><th>Placed</th></tr></thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="6">
                        <div class="mo-empty py-4"><i class="bi bi-receipt"></i>No purchase orders yet. <a href="<?= site_url('monline/browse') ?>">Browse the catalogue</a>.</div>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td><a class="fw-semibold text-decoration-none" href="<?= site_url('monline/orders/' . (int) $o['id']) ?>"><?= esc($o['po_no']) ?></a></td>
                            <td class="small"><?= esc((string) ($o['seller_name'] ?? '')) ?></td>
                            <td class="small"><?= esc((string) ($o['shop_name'] ?? '')) ?></td>
                            <td class="text-end">₹<?= esc(number_format((float) $o['grand_total'], 2)) ?></td>
                            <td><span class="<?= $statusClass((string) $o['status']) ?>"><?= esc(str_replace('_', ' ', (string) $o['status'])) ?></span></td>
                            <td class="small text-secondary"><?= esc((string) ($o['placed_at'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
