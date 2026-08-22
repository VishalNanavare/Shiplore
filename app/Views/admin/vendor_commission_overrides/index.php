<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= site_url('admin/commission') ?>">Commission</a></li><li class="breadcrumb-item active">Vendor Overrides</li></ol></nav>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Vendor Commission Overrides</span><a href="<?= site_url('admin/vendor-commission-overrides/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add</a></div>
    <div class="card-body">
        <?php if (empty($overrides)): ?>
            <div class="text-center text-secondary py-5">No overrides yet.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle w-100">
                <thead class="table-light"><tr><th>Vendor</th><th>Category</th><th>Rate</th><th>Valid</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($overrides as $o): ?>
                    <tr>
                        <td>Vendor #<?= (int) $o['vendor_id'] ?></td>
                        <td><?= $o['category_id'] ? 'Category #' . (int) $o['category_id'] : '<span class="text-secondary">vendor-wide</span>' ?></td>
                        <td><?= esc((string) $o['rate']) ?>%</td>
                        <td><?= esc($o['valid_from']) ?> – <?= esc($o['valid_to'] ?? '∞') ?></td>
                        <td><span class="badge text-bg-<?= $o['status'] === 'active' ? 'success' : 'secondary' ?>"><?= esc($o['status']) ?></span></td>
                        <td class="text-end">
                            <a href="<?= site_url('admin/vendor-commission-overrides/' . $o['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= site_url('admin/vendor-commission-overrides/' . $o['id'] . '/delete') ?>" class="d-inline" onsubmit="return confirm('Delete this override?')"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
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
