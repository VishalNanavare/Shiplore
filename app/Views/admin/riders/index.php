<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $sb = ['active' => 'success', 'suspended' => 'warning', 'terminated' => 'dark']; $ab = ['available' => 'success', 'busy' => 'info', 'offline' => 'secondary']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold">Delivery riders <span class="text-secondary small fw-normal">(<?= count($riders) ?>)</span></span>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <a href="<?= site_url('admin/rider-plans') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-cash-stack me-1"></i>Payout plans</a>
            <form method="get" class="d-flex gap-2">
                <select name="vendor_id" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                    <option value="">All vendors</option>
                    <?php foreach ($vendors as $v): ?><option value="<?= esc($v['id'], 'attr') ?>" <?= (int) $vendorId === (int) $v['id'] ? 'selected' : '' ?>><?= esc($v['display_name']) ?></option><?php endforeach; ?>
                </select>
                <select name="status" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                    <option value="">Any status</option>
                    <?php foreach (['active', 'suspended', 'terminated'] as $s): ?><option value="<?= $s ?>" <?= ($status ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Rider</th><th>Vendor</th><th>Vehicle</th><th>Availability</th><th class="text-center">Load</th><th>Plan</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($riders as $r): ?>
            <tr>
                <td><div class="fw-medium"><?= esc($r['name'] ?? '—') ?></div><div class="text-secondary small"><?= esc($r['phone'] ?? '') ?></div></td>
                <td class="small"><?= esc($r['vendor'] ?? '—') ?></td>
                <td class="small text-capitalize"><?= esc($r['vehicle_type'] ?? '—') ?> <span class="text-secondary"><?= esc($r['vehicle_no'] ?? '') ?></span></td>
                <td><span class="badge text-bg-<?= esc($ab[$r['availability']] ?? 'secondary', 'attr') ?>"><?= esc($r['availability']) ?></span></td>
                <td class="text-center"><?= (int) ($r['active_load'] ?? 0) ?></td>
                <td class="small"><?= esc($r['plan'] ?? '—') ?></td>
                <td><span class="badge text-bg-<?= esc($sb[$r['status']] ?? 'secondary', 'attr') ?>"><?= esc($r['status']) ?></span></td>
                <td class="text-end"><a href="<?= site_url('admin/riders/' . $r['id']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($riders)): ?><tr><td colspan="8" class="text-center text-secondary py-4">No riders yet. Vendors register riders from their panel.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
