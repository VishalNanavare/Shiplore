<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Units</h5>
    <a class="btn btn-sm btn-primary" href="<?= site_url('manufacturer/units/new') ?>">
        <i class="bi bi-plus-lg"></i> New Unit
    </a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead>
                <tr>
                    <th>Name</th><th>Code</th><th>City / PIN</th><th>State</th><th>GSTIN</th><th>Status</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($units)): ?>
                    <tr><td colspan="7" class="text-center text-secondary py-4">
                        No units yet. Add the factory or unit where you produce goods.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($units as $u): ?>
                        <tr>
                            <td><?= esc($u['name'] ?? '') ?></td>
                            <td class="small text-secondary"><?= esc($u['code'] ?? '') ?></td>
                            <td class="small"><?= esc((string) ($u['pincode'] ?? '')) ?></td>
                            <td class="small"><?= esc((string) ($u['state_code'] ?? '')) ?></td>
                            <td class="small"><?= esc((string) ($u['gstin'] ?? '—')) ?></td>
                            <td>
                                <span class="badge bg-<?= ($u['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>">
                                    <?= esc((string) ($u['status'] ?? '')) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('manufacturer/units/' . (int) $u['id'] . '/edit') ?>">Edit</a>
                                <form method="post" action="<?= site_url('manufacturer/units/' . (int) $u['id'] . '/toggle') ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-secondary" type="submit">
                                        <?= ($u['status'] ?? '') === 'active' ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
