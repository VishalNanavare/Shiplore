<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= site_url('admin/attributes') ?>">Attributes</a></li><li class="breadcrumb-item active"><?= esc($attribute['name']) ?></li></ol></nav>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Values for "<?= esc($attribute['name']) ?>"</span>
        <a href="<?= site_url('admin/attributes/' . $attribute['id'] . '/values/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add value</a>
    </div>
    <div class="card-body">
        <?php if (empty($values)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-list-ul display-6 d-block mb-2"></i>No values yet.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle w-100">
                <thead class="table-light"><tr><th>Value</th><th>Sort order</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($values as $v): ?>
                    <tr>
                        <td class="fw-semibold"><?= esc($v['value']) ?></td>
                        <td><?= (int) $v['sort_order'] ?></td>
                        <td><span class="badge text-bg-<?= $v['status'] === 'active' ? 'success' : 'secondary' ?>"><?= esc($v['status']) ?></span></td>
                        <td class="text-end">
                            <a href="<?= site_url('admin/attributes/' . $attribute['id'] . '/values/' . $v['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <?php if ($v['status'] !== 'active'): ?>
                                <form method="post" action="<?= site_url('admin/attributes/' . $attribute['id'] . '/values/' . $v['id'] . '/activate') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Activate</button></form>
                            <?php else: ?>
                                <form method="post" action="<?= site_url('admin/attributes/' . $attribute['id'] . '/values/' . $v['id'] . '/deactivate') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pause"></i> Deactivate</button></form>
                            <?php endif; ?>
                            <form method="post" action="<?= site_url('admin/attributes/' . $attribute['id'] . '/values/' . $v['id'] . '/delete') ?>" onsubmit="return confirm('Delete this value? This cannot be undone. Blocked automatically if it is still in use by a published product.')" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
