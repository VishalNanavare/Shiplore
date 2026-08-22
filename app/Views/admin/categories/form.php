<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $isEdit = $row !== null; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="row"><div class="col-lg-6">
    <div class="card"><div class="card-body p-4">
        <form method="post" action="<?= $isEdit ? site_url('admin/categories/' . $row['id'] . '/update') : site_url('admin/categories/store') ?>">
            <?= csrf_field() ?>
            <div class="mb-3"><label class="form-label">Name <span class="text-danger">*</span></label><input name="name" class="form-control" value="<?= esc($isEdit ? $row['name'] : old('name'), 'attr') ?>" required></div>
            <?php if (! $isEdit): ?>
            <div class="mb-3"><label class="form-label">Parent category</label>
                <select name="parent_id" class="form-select"><option value="">— top level —</option>
                    <?php foreach ($options as $o): ?><option value="<?= esc($o['id'], 'attr') ?>" <?= (int) old('parent_id') === (int) $o['id'] ? 'selected' : '' ?>><?= str_repeat('— ', (int) $o['level']) . esc($o['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-6"><label class="form-label">Default commission %</label><input name="default_commission_rate" type="number" step="any" class="form-control" value="<?= esc($isEdit ? ($row['default_commission_rate'] ?? '') : old('default_commission_rate'), 'attr') ?>"></div>
                <div class="col-6"><label class="form-label">Sort order</label><input name="sort_order" type="number" class="form-control" value="<?= esc($isEdit ? ($row['sort_order'] ?? 0) : old('sort_order', '0'), 'attr') ?>"></div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= $isEdit ? 'Update' : 'Create' ?></button>
                <?php if ($isEdit): ?><a href="<?= site_url('admin/categories/' . $row['id'] . '/attributes') ?>" class="btn btn-outline-primary"><i class="bi bi-tags me-1"></i>Manage attributes</a><?php endif; ?>
                <a href="<?= site_url('admin/categories') ?>" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div></div>
</div></div>
<?= $this->endSection() ?>
