<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php
$cards = [
    ['Units', $metrics['units'] ?? 0, 'bi-buildings', 'primary', 'manufacturer/units'],
    ['Products', $metrics['products'] ?? 0, 'bi-box-seam', 'info', 'manufacturer/products'],
    ['Published', $metrics['published'] ?? 0, 'bi-check2-circle', 'success', 'manufacturer/products?status=published'],
    ['Drafts', $metrics['drafts'] ?? 0, 'bi-pencil-square', 'warning', 'manufacturer/products?status=draft'],
];
?>
<div class="row g-3 mb-4">
    <?php foreach ($cards as [$label, $value, $icon, $colour, $link]): ?>
        <div class="col-sm-6 col-xl-3">
            <a class="text-decoration-none" href="<?= site_url($link) ?>">
                <div class="card h-100">
                    <div class="card-body d-flex align-items-center">
                        <i class="bi <?= esc($icon, 'attr') ?> fs-3 text-<?= esc($colour, 'attr') ?> me-3"></i>
                        <div>
                            <div class="text-secondary small"><?= esc($label) ?></div>
                            <div class="fs-4 fw-semibold"><?= esc((string) $value) ?></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Recent Products</span>
        <a class="btn btn-sm btn-primary" href="<?= site_url('manufacturer/products/new') ?>">
            <i class="bi bi-plus-lg"></i> New Product
        </a>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead>
                <tr>
                    <th>Product</th>
                    <th class="text-end">Making Price</th>
                    <th class="text-end">Selling Price</th>
                    <th>Status</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentProducts)): ?>
                    <tr><td colspan="5" class="text-center text-secondary py-4">No products yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentProducts as $p): ?>
                        <tr>
                            <td>
                                <a href="<?= site_url('manufacturer/products/' . (int) $p['id'] . '/edit') ?>">
                                    <?= esc($p['title'] ?? '') ?>
                                </a>
                            </td>
                            <td class="text-end">₹<?= esc(number_format((float) ($p['making_price'] ?? 0), 2)) ?></td>
                            <td class="text-end">₹<?= esc(number_format((float) ($p['base_price'] ?? 0), 2)) ?></td>
                            <td><span class="badge bg-secondary"><?= esc($p['status'] ?? '') ?></span></td>
                            <td class="small text-secondary"><?= esc((string) ($p['updated_at'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
