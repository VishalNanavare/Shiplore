<?= $this->extend('layouts/rider') ?>

<?= $this->section('content') ?>
<?php
$status = $d['status'];
$offered = $d['assignment_status'] === 'offered';
$cod     = (float) ($d['cod_amount'] ?? 0);
$gmaps   = static fn ($lat, $lng) => $lat && $lng ? 'https://www.google.com/maps/dir/?api=1&destination=' . $lat . ',' . $lng : null;
?>
<?php if ($m = session('success')): ?><div class="alert alert-success py-2 small"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger py-2 small"><?= esc($m) ?></div><?php endif; ?>

<a href="<?= site_url('rider/deliveries') ?>" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>All trips</a>

<div class="rd-card p-3 my-2">
    <div class="d-flex justify-content-between align-items-center">
        <div class="fw-semibold"><?= esc($d['order_no'] ?? ('#' . $d['delivery_id'])) ?></div>
        <span class="rd-badge st-<?= esc($status, 'attr') ?>"><?= esc(str_replace('_', ' ', $status)) ?></span>
    </div>
    <div class="text-secondary small">Fee ₹<?= esc(number_format((float) $d['delivery_fee'], 0)) ?><?php if ($d['eta_at']): ?> · ETA <?= esc(substr((string) $d['eta_at'], 0, 16)) ?><?php endif; ?></div>
</div>

<!-- Pickup -->
<div class="rd-card p-3 mb-2">
    <div class="rd-leg"><span class="rd-pin"><i class="bi bi-shop"></i></span>
        <div class="flex-grow-1"><span class="text-secondary small">Pickup</span><div class="fw-medium"><?= esc($d['pickup']['shop'] ?? '—') ?></div></div>
        <?php if ($u = $gmaps($d['pickup']['lat'] ?? null, $d['pickup']['lng'] ?? null)): ?><a href="<?= esc($u, 'attr') ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-signpost-2"></i></a><?php endif; ?>
    </div>
</div>
<!-- Drop -->
<div class="rd-card p-3 mb-2">
    <div class="rd-leg"><span class="rd-pin"><i class="bi bi-geo-alt-fill"></i></span>
        <div class="flex-grow-1"><span class="text-secondary small">Drop</span>
            <div class="fw-medium"><?= esc($d['drop']['name'] ?? '—') ?></div>
            <div class="small text-secondary"><?= esc($d['drop']['address'] ?? '') ?></div>
        </div>
        <div class="d-flex flex-column gap-1">
            <?php if (! empty($d['drop']['phone'])): ?><a href="tel:<?= esc(preg_replace('/[^0-9+]/', '', (string) $d['drop']['phone']), 'attr') ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-telephone"></i></a><?php endif; ?>
            <?php if ($u = $gmaps($d['drop']['lat'] ?? null, $d['drop']['lng'] ?? null)): ?><a href="<?= esc($u, 'attr') ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-signpost-2"></i></a><?php endif; ?>
        </div>
    </div>
</div>

<!-- Items -->
<?php if (! empty($d['items'])): ?>
<div class="rd-card p-3 mb-2">
    <div class="text-secondary small mb-1">Items</div>
    <?php foreach ($d['items'] as $it): ?>
        <div class="d-flex justify-content-between small"><span><?= esc($it['product_title_snapshot']) ?></span><span class="text-secondary">×<?= esc(rtrim(rtrim((string) $it['qty'], '0'), '.')) ?></span></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- COD -->
<?php if ($cod > 0): ?>
<div class="rd-card p-3 mb-2 border border-warning">
    <div class="d-flex justify-content-between align-items-center">
        <div><span class="rd-badge rd-cod">COD</span> <span class="fw-semibold">Collect ₹<?= esc(number_format($cod, 2)) ?></span></div>
        <?php if (in_array($status, ['out_for_delivery', 'picked_up'], true)): ?>
            <form method="post" action="<?= site_url('rider/deliveries/' . $d['delivery_id'] . '/cod') ?>" data-confirm="Confirm you collected ₹<?= esc(number_format($cod, 0), 'attr') ?> cash?">
                <?= csrf_field() ?><button class="btn btn-sm btn-rd"><i class="bi bi-cash me-1"></i>Cash collected</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Actions by status -->
<div class="rd-card p-3 mb-2">
    <div class="text-secondary small mb-2">Update trip</div>
    <?php if ($offered): ?>
        <form method="post" action="<?= site_url('rider/deliveries/' . $d['delivery_id'] . '/accept') ?>"><?= csrf_field() ?><button class="btn btn-rd w-100"><i class="bi bi-check2 me-1"></i>Accept trip</button></form>
    <?php elseif ($status === 'assigned'): ?>
        <?= view('rider/_status_btn', ['id' => $d['delivery_id'], 'to' => 'picked_up', 'label' => 'Picked up from shop', 'icon' => 'bag-check', 'cls' => 'btn-rd']) ?>
        <form method="post" action="<?= site_url('rider/deliveries/' . $d['delivery_id'] . '/decline') ?>" class="mt-2" data-confirm="Decline this trip? It will be offered to another rider.">
            <?= csrf_field() ?><button class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-x-circle me-1"></i>Can't take this trip</button>
        </form>
    <?php elseif ($status === 'picked_up'): ?>
        <?= view('rider/_status_btn', ['id' => $d['delivery_id'], 'to' => 'out_for_delivery', 'label' => 'Start delivery', 'icon' => 'truck', 'cls' => 'btn-rd']) ?>
    <?php elseif ($status === 'out_for_delivery'): ?>
        <!-- Proof of delivery → marks delivered -->
        <form method="post" action="<?= site_url('rider/deliveries/' . $d['delivery_id'] . '/pod') ?>" class="mb-2">
            <?= csrf_field() ?>
            <label class="form-label small mb-1">Proof of delivery</label>
            <div class="input-group mb-2">
                <select name="pod_type" class="form-select" style="max-width:130px"><option value="otp">OTP</option><option value="photo">Photo ref</option><option value="signature">Signature</option></select>
                <input name="pod_ref" class="form-control" placeholder="OTP / note" required>
            </div>
            <button class="btn btn-success w-100"><i class="bi bi-patch-check me-1"></i>Mark delivered</button>
        </form>
        <button class="btn btn-outline-danger w-100" type="button" data-bs-toggle="collapse" data-bs-target="#failBox"><i class="bi bi-x-circle me-1"></i>Couldn't deliver</button>
        <div class="collapse mt-2" id="failBox">
            <form method="post" action="<?= site_url('rider/deliveries/' . $d['delivery_id'] . '/status') ?>">
                <?= csrf_field() ?><input type="hidden" name="status" value="failed">
                <textarea name="reason" class="form-control mb-2" rows="2" placeholder="Reason (customer not available, wrong address…)" required></textarea>
                <button class="btn btn-danger w-100">Mark failed</button>
            </form>
        </div>
    <?php else: ?>
        <div class="text-center text-secondary small py-2">This trip is <strong><?= esc(str_replace('_', ' ', $status)) ?></strong> — no further action.</div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
