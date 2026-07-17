<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $isEdit = $row !== null; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="row"><div class="col-lg-7">
    <div class="card"><div class="card-body p-4">
        <form method="post" action="<?= $isEdit ? site_url('admin/' . $spec['slug'] . '/' . $row['id'] . '/update') : site_url('admin/' . $spec['slug'] . '/store') ?>">
            <?= csrf_field() ?>
            <?php foreach ($spec['fields'] as $f): [$key, $label, $type, $req] = [$f[0], $f[1], $f[2], $f[3] ?? false]; $opts = $f[4] ?? []; $val = $isEdit ? ($row[$key] ?? '') : old($key); ?>
                <div class="mb-3">
                    <label class="form-label"><?= esc($label) ?> <?php if ($req): ?><span class="text-danger">*</span><?php endif; ?></label>
                    <?php if ($type === 'select'): ?>
                        <select name="<?= esc($key, 'attr') ?>" class="form-select" <?= $req ? 'required' : '' ?>>
                            <option value="">—</option>
                            <?php foreach ($opts as $ov => $ol): ?><option value="<?= esc($ov, 'attr') ?>" <?= (string) $val === (string) $ov ? 'selected' : '' ?>><?= esc($ol) ?></option><?php endforeach; ?>
                        </select>
                    <?php elseif ($type === 'checkbox'): ?>
                        <div class="form-check"><input type="checkbox" class="form-check-input" name="<?= esc($key, 'attr') ?>" value="1" <?= $val ? 'checked' : '' ?>></div>
                    <?php elseif ($type === 'textarea'): ?>
                        <textarea name="<?= esc($key, 'attr') ?>" class="form-control" rows="3" <?= $req ? 'required' : '' ?>><?= esc((string) $val) ?></textarea>
                    <?php elseif ($type === 'date'): ?>
                        <input type="date" name="<?= esc($key, 'attr') ?>" class="form-control" value="<?= esc(substr((string) $val, 0, 10), 'attr') ?>" <?= $req ? 'required' : '' ?>>
                    <?php else: ?>
                        <input type="<?= $type === 'number' ? 'number' : 'text' ?>" step="any" name="<?= esc($key, 'attr') ?>" class="form-control" value="<?= esc((string) $val, 'attr') ?>" <?= $req ? 'required' : '' ?>>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= $isEdit ? 'Update' : 'Create' ?></button>
                <a href="<?= site_url('admin/' . $spec['slug']) ?>" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div></div>
</div></div>
<?= $this->endSection() ?>
