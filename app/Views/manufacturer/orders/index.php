<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Purchase Orders</h5>
    <form method="get">
        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach (['' => 'All', 'placed' => 'New', 'accepted' => 'Accepted', 'packed' => 'Packed', 'dispatched' => 'Dispatched', 'received' => 'Received', 'rejected' => 'Rejected'] as $v => $label): ?>
                <option value="<?= esc($v, 'attr') ?>" <?= ($filters['status'] ?? '') === $v ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead><tr><th>PO</th><th>Buyer</th><th>Deliver to</th><th class="text-end">Total</th><th>Status</th><th>Placed</th></tr></thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-4">No purchase orders yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td><a href="<?= site_url('manufacturer/purchase-orders/' . (int) $o['id']) ?>"><?= esc($o['po_no']) ?></a></td>
                            <td class="small"><?= esc((string) ($o['buyer_name'] ?? '')) ?></td>
                            <td class="small"><?= esc((string) ($o['buyer_shop_name'] ?? '')) ?></td>
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
