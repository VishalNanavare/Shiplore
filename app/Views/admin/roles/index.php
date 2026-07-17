<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<div class="card"><div class="card-header fw-semibold">Roles &amp; Permissions <span class="text-secondary small fw-normal">(<?= count($roles) ?>)</span></div>
<div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Role</th><th>Code</th><th>Scope</th><th>Permissions</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($roles as $r): ?>
        <tr>
            <td class="fw-medium"><?= esc($r['name']) ?> <?php if ($r['is_system']): ?><span class="badge bg-light text-secondary border">system</span><?php endif; ?></td>
            <td><code><?= esc($r['code']) ?></code></td>
            <td class="small text-secondary"><?= esc($r['scope_class'] ?? '—') ?></td>
            <td><span class="badge bg-primary-subtle text-primary"><?= esc($r['perms']) ?> perms</span></td>
            <td class="text-end"><a href="<?= site_url('admin/roles/' . $r['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-shield-lock me-1"></i>Permissions</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<?= $this->endSection() ?>
