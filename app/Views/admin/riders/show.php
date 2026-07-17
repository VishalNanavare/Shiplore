<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$sb = ['active' => 'success', 'suspended' => 'warning', 'terminated' => 'dark'];
$db = ['uploaded' => 'warning', 'verified' => 'success', 'rejected' => 'danger'];
$eb = ['accrued' => 'info', 'paid' => 'success', 'reversed' => 'secondary'];
$active = ($rider['status'] ?? '') === 'active';
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="mb-3 d-flex justify-content-between align-items-center">
    <div class="d-flex gap-2">
        <a href="<?= site_url('admin/riders') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back to riders</a>
        <a href="<?= site_url('admin/rider-finance/statement/' . (int) $rider['user_id']) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-text me-1"></i>Statement</a>
    </div>
    <div class="d-flex gap-2">
        <?php if ($active): ?>
        <form method="post" action="<?= site_url('admin/portal/enter/rider/' . $rider['id']) ?>" class="m-0"
              onsubmit="return confirm('Open the rider portal as <?= esc($rider['name'] ?? 'this rider', 'attr') ?>? You will be signed in as them until you return to admin.');">
            <?= csrf_field() ?>
            <button class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-up-right me-1"></i>Go to Rider Portal</button>
        </form>
        <?php endif; ?>
        <form method="post" action="<?= site_url('admin/riders/' . $rider['id'] . '/status') ?>" data-confirm="<?= $active ? 'Suspend' : 'Activate' ?> this rider?" class="m-0">
            <?= csrf_field() ?><input type="hidden" name="status" value="<?= $active ? 'suspended' : 'active' ?>">
            <button class="btn btn-sm <?= $active ? 'btn-outline-warning' : 'btn-success' ?>"><i class="bi bi-<?= $active ? 'pause-circle' : 'play-circle' ?> me-1"></i><?= $active ? 'Suspend' : 'Activate' ?></button>
        </form>
    </div>
</div>

