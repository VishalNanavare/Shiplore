<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php if (session('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session('success')) ?></div>
<?php endif; ?>
<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>

<?php
$uid  = (int) $unit['id'];
$addr = (array) json_decode((string) ($unit['address_json'] ?? '{}'), true);
$qty  = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 3), '0'), '.') ?: '0';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0"><?= esc($unit['name'] ?? '') ?></h5>
        <span class="badge bg-<?= ($unit['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>">
            <?= esc((string) ($unit['status'] ?? '')) ?>
        </span>
        <?php if (! empty($unit['delivery_enabled'])): ?>
            <span class="badge bg-info"><i class="bi bi-truck me-1"></i>Delivers<?= ! empty($unit['delivery_radius_km']) ? ' · ' . esc((string) $unit['delivery_radius_km']) . ' km' : '' ?></span>
        <?php endif; ?>
    </div>
    <div class="btn-group btn-group-sm">
        <?php if (! empty($canManage)): ?>
            <a class="btn btn-outline-secondary" href="<?= site_url('manufacturer/units/' . $uid . '/edit') ?>">
                <i class="bi bi-pencil me-1"></i>Edit
            </a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= site_url('manufacturer/inventory?mshop_id=' . $uid) ?>">
            <i class="bi bi-boxes me-1"></i>Stock
        </a>
        <a class="btn btn-light" href="<?= site_url('manufacturer/units') ?>">
            <i class="bi bi-arrow-left me-1"></i>All units
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header py-2"><strong>Address</strong></div>
            <div class="card-body small">
                <?php $line = trim(implode(', ', array_filter([$addr['line1'] ?? '', $addr['area'] ?? '', $addr['city'] ?? '']))); ?>
                <?= $line !== '' ? esc($line) : '<span class="text-secondary">Not set</span>' ?>
                <div class="text-secondary mt-1">
                    <?= esc((string) ($unit['pincode'] ?? '')) ?>
                    <?= esc((string) ($unit['state_code'] ?? '')) ?>
                </div>
                <?php if (! empty($unit['gstin'])): ?>
                    <div class="mt-2">GSTIN <strong><?= esc($unit['gstin']) ?></strong></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <strong>Staff</strong><span class="text-secondary small"><?= count($staff) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($staff)): ?>
                    <div class="p-3 small text-secondary">Nobody is assigned to this unit yet.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($staff as $s): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                                <span class="small"><?= esc((string) ($s['name'] ?? '')) ?></span>
                                <span class="badge bg-light text-secondary border">
                                    <?= esc(str_replace('_', ' ', (string) ($s['staff_type'] ?? ''))) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <strong>Products made here</strong><span class="text-secondary small"><?= count($products) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Product</th><th>SKU</th><th>Status</th><th class="text-end"></th></tr></thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                            <tr><td colspan="4" class="text-center text-secondary py-3 small">Nothing listed at this unit yet.</td></tr>
                        <?php else: ?>
                            <?php foreach (array_slice($products, 0, 25) as $p): ?>
                                <tr>
                                    <td class="small"><?= esc((string) ($p['title'] ?? '')) ?></td>
                                    <td class="small text-secondary"><?= esc(($p['sku'] ?? '') !== '' ? $p['sku'] : '—') ?></td>
                                    <td><span class="badge bg-secondary"><?= esc(str_replace('_', ' ', (string) ($p['status'] ?? ''))) ?></span></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="<?= site_url('manufacturer/products/' . (int) $p['id'] . '/variants') ?>">Variants</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header py-2"><strong>Stock at this unit</strong></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Item</th><th>SKU</th><th class="text-end">On hand</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if (empty($stock)): ?>
                            <tr><td colspan="4" class="text-center text-secondary py-3 small">No stock recorded here yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($stock as $s): ?>
                                <?php $st = (string) ($s['status'] ?? ''); ?>
                                <tr>
                                    <td class="small"><?= esc((string) ($s['title'] ?? '')) ?></td>
                                    <td class="small text-secondary"><?= esc((string) ($s['sku'] ?? '')) ?></td>
                                    <td class="text-end"><?= esc($qty($s['on_hand'] ?? 0)) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $st === 'in_stock' ? 'success' : ($st === 'low' ? 'warning' : 'secondary') ?>">
                                            <?= esc(str_replace('_', ' ', $st)) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
