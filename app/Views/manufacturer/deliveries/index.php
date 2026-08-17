<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php if (session('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session('success')) ?></div>
<?php endif; ?>
<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>

<?php
$badge = [
    'pending' => 'secondary', 'assigned' => 'info', 'picked_up' => 'primary',
    'in_transit' => 'primary', 'delivered' => 'success', 'failed' => 'danger', 'returned' => 'warning',
];
// What each state may become. Mirrors ManufacturerDeliveryRepository::transition()'s
// map — the server is the authority; this only decides which buttons to draw.
$next = [
    'assigned'   => ['picked_up' => 'Picked up', 'failed' => 'Failed'],
    'picked_up'  => ['in_transit' => 'In transit', 'delivered' => 'Delivered', 'failed' => 'Failed'],
    'in_transit' => ['delivered' => 'Delivered', 'failed' => 'Failed'],
    'failed'     => ['returned' => 'Returned'],
];
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Deliveries</h5>
    <form method="get" class="d-flex align-items-center gap-2">
        <label class="form-label mb-0 small text-secondary" for="statusFilter">Status</label>
        <select class="form-select form-select-sm" id="statusFilter" name="status" onchange="this.form.submit()">
            <option value="">All</option>
            <?php foreach (array_keys($badge) as $s): ?>
                <option value="<?= esc($s, 'attr') ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>>
                    <?= esc(ucwords(str_replace('_', ' ', $s))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle" id="deliveriesTable">
            <thead>
                <tr><th>PO</th><th>Buyer</th><th>Unit</th><th>Rider</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
                <?php if (empty($deliveries)): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-4">
                        Nothing to deliver. A delivery opens automatically when you dispatch a purchase order.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($deliveries as $d): ?>
                        <?php $st = (string) ($d['status'] ?? ''); $did = (int) $d['id']; ?>
                        <tr>
                            <td class="small">
                                <a href="<?= site_url('manufacturer/purchase-orders/' . (int) $d['po_id']) ?>"><?= esc((string) ($d['po_no'] ?? '')) ?></a>
                            </td>
                            <td class="small"><?= esc((string) ($d['buyer_name'] ?? '—')) ?></td>
                            <td class="small text-secondary"><?= esc((string) ($d['unit_name'] ?? '—')) ?></td>
                            <td class="small">
                                <?php if (! empty($d['rider_name'])): ?>
                                    <?= esc($d['rider_name']) ?>
                                    <div class="text-secondary"><?= esc((string) ($d['rider_phone'] ?? '')) ?></div>
                                <?php else: ?>
                                    <span class="text-secondary">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-<?= esc($badge[$st] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $st)) ?></span></td>
                            <td class="text-end">
                                <?php if (in_array($st, ['pending', 'assigned'], true)): ?>
                                    <form method="post" action="<?= site_url('manufacturer/deliveries/' . $did . '/assign') ?>" class="d-inline-flex gap-1 align-items-center">
                                        <?= csrf_field() ?>
                                        <select name="rider_user_id" class="form-select form-select-sm" style="width:auto" required aria-label="Rider">
                                            <option value="">Assign rider…</option>
                                            <?php foreach ($riders as $r): ?>
                                                <option value="<?= (int) $r['user_id'] ?>" <?= (int) ($d['rider_user_id'] ?? 0) === (int) $r['user_id'] ? 'selected' : '' ?>>
                                                    <?= esc((string) ($r['name'] ?? '')) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-sm btn-outline-primary" type="submit">Go</button>
                                    </form>
                                <?php endif; ?>

                                <?php foreach (($next[$st] ?? []) as $to => $label): ?>
                                    <form method="post" action="<?= site_url('manufacturer/deliveries/' . $did . '/' . $to) ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <?php if ($to === 'failed'): ?>
                                            <input type="hidden" name="reason" value="Not delivered">
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-secondary" type="submit"><?= esc($label) ?></button>
                                    </form>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
