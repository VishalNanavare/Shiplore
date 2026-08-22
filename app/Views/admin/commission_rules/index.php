<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= site_url('admin/commission') ?>">Commission</a></li><li class="breadcrumb-item active">Rules</li></ol></nav>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Commission Rules</span><a href="<?= site_url('admin/commission-rules/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add</a></div>
    <div class="card-body">
        <?php if (empty($rules)): ?>
            <div class="text-center text-secondary py-5">No commission rules yet.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle w-100">
                <thead class="table-light"><tr><th>Scope</th><th>Type</th><th>Value</th><th>GMV range</th><th>Priority</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rules as $r): ?>
                    <tr>
                        <td>
                            <?php if ($r['product_id']): ?>Product #<?= (int) $r['product_id'] ?>
                            <?php elseif ($r['category_id']): ?>Category #<?= (int) $r['category_id'] ?>
                            <?php elseif ($r['business_type_id']): ?>Business type #<?= (int) $r['business_type_id'] ?>
                            <?php endif; ?>
                        </td>
                        <td><?= esc($r['commission_type']) ?></td>
                        <td><?= $r['commission_type'] === 'fixed' ? '₹' . esc((string) $r['fixed_amount']) : esc((string) $r['rate']) . '%' ?></td>
                        <td><?= esc((string) ($r['min_gmv'] ?? '—')) ?> – <?= esc((string) ($r['max_gmv'] ?? '—')) ?></td>
                        <td><?= (int) $r['priority'] ?></td>
                        <td class="text-end">
                            <a href="<?= site_url('admin/commission-rules/' . $r['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= site_url('admin/commission-rules/' . $r['id'] . '/delete') ?>" class="d-inline" onsubmit="return confirm('Delete this rule?')"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
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
