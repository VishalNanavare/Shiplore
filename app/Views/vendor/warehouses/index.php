<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Warehouses</span><span class="text-secondary small"><?= count($warehouses) ?></span></div>
    <div class="card-body">
        <?php if (empty($warehouses)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-buildings display-6 d-block mb-2"></i>No warehouses linked yet.</div>
        <?php else: ?>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Warehouse</th><th>Code</th><th>Pincode</th><th>State</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($warehouses as $w): ?>
                <tr>
                    <td class="fw-semibold"><?= esc($w['name']) ?></td>
                    <td class="small"><?= esc($w['code']) ?></td>
                    <td class="small"><?= esc($w['pincode']) ?></td>
                    <td class="small"><?= esc($w['state_code']) ?></td>
                    <td><span class="badge text-bg-<?= $w['status'] === 'active' ? 'success' : 'secondary' ?>"><?= esc($w['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