<?php
$m  = $metrics ?? [];
$kpi = static function (string $label, $value, string $icon, string $tone = 'secondary'): string {
    return '<div class="col"><div class="card h-100"><div class="card-body py-2 text-center">'
        . '<div class="text-' . $tone . '"><i class="bi bi-' . $icon . '"></i></div>'
        . '<div class="h5 mb-0">' . $value . '</div>'
        . '<div class="small text-secondary">' . $label . '</div></div></div></div>';
};
?>
<div class="row row-cols-2 row-cols-md-6 g-2 mb-3">
    <?= $kpi('Delivered', (int) ($m['delivered'] ?? 0), 'check2-circle', 'success') ?>
    <?= $kpi('On-time', $m['on_time_pct'] !== null ? $m['on_time_pct'] . '%' : '—', 'clock-history', 'info') ?>
    <?= $kpi('Acceptance', $m['acceptance_pct'] !== null ? $m['acceptance_pct'] . '%' : '—', 'hand-thumbs-up', 'primary') ?>
    <?= $kpi('Rating', ($m['rating_count'] ?? 0) > 0 ? number_format((float) $m['avg_rating'], 2) . '★' : '—', 'star-fill', 'warning') ?>
    <?= $kpi('Active', (int) ($m['active'] ?? 0), 'truck', 'dark') ?>
    <?= $kpi('Disputes', (int) ($m['open_disputes'] ?? 0), 'flag', ($m['open_disputes'] ?? 0) > 0 ? 'danger' : 'secondary') ?>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3"><div class="card-header fw-semibold"><?= esc($rider['name'] ?? 'Rider') ?></div><div class="card-body">
            <dl class="row mb-0 small">
                <dt class="col-5 text-secondary fw-normal">Status</dt><dd class="col-7"><span class="badge text-bg-<?= esc($sb[$rider['status']] ?? 'secondary', 'attr') ?>"><?= esc($rider['status']) ?></span></dd>
                <dt class="col-5 text-secondary fw-normal">Phone</dt><dd class="col-7"><?= esc($rider['phone'] ?? '—') ?></dd>
                <dt class="col-5 text-secondary fw-normal">Vendor</dt><dd class="col-7"><?= esc($rider['vendor'] ?? '—') ?></dd>
                <dt class="col-5 text-secondary fw-normal">Vehicle</dt><dd class="col-7 text-capitalize"><?= esc($rider['vehicle_type'] ?? '—') ?> · <?= esc($rider['vehicle_no'] ?? '—') ?></dd>
                <dt class="col-5 text-secondary fw-normal">Licence</dt><dd class="col-7"><?= esc($rider['license_no'] ?: '—') ?></dd>
                <dt class="col-5 text-secondary fw-normal">Availability</dt><dd class="col-7 text-capitalize"><?= esc($rider['availability'] ?? '—') ?></dd>
                <dt class="col-5 text-secondary fw-normal">Max active</dt><dd class="col-7"><?= esc($rider['max_active_orders'] ?? '—') ?></dd>
            </dl>
        </div></div>

        <div class="card"><div class="card-header fw-semibold">Payout plan</div><div class="card-body">
            <form method="post" action="<?= site_url('admin/riders/' . $rider['id'] . '/plan') ?>" class="d-flex gap-2">
                <?= csrf_field() ?>
                <select name="payout_plan_id" class="form-select form-select-sm">
                    <option value="">— No plan —</option>
                    <?php foreach ($plans as $p): ?><option value="<?= esc($p['id'], 'attr') ?>" <?= (int) ($rider['payout_plan_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= esc($p['name']) ?> (<?= esc(str_replace('_', ' ', $p['model'])) ?>)</option><?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-primary">Save</button>
            </form>
            <div class="form-text">Current: <strong><?= esc($rider['plan'] ?? 'none') ?></strong> · <a href="<?= site_url('admin/rider-plans') ?>">manage plans</a></div>
        </div></div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-file-earmark-text me-1"></i>KYC documents</div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Type</th><th>Status</th><th>Expires</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="text-uppercase"><?= esc($d['doc_type']) ?></td>
                        <td><span class="badge text-bg-<?= esc($db[$d['status']] ?? 'secondary', 'attr') ?>"><?= esc($d['status']) ?></span><?php if (! empty($d['note'])): ?><div class="text-secondary small"><?= esc($d['note']) ?></div><?php endif; ?></td>
                        <td class="small text-secondary"><?= esc($d['expires_at'] ?: '—') ?></td>
                        <td class="text-end">
                            <?php if (! empty($d['uuid'])): ?><a href="<?= site_url('media/' . $d['uuid']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a><?php endif; ?>
                            <?php if ($d['status'] !== 'verified'): ?><form method="post" action="<?= site_url('admin/riders/' . $rider['id'] . '/documents/' . $d['id'] . '/verify') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-success"><i class="bi bi-check2"></i></button></form><?php endif; ?>
                            <?php if ($d['status'] !== 'rejected'): ?><form method="post" action="<?= site_url('admin/riders/' . $rider['id'] . '/documents/' . $d['id'] . '/reject') ?>" class="d-inline" data-confirm="Reject this document?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button></form><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($docs)): ?><tr><td colspan="4" class="text-center text-secondary py-3">No documents uploaded by this rider yet.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>

        <div class="card"><div class="card-header fw-semibold"><i class="bi bi-cash-coin me-1"></i>Recent payout entries</div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Delivery</th><th>Model</th><th class="text-end">Order ₹</th><th class="text-end">Km</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($entries as $e): ?>
                    <tr>
                        <td>#<?= (int) $e['delivery_id'] ?></td>
                        <td class="small text-capitalize"><?= esc(str_replace('_', ' ', $e['model'])) ?></td>
                        <td class="text-end"><?= esc(number_format((float) $e['order_value'], 0)) ?></td>
                        <td class="text-end"><?= esc(number_format((float) $e['distance_km'], 1)) ?></td>
                        <td class="text-end fw-semibold">₹<?= esc(number_format((float) $e['amount'], 2)) ?></td>
                        <td><span class="badge text-bg-<?= esc($eb[$e['status']] ?? 'secondary', 'attr') ?>"><?= esc($e['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($entries)): ?><tr><td colspan="6" class="text-center text-secondary py-3">No payout entries yet.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>

        <?php $dsb = ['open' => 'danger', 'investigating' => 'warning', 'resolved' => 'success', 'rejected' => 'secondary']; ?>
        <div class="card mt-3"><div class="card-header fw-semibold"><i class="bi bi-flag me-1"></i>Disputes</div>
            <div class="card-body p-0">
            <?php foreach (($disputes ?? []) as $dp): ?>
                <div class="border-bottom p-2 small">
                    <div class="d-flex justify-content-between">
                        <span><strong><?= esc(str_replace('_', ' ', (string) $dp['reason'])) ?></strong> · <?= esc($dp['order_no'] ?? ('#' . $dp['delivery_id'])) ?> <span class="text-secondary">(<?= esc($dp['raised_by_role']) ?>)</span></span>
                        <span class="badge text-bg-<?= esc($dsb[$dp['status']] ?? 'secondary', 'attr') ?>"><?= esc($dp['status']) ?></span>
                    </div>
                    <?php if (! empty($dp['detail'])): ?><div class="text-secondary mt-1"><?= esc($dp['detail']) ?></div><?php endif; ?>
                    <?php if (! empty($dp['resolution'])): ?><div class="text-success mt-1"><i class="bi bi-check2 me-1"></i><?= esc($dp['resolution']) ?></div><?php endif; ?>
                    <?php if (in_array($dp['status'], ['open', 'investigating'], true)): ?>
                        <form method="post" action="<?= site_url('admin/riders/' . $rider['id'] . '/disputes/' . $dp['id'] . '/resolve') ?>" class="d-flex gap-1 mt-2">
                            <?= csrf_field() ?>
                            <input name="resolution" class="form-control form-control-sm" placeholder="Resolution note">
                            <button name="status" value="investigating" class="btn btn-sm btn-outline-warning" title="Mark investigating"><i class="bi bi-search"></i></button>
                            <button name="status" value="resolved" class="btn btn-sm btn-outline-success" title="Resolve"><i class="bi bi-check2"></i></button>
                            <button name="status" value="rejected" class="btn btn-sm btn-outline-secondary" title="Reject"><i class="bi bi-x-lg"></i></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (empty($disputes)): ?><div class="text-center text-secondary py-3 small">No disputes raised against this rider.</div><?php endif; ?>
            </div>
        </div>

        <div class="card mt-3"><div class="card-header fw-semibold"><i class="bi bi-star me-1"></i>Recent ratings</div>
            <div class="card-body p-0">
            <?php foreach (($reviews ?? []) as $rv): ?>
                <div class="border-bottom p-2 small d-flex justify-content-between">
                    <span><span class="text-warning"><?php for ($i = 1; $i <= 5; $i++): ?><i class="bi bi-star<?= $i <= (int) $rv['rating'] ? '-fill' : '' ?>"></i><?php endfor; ?></span>
                        <?php if (! empty($rv['comment'])): ?> <span class="text-secondary">"<?= esc($rv['comment']) ?>"</span><?php endif; ?></span>
                    <span class="text-secondary"><?= esc($rv['order_no'] ?? '') ?></span>
                </div>
            <?php endforeach; ?>
            <?php if (empty($reviews)): ?><div class="text-center text-secondary py-3 small">No customer ratings yet.</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
