<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><?= esc($spec['label']) ?>s <span class="text-secondary small fw-normal">(<?= count($rows) ?>)</span></span>
        <a href="<?= site_url('admin/' . $spec['slug'] . '/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add <?= esc($spec['label']) ?></a>
    </div>
    <div class="table-responsive" data-ajax-region><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr>
            <?php foreach (($spec['columns'] ?? [['name', 'Name']]) as $c): ?><th><?= esc($c[1]) ?></th><?php endforeach; ?>
            <th>Status</th><th class="text-end">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <?php foreach (($spec['columns'] ?? [['name', 'Name']]) as $c): ?><td><?= esc((string) ($r[$c[0]] ?? '—')) ?></td><?php endforeach; ?>
                <?php $isActive = ($r['status'] ?? 'active') === 'active'; ?>
                <td><span class="badge text-bg-<?= $isActive ? 'success' : 'secondary' ?>"><?= esc($r['status'] ?? '—') ?></span></td>
                <td class="text-end">
                    <a href="<?= site_url('admin/' . $spec['slug'] . '/' . $r['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                    <?php
                    // Build the whole prompt in PHP and escape ONCE. Written inline with
                    // nested quotes, the attribute value ended at the second `"` and the
                    // record name became stray attributes on the form — so the dialog read
                    // 'Deactivate "' with no name, on every master list.
                    $confirmName = (string) ($r[($spec['columns'][0][0] ?? 'name')] ?? '');
                    $confirmMsg  = ($isActive ? 'Deactivate' : 'Activate') . ' "' . $confirmName . '"?';
                    ?>
                    <form method="post" action="<?= site_url('admin/' . $spec['slug'] . '/' . $r['id'] . '/toggle') ?>" data-ajax-refresh class="d-inline" data-confirm="<?= esc($confirmMsg, 'attr') ?>"><?= csrf_field() ?><button class="btn btn-sm <?= $isActive ? 'btn-success' : 'btn-outline-secondary' ?>" title="<?= $isActive ? 'Active — click to deactivate' : 'Inactive — click to activate' ?>"><i class="bi bi-toggle-<?= $isActive ? 'on' : 'off' ?>"></i></button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?><tr><td colspan="9" class="text-center text-secondary py-4">None yet. Click "Add <?= esc($spec['label']) ?>".</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
