<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php if (session('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session('success')) ?></div>
<?php endif; ?>
<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Trash <span class="text-secondary small">(<?= count($products) ?>)</span></h5>
    <a class="btn btn-sm btn-light" href="<?= site_url('manufacturer/products') ?>">
        <i class="bi bi-arrow-left me-1"></i>Back to products
    </a>
</div>

<p class="small text-secondary">
    Deleted drafts. Only drafts can be deleted, so nothing submitted or live ever lands here.
</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle" id="trashTable">
            <thead><tr><th>Product</th><th>SKU</th><th>Category</th><th>Deleted</th><th class="text-end"></th></tr></thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="5" class="text-center text-secondary py-4">Trash is empty.</td></tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td><?= esc($p['title'] ?? '') ?></td>
                            <td class="small text-secondary"><?= esc(($p['sku'] ?? '') !== '' ? $p['sku'] : '—') ?></td>
                            <td class="small"><?= esc((string) ($p['category'] ?? '—')) ?></td>
                            <td class="small text-secondary"><?= esc(substr((string) ($p['deleted_at'] ?? ''), 0, 16)) ?></td>
                            <td class="text-end">
                                <?php if (! empty($canUpdate)): ?>
                                    <form method="post" action="<?= site_url('manufacturer/products/' . (int) $p['id'] . '/restore') ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">
                                            <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
