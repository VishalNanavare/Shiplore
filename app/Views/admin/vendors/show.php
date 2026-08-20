<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$vstatus = (string) ($vendor['status'] ?? '');
$vBadge  = ['active' => 'success', 'approved' => 'success', 'submitted' => 'warning', 'under_review' => 'warning', 'draft' => 'secondary', 'suspended' => 'danger', 'terminated' => 'dark'][$vstatus] ?? 'secondary';
$gBadge  = ['verified' => 'success', 'pending' => 'warning', 'failed' => 'danger', 'unverified' => 'secondary'][(string) ($vendor['gstin_status'] ?? 'unverified')] ?? 'secondary';
$stateNm = \App\Libraries\Geo\IndiaStates::list()[(string) ($vendor['state_code'] ?? '')] ?? ($vendor['state_code'] ?? '—');
$money   = static fn ($n): string => '₹' . number_format((float) $n, 2);
$tile    = static function (string $icon, string $label, string $value, string $tone = 'primary'): string {
    return '<div class="col"><div class="card h-100"><div class="card-body py-3">'
        . '<div class="d-flex align-items-center gap-2 text-' . $tone . '"><i class="bi ' . $icon . ' fs-5"></i>'
        . '<span class="text-secondary small text-uppercase" style="letter-spacing:.03em">' . esc($label) . '</span></div>'
        . '<div class="h4 mb-0 mt-1 fw-semibold">' . $value . '</div></div></div></div>';
};
$m = $metrics ?? [];
?>
<?php if ($f = session('success')): ?><div class="alert alert-success"><?= esc($f) ?></div><?php endif; ?>
<?php if ($f = session('error')): ?><div class="alert alert-danger"><?= esc($f) ?></div><?php endif; ?>
<?php if ($f = session('warning')): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i><?= esc($f) ?></div><?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <a href="<?= site_url('admin/vendors') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back to vendors</a>
    <div class="d-flex flex-wrap gap-2">
        <form method="post" action="<?= site_url('admin/portal/enter/vendor/' . $vendor['id']) ?>" class="m-0"
              onsubmit="return confirm('Open the vendor portal as <?= esc($vendor['display_name'] ?? 'this vendor', 'attr') ?>? You will be signed in as them until you return to admin.');">
            <?= csrf_field() ?>
            <button class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-up-right me-1"></i>Go to Vendor Portal</button>
        </form>
        <a href="<?= site_url('admin/vendors/' . $vendor['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
        <a href="<?= site_url('admin/vendors/' . $vendor['id'] . '/documents') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-text me-1"></i>KYC</a>
        <a href="<?= site_url('admin/vendors/' . $vendor['id'] . '/statement') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-receipt me-1"></i>Statement</a>
        <?php if (in_array($vstatus, ['submitted', 'under_review'], true)): ?>
            <form method="post" action="<?= site_url('admin/vendors/' . $vendor['id'] . '/approve') ?>" class="m-0"><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-check2 me-1"></i>Approve</button></form>
            <form method="post" action="<?= site_url('admin/vendors/' . $vendor['id'] . '/reject') ?>" class="m-0"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg me-1"></i>Reject</button></form>
        <?php endif; ?>
        <?php // No button at all for 'terminated' — the controller refuses both directions
              // for it server-side; a button that POSTs to a guaranteed no-op is worse than
              // none. Shown for every other status, including draft/submitted/under_review —
              // an admin can activate straight from onboarding without waiting on approve first. ?>
        <?php if ($vstatus !== 'terminated'): ?>
            <?php if ($vstatus === 'active'): ?>
                <form method="post" action="<?= site_url('admin/vendors/' . $vendor['id'] . '/deactivate') ?>" class="m-0"
                      onsubmit="return confirm('Deactivate <?= esc($vendor['display_name'] ?? 'this vendor', 'attr') ?>? Once enforcement is live this will take all their shops offline and lock out their staff and riders.');">
                    <?= csrf_field() ?><button class="btn btn-sm btn-outline-warning"><i class="bi bi-pause-circle me-1"></i>Deactivate</button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= site_url('admin/vendors/' . $vendor['id'] . '/activate') ?>" class="m-0">
                    <?= csrf_field() ?><button class="btn btn-sm btn-outline-success"><i class="bi bi-play-circle me-1"></i>Activate</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Header -->
