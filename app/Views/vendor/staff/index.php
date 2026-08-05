<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php
$staffBadge = ['active' => 'success', 'suspended' => 'warning', 'terminated' => 'danger'];
$availBadge = ['available' => 'success', 'busy' => 'warning', 'offline' => 'secondary'];
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<?php if (! empty($canAddRider)): ?>
<div class="card mb-3"><div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-person-plus me-1"></i>Add delivery rider</h2>
    <form method="post" action="<?= site_url('vendor/staff/riders') ?>" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <div class="col-md-3"><label class="form-label small">Name</label><input name="name" class="form-control form-control-sm" required></div>
        <div class="col-md-2"><label class="form-label small">Phone</label><input name="phone" class="form-control form-control-sm" placeholder="+91…" required></div>
        <div class="col-md-2"><label class="form-label small">Vehicle</label>
            <select name="vehicle_type" class="form-select form-select-sm"><?php foreach (['bike', 'scooter', 'bicycle', 'car', 'foot'] as $v): ?><option value="<?= $v ?>"><?= ucfirst($v) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-2"><label class="form-label small">Vehicle no.</label><input name="vehicle_no" class="form-control form-control-sm"></div>
        <div class="col-md-2"><label class="form-label small">Max active</label><input name="max_active_orders" type="number" class="form-control form-control-sm" value="5"></div>
        <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Add</button></div>
    </form>
    <div class="form-text mt-1">The rider signs in to the Delivery app with this phone (OTP).</div>
</div></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7"><div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Staff</span>
            <?php if (! empty($canManage)): ?><a href="<?= site_url('vendor/staff/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-person-plus me-1"></i>Add staff</a><?php else: ?><span class="text-secondary small"><?= count($staff) ?></span><?php endif; ?>
        </div>
        <div class="table-responsive" data-ajax-region><table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Name</th><th>Role</th><th>Shops</th><th>Status</th><?php if (! empty($canManage)): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($staff as $s): ?>
                <tr>
                    <td><div class="fw-medium"><?= esc($s['name'] ?? '—') ?></div><div class="text-secondary small"><?= esc($s['email'] ?? '') ?></div></td>
                    <td class="text-capitalize small"><?= esc(str_replace('_', ' ', $s['staff_type'])) ?><?php if (! empty($s['employee_code'])): ?><div class="text-secondary"><?= esc($s['employee_code']) ?></div><?php endif; ?></td>
                    <td class="small"><?= esc($s['shops'] ?: '—') ?></td>
                    <td><span class="badge text-bg-<?= esc($staffBadge[$s['status']] ?? 'secondary', 'attr') ?>"><?= esc($s['status']) ?></span></td>
                    <?php if (! empty($canManage)): ?>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1">
                            <a href="<?= site_url('vendor/staff/' . $s['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= site_url('vendor/staff/' . $s['id'] . '/suspend') ?>" data-ajax-refresh><?= csrf_field() ?><input type="hidden" name="status" value="<?= $s['status'] === 'active' ? 'suspended' : 'active' ?>"><button class="btn btn-sm btn-outline-<?= $s['status'] === 'active' ? 'warning' : 'success' ?>" title="<?= $s['status'] === 'active' ? 'Suspend' : 'Reactivate' ?>"><i class="bi bi-<?= $s['status'] === 'active' ? 'pause' : 'play' ?>"></i></button></form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($staff)): ?><tr><td colspan="5" class="text-center text-secondary py-3">No staff yet.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div></div>
    <div class="col-lg-5"><div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Delivery Riders</span><span class="text-secondary small"><?= count($riders) ?></span></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Rider</th><th>Vehicle</th><th>Availability</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($riders as $r): ?>
                <tr>
                    <td><div class="fw-medium"><?= esc($r['name'] ?? '—') ?></div><div class="text-secondary small"><?= esc($r['phone'] ?? '') ?></div></td>
                    <td class="small text-capitalize"><?= esc($r['vehicle_type']) ?><div class="text-secondary"><?= esc($r['vehicle_no'] ?? '') ?></div></td>
                    <td><span class="badge text-bg-<?= esc($availBadge[$r['availability']] ?? 'secondary', 'attr') ?>"><?= esc($r['availability']) ?></span></td>
                    <td><span class="badge text-bg-<?= esc($staffBadge[$r['status']] ?? 'secondary', 'attr') ?>"><?= esc($r['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($riders)): ?><tr><td colspan="4" class="text-center text-secondary py-3">No riders yet.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div></div>
</div>
<?= $this->endSection() ?>
