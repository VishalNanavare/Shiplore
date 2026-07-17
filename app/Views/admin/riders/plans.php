<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $g = static fn ($k, $d = '') => esc((string) ($edit[$k] ?? $d), 'attr'); $isEdit = $edit !== null; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="mb-3"><a href="<?= site_url('admin/riders') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back to riders</a></div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card"><div class="card-header fw-semibold"><?= $isEdit ? 'Edit plan' : 'New payout plan' ?></div><div class="card-body">
            <form method="post" action="<?= $isEdit ? site_url('admin/rider-plans/' . $edit['id'] . '/update') : site_url('admin/rider-plans/store') ?>">
                <?= csrf_field() ?>
                <div class="mb-2"><label class="form-label small">Plan name</label><input name="name" class="form-control form-control-sm" value="<?= $g('name') ?>" required></div>
                <div class="mb-2"><label class="form-label small">Model</label>
                    <select name="model" class="form-select form-select-sm">
                        <?php foreach ($models as $mo): ?><option value="<?= $mo ?>" <?= ($edit['model'] ?? '') === $mo ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $mo)) ?></option><?php endforeach; ?>
                    </select>
                    <div class="form-text">fixed&nbsp;%&nbsp;of&nbsp;order · per&nbsp;km · per&nbsp;order&nbsp;flat · salary (payroll) · hybrid (per-order after threshold)</div>
                </div>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label small">% of order</label><input name="pct_of_order" type="number" step="0.01" class="form-control form-control-sm" value="<?= $g('pct_of_order', '0') ?>"></div>
                    <div class="col-6"><label class="form-label small">Per-km ₹</label><input name="per_km_rate" type="number" step="0.01" class="form-control form-control-sm" value="<?= $g('per_km_rate', '0') ?>"></div>
                    <div class="col-6"><label class="form-label small">Per-order ₹</label><input name="per_order_amount" type="number" step="0.01" class="form-control form-control-sm" value="<?= $g('per_order_amount', '0') ?>"></div>
                    <div class="col-6"><label class="form-label small">Base salary ₹</label><input name="base_salary" type="number" step="0.01" class="form-control form-control-sm" value="<?= $g('base_salary', '0') ?>"></div>
                    <div class="col-6"><label class="form-label small">Incentive threshold</label><input name="incentive_threshold" type="number" class="form-control form-control-sm" value="<?= $g('incentive_threshold', '0') ?>"></div>
                    <div class="col-6"><label class="form-label small">Vendor (optional)</label>
                        <select name="vendor_id" class="form-select form-select-sm">
                            <option value="">Company-wide</option>
                            <?php foreach ($vendors as $v): ?><option value="<?= esc($v['id'], 'attr') ?>" <?= (int) ($edit['vendor_id'] ?? 0) === (int) $v['id'] ? 'selected' : '' ?>><?= esc($v['display_name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button class="btn btn-primary btn-sm mt-3 w-100"><?= $isEdit ? 'Save changes' : 'Create plan' ?></button>
                <?php if ($isEdit): ?><a href="<?= site_url('admin/rider-plans') ?>" class="btn btn-link btn-sm w-100">Cancel edit</a><?php endif; ?>
            </form>
        </div></div>
    </div>

    <div class="col-lg-7">
        <div class="card"><div class="card-header fw-semibold">Payout plans <span class="text-secondary small fw-normal">(<?= count($plans) ?>)</span></div>
            <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Name</th><th>Model</th><th>Rate</th><th>Scope</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($plans as $p): ?>
                    <tr>
                        <td class="fw-medium"><?= esc($p['name']) ?></td>
                        <td class="small text-capitalize"><?= esc(str_replace('_', ' ', $p['model'])) ?></td>
                        <td class="small text-secondary"><?php
                            echo match ($p['model']) {
                                'fixed_commission' => $p['pct_of_order'] . '%',
                                'per_km'           => '₹' . $p['per_km_rate'] . '/km',
                                'per_order'        => '₹' . $p['per_order_amount'],
                                'salary'           => '₹' . $p['base_salary'] . '/mo',
                                'hybrid'           => '₹' . $p['per_order_amount'] . ' after ' . $p['incentive_threshold'],
                                default            => '—',
                            }; ?></td>
                        <td class="small"><?= esc($p['vendor'] ?? 'Company-wide') ?></td>
                        <td><span class="badge text-bg-<?= $p['status'] === 'active' ? 'success' : 'secondary' ?>"><?= esc($p['status']) ?></span></td>
                        <td class="text-end">
                            <a href="<?= site_url('admin/rider-plans/' . $p['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= site_url('admin/rider-plans/' . $p['id'] . '/toggle') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm <?= $p['status'] === 'active' ? 'btn-outline-warning' : 'btn-outline-success' ?>"><i class="bi bi-toggle-<?= $p['status'] === 'active' ? 'on' : 'off' ?>"></i></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($plans)): ?><tr><td colspan="6" class="text-center text-secondary py-3">No plans yet — create one on the left.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
