<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<?php $locked = $role['code'] === 'super_admin'; ?>
<a href="<?= site_url('admin/roles') ?>" class="btn btn-sm btn-light mb-3"><i class="bi bi-arrow-left"></i> Roles</a>

<form method="post" action="<?= site_url('admin/roles/' . $role['id'] . '/update') ?>">
    <?= csrf_field() ?>
    <div class="card"><div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><?= esc($role['name']) ?> — permissions</span>
        <?php if (! $locked): ?><button class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>Save permissions</button><?php endif; ?>
    </div><div class="card-body">
        <?php if ($locked): ?><div class="alert alert-warning"><i class="bi bi-shield-lock me-1"></i>The Super Admin role holds all permissions and is protected from edits.</div><?php endif; ?>
        <div class="row g-3">
            <?php foreach ($modules as $module => $perms): ?>
                <div class="col-md-6 col-lg-4"><div class="border rounded p-2 h-100">
                    <div class="fw-semibold small text-uppercase text-secondary mb-2"><?= esc($module) ?></div>
                    <?php foreach ($perms as $p): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="perm_ids[]" value="<?= esc($p['id'], 'attr') ?>" id="p<?= $p['id'] ?>" <?= in_array((int) $p['id'], $assigned, true) ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
                            <label class="form-check-label small" for="p<?= $p['id'] ?>" title="<?= esc($p['description'] ?? '', 'attr') ?>"><?= esc($p['code']) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div></div>
            <?php endforeach; ?>
        </div>
    </div></div>
</form>
<?= $this->endSection() ?>