<div class="card mb-3"><div class="card-body d-flex flex-wrap align-items-center gap-3">
    <?php if (! empty($vendor['logo_uuid'])): ?>
        <img src="<?= site_url('media/' . $vendor['logo_uuid']) ?>" alt="" class="rounded border" style="width:64px;height:64px;object-fit:cover">
    <?php else: ?>
        <span class="rounded bg-primary-subtle text-primary d-grid" style="width:64px;height:64px;place-items:center;font-weight:700;font-size:1.4rem"><?= esc(strtoupper(substr((string) ($vendor['display_name'] ?? 'V'), 0, 2))) ?></span>
    <?php endif; ?>
    <div class="me-auto">
        <h1 class="h4 mb-1"><?= esc($vendor['display_name'] ?? '—') ?></h1>
        <div class="text-secondary small"><?= esc($vendor['legal_name'] ?? '') ?> · #<?= (int) $vendor['id'] ?></div>
    </div>
    <div class="text-end">
        <span class="badge text-bg-<?= $vBadge ?> text-capitalize mb-1"><?= esc(str_replace('_', ' ', $vstatus)) ?></span><br>
        <span class="badge text-bg-<?= $gBadge ?>"><i class="bi bi-patch-check me-1"></i>GST <?= esc($vendor['gstin_status'] ?? 'unverified') ?></span>
    </div>
</div></div>

<!-- KPI tiles -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-1">
    <?= $tile('bi-shop', 'Shops', (string) ($m['shops'] ?? 0), 'primary') ?>
    <?= $tile('bi-box-seam', 'Products', (string) ($m['products'] ?? 0), 'info') ?>
    <?= $tile('bi-bag-check', 'Open orders', (string) ($m['open_orders'] ?? 0), 'warning') ?>
    <?= $tile('bi-currency-rupee', 'Lifetime sales', $money($m['sales'] ?? 0), 'success') ?>
</div>

