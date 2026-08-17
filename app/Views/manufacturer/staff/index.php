<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php if (session('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session('success')) ?></div>
<?php endif; ?>
<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Staff</h5>
    <?php if (! empty($canManage)): ?>
        <a class="btn btn-sm btn-primary" href="<?= site_url('manufacturer/staff/new') ?>">
            <i class="bi bi-plus-lg"></i> Add Staff
        </a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle" id="staffTable">
            <thead>
                <tr>
                    <th>Name</th><th>Role</th><th>Units</th><th>Contact</th><th>Status</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staff)): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-4">
                        No staff yet. Add the people who run your manufacturing units.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($staff as $s): ?>
                        <tr>
                            <td><?= esc($s['name'] ?? '') ?>
                                <?php if (! empty($s['employee_code'])): ?>
                                    <span class="text-secondary small">· <?= esc($s['employee_code']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= esc(str_replace('_', ' ', (string) ($s['staff_type'] ?? ''))) ?></td>
                            <td class="small"><?= esc((string) ($s['units'] ?? '—')) ?></td>
                            <td class="small">
                                <?= esc((string) ($s['email'] ?? '')) ?>
                                <?php if (! empty($s['phone'])): ?><br><span class="text-secondary"><?= esc($s['phone']) ?></span><?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= ($s['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>">
                                    <?= esc((string) ($s['status'] ?? '')) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <?php if (! empty($canManage)): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('manufacturer/staff/' . (int) $s['id'] . '/edit') ?>">Edit</a>
                                    <form method="post" action="<?= site_url('manufacturer/staff/' . (int) $s['id'] . '/suspend') ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="status" value="<?= ($s['status'] ?? '') === 'active' ? 'suspended' : 'active' ?>">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">
                                            <?= ($s['status'] ?? '') === 'active' ? 'Suspend' : 'Reactivate' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
