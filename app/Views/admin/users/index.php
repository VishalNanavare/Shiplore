<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Staff Users <span class="text-secondary small fw-normal">(<?= count($users) ?>)</span></span>
        <a href="<?= site_url('admin/users/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Staff</a>
    </div>
    <div class="table-responsive" data-ajax-region><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Name</th><th>Email</th><th>Roles</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td class="fw-medium"><?= esc($u['name']) ?></td>
                <td class="small"><?= esc($u['email']) ?></td>
                <td class="small text-secondary"><?= esc($u['roles'] ?? '—') ?></td>
                <td><span class="badge text-bg-<?= $u['status'] === 'active' ? 'success' : 'secondary' ?>"><?= esc($u['status']) ?></span></td>
                <td class="text-end">
                    <?php if ($u['status'] === 'active'): ?>
                        <form method="post" action="<?= site_url('admin/users/' . $u['id'] . '/suspend') ?>" class="d-inline" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-outline-warning"><i class="bi bi-pause"></i> Suspend</button></form>
                    <?php else: ?>
                        <form method="post" action="<?= site_url('admin/users/' . $u['id'] . '/activate') ?>" class="d-inline" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-outline-success"><i class="bi bi-check2"></i> Activate</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
