<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= site_url('admin/attributes') ?>">Attributes</a></li><li class="breadcrumb-item"><a href="<?= site_url('admin/attributes/' . $attribute['id'] . '/values') ?>"><?= esc($attribute['name']) ?></a></li><li class="breadcrumb-item active"><?= $row ? 'Edit value' : 'New value' ?></li></ol></nav>

<div class="card" style="max-width: 480px;">
    <div class="card-header fw-semibold"><?= $row ? 'Edit value' : 'New value' ?> — <?= esc($attribute['name']) ?></div>
    <div class="card-body">
        <form method="post" action="<?= $row
            ? site_url('admin/attributes/' . $attribute['id'] . '/values/' . $row['id'] . '/update')
            : site_url('admin/attributes/' . $attribute['id'] . '/values/store') ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label">Value</label>
                <input type="text" name="value" class="form-control" value="<?= esc(old('value', $row['value'] ?? '')) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Sort order</label>
                <input type="number" name="sort_order" class="form-control" value="<?= esc((string) old('sort_order', (string) ($row['sort_order'] ?? 0))) ?>">
            </div>
            <button type="submit" class="btn btn-primary"><?= $row ? 'Save changes' : 'Create value' ?></button>
            <a href="<?= site_url('admin/attributes/' . $attribute['id'] . '/values') ?>" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
<?= $this->endSection() ?>
