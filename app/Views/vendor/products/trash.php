<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-trash me-1"></i>Deleted drafts <span class="text-secondary small fw-normal">(<?= count($products) ?>)</span></span>
        <a href="<?= site_url('vendor/products') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to products</a>
    </div>
    <div class="card-body">
        <?php if (empty($products)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-trash display-6 d-block mb-2"></i>Trash is empty.</div>
        <?php else: ?>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Product</th><th>Category</th><th>SKU</th><th>Deleted</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td class="fw-semibold"><?= esc($p['title']) ?></td>
                    <td><?= esc($p['category'] ?? '—') ?></td>
                    <td class="small text-secondary"><?= esc($p['sku'] ?? '—') ?></td>
                    <td class="small text-secondary"><?= esc($p['deleted_at']) ?></td>
                    <td class="text-end">
                        <form method="post" action="<?= site_url('vendor/products/' . $p['id'] . '/restore') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise me-1"></i>Restore</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
