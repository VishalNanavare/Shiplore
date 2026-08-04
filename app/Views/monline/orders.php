<?= $this->extend('monline/_layout') ?>
<?= $this->section('content') ?>

<h2 class="mo-section-title">Purchase orders</h2>

<div class="card">
    <div class="table-responsive">
        <table class="table mo-table mb-0 align-middle">
            <thead><tr><th>PO</th><th>Manufacturer</th><th>Deliver to</th><th class="text-end">Total</th><th>Status</th><th>Placed</th></tr></thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-4">
                        No purchase orders yet. <a href="<?= site_url('monline/browse') ?>">Browse the catalogue</a>.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td><a href="<?= site_url('monline/orders/' . (int) $o['id']) ?>"><?= esc($o['po_no']) ?></a></td>
                            <td class="small"><?= esc((string) ($o['seller_name'] ?? '')) ?></td>
                            <td class="small"><?= esc((string) ($o['shop_name'] ?? '')) ?></td>
                            <td class="text-end">₹<?= esc(number_format((float) $o['grand_total'], 2)) ?></td>
                            <td><span class="badge bg-secondary"><?= esc(str_replace('_', ' ', (string) $o['status'])) ?></span></td>
                            <td class="small text-secondary"><?= esc((string) ($o['placed_at'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
