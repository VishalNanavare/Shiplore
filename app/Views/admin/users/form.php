<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<div class="row"><div class="col-lg-6">
    <div class="card"><div class="card-body p-4">
        <form method="post" action="<?= site_url('admin/users/store') ?>">
            <?= csrf_field() ?>
            <div class="mb-3"><label class="form-label">Name <span class="text-danger">*</span></label><input name="name" class="form-control" value="<?= esc(old('name'), 'attr') ?>" required></div>
            <div class="mb-3"><label class="form-label">Email <span class="text-danger">*</span></label><input type="email" name="email" class="form-control" value="<?= esc(old('email'), 'attr') ?>" required></div>
            <div class="row g-3">
                <div class="col-6"><label class="form-label">Phone</label><input name="phone" class="form-control" value="<?= esc(old('phone'), 'attr') ?>"></div>
                <div class="col-6"><label class="form-label">Password <span class="text-danger">*</span></label><input type="password" name="password" class="form-control" minlength="8" required></div>
            </div>
            <div class="mb-3 mt-3"><label class="form-label">Role</label>
                <select name="role_id" class="form-select"><option value="">— no role —</option>
                    <?php foreach ($roles as $r): ?><option value="<?= esc($r['id'], 'attr') ?>"><?= esc($r['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Create user</button><a href="<?= site_url('admin/users') ?>" class="btn btn-light">Cancel</a></div>
        </form>
    </div></div>
</div></div>
<?= $this->endSection() ?>
