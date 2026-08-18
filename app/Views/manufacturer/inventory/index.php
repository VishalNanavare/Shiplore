<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php if (session('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session('success')) ?></div>
<?php endif; ?>
<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Stock</h5>
    <?php if (! empty($unitOptions)): ?>
        <form method="get" class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 small text-secondary" for="unitFilter">Unit</label>
            <select class="form-select form-select-sm" id="unitFilter" name="mshop_id" onchange="this.form.submit()">
                <option value="">All units</option>
                <?php foreach ($unitOptions as $id => $name): ?>
                    <option value="<?= (int) $id ?>" <?= (int) ($activeUnit ?? 0) === (int) $id ? 'selected' : '' ?>>
                        <?= esc($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle" id="stockTable">
            <thead>
                <tr>
                    <th>Product</th><th>SKU</th><th>Unit</th>
                    <th class="text-end">On hand</th><th class="text-end">Reserved</th><th class="text-end">Available</th>
                    <th>Status</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="text-center text-secondary py-4">
                        No stock recorded yet. Open a product and record production to start tracking balances.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php $st = (string) ($r['status'] ?? ''); ?>
                        <tr>
                            <td class="small"><?= esc((string) ($r['title'] ?? '')) ?></td>
                            <td class="small text-secondary"><?= esc((string) ($r['sku'] ?? '')) ?></td>
                            <td class="small text-secondary"><?= esc((string) ($r['unit_name'] ?? '—')) ?></td>
                            <td class="text-end"><?= esc(rtrim(rtrim((string) ($r['on_hand'] ?? '0'), '0'), '.')) ?: '0' ?></td>
                            <td class="text-end text-secondary"><?= esc(rtrim(rtrim((string) ($r['reserved'] ?? '0'), '0'), '.')) ?: '0' ?></td>
                            <td class="text-end fw-medium"><?= esc(rtrim(rtrim((string) ($r['available'] ?? '0'), '0'), '.')) ?: '0' ?></td>
                            <td>
                                <span class="badge bg-<?= $st === 'in_stock' ? 'success' : ($st === 'low' ? 'warning' : 'secondary') ?>">
                                    <?= esc(str_replace('_', ' ', $st)) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <?php
                                // Carry THIS ROW's unit through. The grid has one row per
                                // (variant, unit), so dropping it meant the stock page fell
                                // back to the first allowed unit and wrote production into
                                // whichever plant that happened to be.
                                ?>
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= site_url('manufacturer/products/' . (int) ($r['product_id'] ?? 0) . '/stock')
                                       . (($r['mshop_id'] ?? 0) ? '?mshop_id=' . (int) $r['mshop_id'] : '') ?>">Manage</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
