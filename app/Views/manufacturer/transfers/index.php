<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>
<?php if (session('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session('success')) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Stock Transfers</h5>
    <form method="get" class="d-flex gap-2">
        <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
            <?php foreach (['' => 'All', 'draft' => 'Draft', 'dispatched' => 'In transit', 'received' => 'Received'] as $k => $label): ?>
                <option value="<?= esc($k, 'attr') ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if (count($units ?? []) < 2): ?>
    <div class="alert alert-info py-2">
        A transfer moves stock between two of your units. Add a second unit or warehouse first.
    </div>
<?php else: ?>
    <div class="card mb-3">
        <div class="card-header py-2"><strong>New transfer</strong></div>
        <div class="card-body">
            <form method="post" action="<?= site_url('manufacturer/transfers') ?>" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <div class="col-md-3">
                    <label class="form-label small" for="fromU">From</label>
                    <select class="form-select form-select-sm" id="fromU" name="from_mshop_id" required>
                        <?php foreach ($units as $uid => $uname): ?>
                            <option value="<?= (int) $uid ?>"><?= esc($uname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="toU">To</label>
                    <select class="form-select form-select-sm" id="toU" name="to_mshop_id" required>
                        <?php foreach ($units as $uid => $uname): ?>
                            <option value="<?= (int) $uid ?>"><?= esc($uname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="vId">Variant ID</label>
                    <input class="form-control form-control-sm" id="vId" name="items[0][variant_id]" type="number" min="1" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="vQty">Quantity</label>
                    <input class="form-control form-control-sm" id="vQty" name="items[0][qty]" type="number" step="0.001" min="0.001" required>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-primary w-100" type="submit">Create draft</button>
                </div>
                <div class="col-12 form-text">
                    Nothing moves until you dispatch it. Find variant IDs on the
                    <a href="<?= site_url('manufacturer/inventory') ?>">stock</a> page.
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <?php if (empty($transfers)): ?>
        <div class="card-body text-secondary">No transfers yet.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th>Transfer</th><th>From</th><th>To</th><th>Status</th><th>Created</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($transfers as $t): ?>
                        <?php
                        $badge = match ($t['status'] ?? '') {
                            'received'   => 'success',
                            'dispatched' => 'warning',
                            'cancelled'  => 'secondary',
                            default      => 'light',
                        };
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= esc((string) ($t['transfer_no'] ?? '')) ?></td>
                            <td><?= esc((string) ($t['from_name'] ?? '')) ?></td>
                            <td><?= esc((string) ($t['to_name'] ?? '')) ?></td>
                            <td>
                                <span class="badge bg-<?= esc($badge, 'attr') ?>-subtle text-<?= esc($badge, 'attr') ?>-emphasis">
                                    <?= esc($t['status'] === 'dispatched' ? 'in transit' : (string) ($t['status'] ?? '')) ?>
                                </span>
                            </td>
                            <td class="text-secondary small"><?= esc(substr((string) ($t['created_at'] ?? ''), 0, 16)) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= site_url('manufacturer/transfers/' . (int) ($t['id'] ?? 0)) ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
