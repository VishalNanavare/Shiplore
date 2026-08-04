<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$statusBadge = [
    'draft' => 'secondary', 'submitted' => 'info', 'under_review' => 'info',
    'approved' => 'primary', 'active' => 'success',
    'suspended' => 'warning', 'terminated' => 'dark', 'rejected' => 'danger',
];
$m = $manufacturer;
?>
<?php if ($msg = session('success')): ?><div class="alert alert-success"><?= esc($msg) ?></div><?php endif; ?>
<?php if ($msg = session('error')): ?><div class="alert alert-danger"><?= esc($msg) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <a href="<?= site_url('admin/manufacturers') ?>" class="small text-decoration-none">&larr; All manufacturers</a>
        <h2 class="h4 mb-1 mt-1"><?= esc($m['display_name']) ?>
            <span class="badge text-bg-<?= esc($statusBadge[$m['status']] ?? 'secondary', 'attr') ?> align-middle"><?= esc(str_replace('_', ' ', $m['status'])) ?></span>
        </h2>
        <div class="text-secondary small"><?= esc($m['legal_name'] ?? '') ?></div>
    </div>
    <div class="d-flex gap-1 flex-wrap">
        <form method="post" action="<?= site_url('admin/portal/enter/manufacturer/' . (int) $m['id']) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-box-arrow-up-right me-1"></i>Go to Manufacturer Portal</button>
        </form>
        <?php if (! in_array($m['status'], ['approved', 'active'], true)): ?>
            <form method="post" action="<?= site_url('admin/manufacturers/' . (int) $m['id'] . '/approve') ?>">
                <?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-check2 me-1"></i>Approve</button>
            </form>
        <?php endif; ?>
        <?php if ($m['status'] !== 'rejected'): ?>
            <form method="post" action="<?= site_url('admin/manufacturers/' . (int) $m['id'] . '/reject') ?>">
                <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x me-1"></i>Reject</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Units', count($units), 'bi-building'],
        ['Open purchase orders', (int) $openOrders, 'bi-receipt'],
        ['B2B orders (settled)', (int) ($gst['orders'] ?? 0), 'bi-bag-check'],
        ['B2B value', '₹' . number_format((float) ($gst['grand'] ?? 0), 2), 'bi-cash-stack'],
    ] as [$label, $value, $icon]): ?>
        <div class="col-6 col-xl-3">
            <div class="card kpi-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="kpi-icon"><i class="bi <?= esc($icon, 'attr') ?>"></i></div>
                    <div>
                        <div class="text-secondary small"><?= esc($label) ?></div>
                        <div class="kpi-value"><?= esc((string) $value) ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header fw-semibold">Business</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-secondary">GSTIN</dt><dd class="col-7"><code><?= esc($m['gstin'] ?? '—') ?></code></dd>
                    <dt class="col-5 text-secondary">GST status</dt><dd class="col-7"><?= esc($m['gstin_status'] ?? '—') ?></dd>
                    <dt class="col-5 text-secondary">State</dt><dd class="col-7"><?= esc($m['state_code'] ?? '—') ?></dd>
                    <dt class="col-5 text-secondary">Registered</dt><dd class="col-7"><?= esc((string) ($m['created_at'] ?? '—')) ?></dd>
                </dl>
            </div>
        </div>
        <div class="card">
            <div class="card-header fw-semibold">Owner login</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-secondary">Email</dt><dd class="col-7"><?= esc($m['owner_email'] ?? '—') ?></dd>
                    <dt class="col-5 text-secondary">Phone</dt><dd class="col-7"><?= esc($m['owner_phone'] ?? '—') ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header fw-semibold">Units <span class="text-secondary small fw-normal">(manufacturing / stock locations)</span></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Unit</th><th>Pincode</th><th>State</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($units as $u): ?>
                            <tr>
                                <td><?= esc($u['name']) ?><div class="text-secondary small"><?= esc((string) ($u['code'] ?? '')) ?></div></td>
                                <td class="small"><?= esc((string) ($u['pincode'] ?? '—')) ?></td>
                                <td class="small"><?= esc((string) ($u['state_code'] ?? '—')) ?></td>
                                <td><span class="badge text-bg-secondary"><?= esc((string) $u['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($units)): ?>
                            <tr><td colspan="4" class="text-center text-secondary py-3">No units yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">Recent purchase orders received</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>PO</th><th>Buyer</th><th class="text-end">Total</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td><a href="<?= site_url('admin/purchase-orders/' . (int) $o['id']) ?>"><?= esc($o['po_no']) ?></a></td>
                                <td class="small"><?= esc((string) ($o['buyer_name'] ?? '')) ?></td>
                                <td class="text-end">₹<?= esc(number_format((float) $o['grand_total'], 2)) ?></td>
                                <td><span class="badge text-bg-secondary"><?= esc(str_replace('_', ' ', (string) $o['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($orders)): ?>
                            <tr><td colspan="4" class="text-center text-secondary py-3">No purchase orders yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
