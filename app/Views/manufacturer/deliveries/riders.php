<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php if (session('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session('success')) ?></div>
<?php endif; ?>
<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <strong>Riders</strong>
                <span class="text-secondary small"><?= count($riders) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle" id="ridersTable">
                    <thead><tr><th>Name</th><th>Phone</th><th>Vehicle</th><th>Availability</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if (empty($riders)): ?>
                            <tr><td colspan="5" class="text-center text-secondary py-4">
                                No riders yet. Add one to assign dispatched purchase orders.
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($riders as $r): ?>
                                <tr>
                                    <td class="small"><?= esc((string) ($r['name'] ?? '')) ?></td>
                                    <td class="small text-secondary"><?= esc((string) ($r['phone'] ?? '')) ?></td>
                                    <td class="small">
                                        <?= esc((string) ($r['vehicle_type'] ?? '')) ?>
                                        <?php if (! empty($r['vehicle_no'])): ?>
                                            <span class="text-secondary">· <?= esc($r['vehicle_no']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= ($r['availability'] ?? '') === 'online' ? 'success' : 'secondary' ?>">
                                            <?= esc((string) ($r['availability'] ?? '')) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= ($r['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>">
                                            <?= esc((string) ($r['status'] ?? '')) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header py-2"><strong>Add a rider</strong></div>
            <div class="card-body">
                <p class="small text-secondary">
                    Creates a login for the rider app. They sign in with this phone number.
                </p>
                <form method="post" action="<?= site_url('manufacturer/riders') ?>">
                    <?= csrf_field() ?>
                    <div class="mb-2">
                        <label class="form-label" for="r-name">Name</label>
                        <input class="form-control form-control-sm" id="r-name" name="name" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="r-phone">Phone</label>
                        <input class="form-control form-control-sm" id="r-phone" name="phone" required
                               pattern="\+?[0-9]{10,15}" title="10 to 15 digits">
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="r-pass">Password</label>
                        <input class="form-control form-control-sm" id="r-pass" name="password" type="password" autocomplete="new-password">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label" for="r-vt">Vehicle</label>
                            <select class="form-select form-select-sm" id="r-vt" name="vehicle_type">
                                <?php foreach (['bike', 'scooter', 'van', 'truck', 'car', 'bicycle', 'foot'] as $v): ?>
                                    <option value="<?= esc($v, 'attr') ?>"><?= esc(ucfirst($v)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="r-vn">Vehicle no.</label>
                            <input class="form-control form-control-sm" id="r-vn" name="vehicle_no">
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">Add rider</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
