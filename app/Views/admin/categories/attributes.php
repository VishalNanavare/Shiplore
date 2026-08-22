<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= site_url('admin/categories') ?>">Categories</a></li><li class="breadcrumb-item active"><?= esc($category['name']) ?></li></ol></nav>

<div class="card">
    <div class="card-header fw-semibold">Attributes for "<?= esc($category['name']) ?>"</div>
    <div class="card-body">
        <p class="text-secondary small">Only checked attributes appear on this category's product form and Variants page. A category with nothing checked shows no attribute fields at all — it is not yet configured, not "everything".</p>
        <?php if (empty($attributes)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-tags display-6 d-block mb-2"></i>No active attributes yet. <a href="<?= site_url('admin/attributes/new') ?>">Create one</a>.</div>
        <?php else: ?>
        <form method="post" action="<?= site_url('admin/categories/' . $category['id'] . '/attributes/save') ?>">
            <?= csrf_field() ?>
            <div class="row g-2">
                <?php foreach ($attributes as $a): ?>
                    <div class="col-md-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="attribute_ids[]" value="<?= (int) $a['id'] ?>" id="attr<?= (int) $a['id'] ?>" <?= in_array((int) $a['id'], $mappedIds, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="attr<?= (int) $a['id'] ?>"><?= esc($a['name']) ?> <span class="text-secondary small">(<?= esc($a['type']) ?><?= $a['is_variant_defining'] ? ', variant' : '' ?>)</span></label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary mt-3">Save mapping</button>
            <a href="<?= site_url('admin/categories/' . $category['id'] . '/edit') ?>" class="btn btn-outline-secondary mt-3">Back to category</a>
        </form>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