<div class="row g-3 mt-0">
    <!-- Left column -->
    <div class="col-lg-5">
        <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-building me-1"></i>Business</div><div class="card-body">
            <dl class="row mb-0 small">
                <dt class="col-5 text-secondary">Legal name</dt><dd class="col-7"><?= esc($vendor['legal_name'] ?? '—') ?></dd>
                <dt class="col-5 text-secondary">Business type</dt>
                <dd class="col-7">
                    <?php if (! empty($vendor['business_type'])): ?>
                        <?= esc($vendor['business_type']) ?>
                    <?php else: ?>
                        <span class="badge text-bg-warning text-dark">Not assigned</span>
                        <a href="<?= site_url('admin/vendors/' . $vendor['id'] . '/edit') ?>" class="ms-1 small">Assign</a>
                    <?php endif; ?>
                </dd>
                <?php if (! empty($allowedCategories)): ?>
                <dt class="col-5 text-secondary align-self-start">Allowed categories</dt>
                <dd class="col-7">
                    <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($allowedCategories as $ac): ?>
                        <span class="badge text-bg-light border text-dark"><?= esc($ac['name']) ?></span>
                    <?php endforeach; ?>
                    </div>
                </dd>
                <?php elseif (empty($vendor['business_type_id'])): ?>
                <dt class="col-5 text-secondary">Allowed categories</dt>
                <dd class="col-7 text-warning-emphasis small"><i class="bi bi-exclamation-triangle me-1"></i>All categories (no type set)</dd>
                <?php endif; ?>
                <dt class="col-5 text-secondary">GSTIN</dt><dd class="col-7"><?= esc($vendor['gstin'] ?: '—') ?></dd>
                <dt class="col-5 text-secondary">PAN</dt><dd class="col-7"><?= esc($vendor['pan'] ?: '—') ?></dd>
                <dt class="col-5 text-secondary">State</dt><dd class="col-7"><?= esc($stateNm) ?></dd>
                <dt class="col-5 text-secondary">Composition</dt><dd class="col-7"><?= ! empty($vendor['is_composition']) ? 'Yes' : 'No' ?></dd>
                <dt class="col-5 text-secondary">Registered</dt><dd class="col-7"><?= esc(substr((string) ($vendor['created_at'] ?? ''), 0, 10) ?: '—') ?></dd>
            </dl>
        </div></div>

        <div class="card"><div class="card-header fw-semibold"><i class="bi bi-person-badge me-1"></i>Owner &amp; login</div><div class="card-body">
            <dl class="row mb-0 small">
                <dt class="col-5 text-secondary">Owner</dt><dd class="col-7"><?= esc($vendor['owner_name'] ?? '—') ?></dd>
                <dt class="col-5 text-secondary">Email</dt><dd class="col-7 text-truncate"><?= esc($vendor['owner_email'] ?? '—') ?></dd>
                <dt class="col-5 text-secondary">Phone</dt><dd class="col-7"><?= esc($vendor['owner_phone'] ?? '—') ?></dd>
                <dt class="col-5 text-secondary">Account</dt><dd class="col-7"><span class="badge text-bg-<?= ($vendor['owner_status'] ?? '') === 'active' ? 'success' : 'secondary' ?>"><?= esc($vendor['owner_status'] ?? '—') ?></span></dd>
                <dt class="col-5 text-secondary">Last login</dt><dd class="col-7"><?= esc(($vendor['last_login_at'] ?? null) ? substr((string) $vendor['last_login_at'], 0, 16) : 'Never') ?></dd>
            </dl>
        </div></div>
    </div>

    <!-- Right column -->
    <div class="col-lg-7">
        <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-cash-coin me-1"></i>Financial</div><div class="card-body">
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="text-secondary small text-uppercase mb-1">Last settlement</div>
                    <?php if ($settlement): ?>
                        <div class="fw-semibold"><?= $money($settlement['net_payable'] ?? 0) ?> <span class="badge text-bg-<?= ($settlement['status'] ?? '') === 'paid' ? 'success' : 'secondary' ?> ms-1"><?= esc($settlement['status'] ?? '') ?></span></div>
                        <div class="text-secondary small"><?= esc(substr((string) ($settlement['period_start'] ?? ''), 0, 10)) ?> → <?= esc(substr((string) ($settlement['period_end'] ?? ''), 0, 10)) ?></div>
                        <div class="text-secondary small">Gross <?= $money($settlement['gross'] ?? 0) ?> · Comm <?= $money($settlement['commission_total'] ?? 0) ?></div>
                    <?php else: ?><div class="text-secondary">No settlements yet.</div><?php endif; ?>
                </div>
                <div class="col-sm-6">
                    <div class="text-secondary small text-uppercase mb-1">GST collected</div>
                    <div class="fw-semibold"><?= $money(($gst['cgst'] ?? 0) + ($gst['sgst'] ?? 0) + ($gst['igst'] ?? 0) + ($gst['cess'] ?? 0)) ?></div>
                    <div class="text-secondary small">Taxable <?= $money($gst['taxable'] ?? 0) ?> · <?= (int) ($gst['orders'] ?? 0) ?> orders</div>
                </div>
                <div class="col-sm-6">
                    <div class="text-secondary small text-uppercase mb-1">Payout</div>
                    <div class="fw-semibold text-capitalize"><?= esc($vendor['payout_cycle'] ?? '—') ?></div>
                </div>
                <div class="col-sm-6">
                    <div class="text-secondary small text-uppercase mb-1">Bank account</div>
                    <?php if ($bank): ?>
                        <div class="fw-semibold">••<?= esc($bank['account_last4'] ?? '') ?> <span class="badge text-bg-<?= ($bank['status'] ?? '') === 'verified' ? 'success' : 'secondary' ?> ms-1"><?= esc($bank['status'] ?? '') ?></span></div>
                        <div class="text-secondary small"><?= esc($bank['holder_name'] ?? '') ?> · <?= esc($bank['ifsc'] ?? '') ?></div>
                    <?php else: ?><div class="text-secondary">Not added</div><?php endif; ?>
                </div>
            </div>
        </div></div>

        <div class="card mb-3"><div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-shop me-1"></i>Shops <span class="text-secondary small fw-normal"><?= count($shops) ?></span></span>
                <a href="<?= site_url('admin/shops') ?>?vendor_id=<?= (int) $vendor['id'] ?>" class="btn btn-sm btn-outline-secondary">View all shops</a>
            </div>
            <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Shop</th><th>Code</th><th>Pincode</th><th>Radius</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($shops as $s): ?>
                    <tr><td class="fw-medium"><a href="<?= site_url('admin/shops/' . $s['id']) ?>" class="text-reset text-decoration-none"><?= esc($s['name']) ?></a></td>
                        <td class="small text-secondary"><?= esc($s['code'] ?? '') ?></td>
                        <td class="small"><?= esc($s['pincode'] ?? '') ?></td>
                        <td class="small"><?= $s['delivery_radius_km'] !== null ? esc($s['delivery_radius_km']) . ' km' : '—' ?></td>
                        <td><span class="badge text-bg-<?= ($s['status'] ?? '') === 'active' || ($s['status'] ?? '') === 'open' ? 'success' : 'secondary' ?>"><?= esc($s['status'] ?? '') ?></span></td>
                        <td class="text-end">
                            <form method="post" action="<?= site_url('admin/portal/enter/shop/' . $s['id']) ?>" class="d-inline"
                                  onsubmit="return confirm('Open the shop portal for <?= esc($s['name'], 'attr') ?>? You will be signed in as its vendor until you return to admin.');">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-primary" title="Go to Shop Portal"><i class="bi bi-box-arrow-up-right"></i></button>
                            </form>
                        </td></tr>
                <?php endforeach; ?>
                <?php if (empty($shops)): ?><tr><td colspan="6" class="text-center text-secondary py-3">No shops.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>

        <div class="row g-3">
            <div class="col-md-7"><div class="card h-100"><div class="card-header fw-semibold"><i class="bi bi-bag me-1"></i>Recent orders</div>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <tbody>
                    <?php foreach ($recentOrders as $o): ?>
                        <tr><td class="small fw-medium"><?= esc($o['order_no'] ?? $o['sub_order_no'] ?? '') ?></td>
                            <td class="small text-secondary text-truncate" style="max-width:120px"><?= esc($o['customer'] ?? '') ?></td>
                            <td class="small text-end"><?= $money($o['grand_total'] ?? 0) ?></td>
                            <td><span class="badge text-bg-secondary text-capitalize"><?= esc(str_replace('_', ' ', $o['status'] ?? '')) ?></span></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentOrders)): ?><tr><td class="text-center text-secondary py-3">No orders yet.</td></tr><?php endif; ?>
                    </tbody>
                </table></div>
            </div></div>
            <div class="col-md-5"><div class="card h-100"><div class="card-header fw-semibold"><i class="bi bi-file-earmark-check me-1"></i>KYC documents</div>
                <ul class="list-group list-group-flush small">
                <?php foreach ($docs as $d): $exp = ! empty($d['expires_at']) && $d['expires_at'] < date('Y-m-d'); ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-capitalize"><?= esc($d['doc_type']) ?><?php if ($exp): ?> <span class="badge text-bg-danger ms-1">expired</span><?php endif; ?></span>
                        <span class="badge text-bg-<?= ['verified' => 'success', 'rejected' => 'danger', 'uploaded' => 'secondary'][$d['status']] ?? 'secondary' ?>"><?= esc($d['status']) ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($docs)): ?><li class="list-group-item text-center text-secondary py-3">No documents.</li><?php endif; ?>
                </ul>
            </div></div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
