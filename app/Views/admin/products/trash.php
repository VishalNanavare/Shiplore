<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-trash me-1"></i>Deleted drafts <span class="text-secondary small fw-normal">(<?= count($products) ?>)</span></span>
        <a href="<?= site_url('admin/products') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to products</a>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Product</th><th>Vendor</th><th>Category</th><th>SKU</th><th>Deleted</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td class="fw-medium"><?= esc($p['title']) ?></td>
                <td class="small"><?= esc($p['vendor'] ?? '—') ?></td>
                <td class="small"><?= esc($p['category'] ?? '—') ?></td>
                <td><code><?= esc($p['sku'] ?? '—') ?></code></td>
                <td class="small text-secondary"><?= esc($p['deleted_at']) ?></td>
                <td class="text-end">
                    <form method="post" action="<?= site_url('admin/products/' . $p['id'] . '/restore') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise me-1"></i>Restore</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($products)): ?><tr><td colspan="6" class="text-center text-secondary py-4">Trash is empty.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
