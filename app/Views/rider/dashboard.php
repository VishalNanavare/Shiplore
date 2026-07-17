<?= $this->extend('layouts/rider') ?>

<?= $this->section('content') ?>
<?php
$online = ($profile['availability'] ?? 'offline') === 'available';
$today  = $live['today'] ?? ['earnings' => '0', 'delivered' => 0, 'cash' => '0', 'hours' => 0];
?>
<?php if ($m = session('success')): ?><div class="alert alert-success py-2 small"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger py-2 small"><?= esc($m) ?></div><?php endif; ?>

<!-- Duty toggle -->
<div class="rd-card p-3 mb-3">
    <div class="rd-avail">
        <div>
            <div class="fw-semibold"><span class="rd-dot <?= $online ? 'on' : 'off' ?> me-1" id="rdOnlineDot"></span><span id="rdOnlineText"><?= $online ? 'You are online' : 'You are offline' ?></span></div>
            <div class="text-secondary small"><?= esc(ucfirst((string) ($profile['vehicle_type'] ?? 'bike'))) ?> · <?= esc($profile['vehicle_no'] ?? '—') ?></div>
        </div>
        <form method="post" action='<?= site_url('rider/availability') ?>'>
            <?= csrf_field() ?>
            <input type="hidden" name="availability" value="<?= $online ? 'offline' : 'available' ?>">
            <button class="btn btn-sm <?= $online ? 'btn-outline-secondary' : 'btn-rd' ?>"><?= $online ? 'Go offline' : 'Go online' ?></button>
        </form>
    </div>
</div>

<!-- Today's stats -->
<div class=\"rd-card p-2 mb-3\">
    <div class=\"row g-0 text-center\">
        <div class=\"col-3 rd-stat border-end\"><div class=\"n\" id=\"rdStatEarn\">₹<?= esc($today['earnings']) ?></div><div class="l">Earned</div></div>
        <div class="col-3 rd-stat border-end"><div class="n" id="rdStatDel"><?= (int) $today['delivered'] ?></div><div class="l">Trips</div></div>
        <div class="col-3 rd-stat border-end"><div class="n" id="rdStatCash">₹<?= esc($today['cash']) ?></div><div class="l">Cash</div></div>
        <div class="col-3 rd-stat"><div class="n" id="rdStatHours"><?= esc($today['hours']) ?>h</div><div class="l">On duty</div></div>
    </div>
</div>
<div class="d-flex gap-2 mb-3">
    <a href='<?= site_url('rider/earnings') ?>' class="btn btn-sm btn-light flex-grow-1"><i class="bi bi-wallet2 me-1"></i>Earnings</a>
    <a href='<?= site_url('rider/performance') ?>' class="btn btn-sm btn-light flex-grow-1"><i class="bi bi-graph-up-arrow me-1"></i>Performance</a>
</div>

<!-- Live offers (populated by rider-live.js) -->
<div id="rdOffersWrap" style="display:none">
    <h2 class="h6 mb-2"><i class="bi bi-broadcast me-1 text-danger"></i>New order<span id="rdOffersS"></span></h2>
    <div id="rdOffers" data-poll='<?= site_url('rider/poll') ?>' data-base='<?= site_url('rider') ?>'></div>
</div>

<!-- Active trips -->
<div class="d-flex justify-content-between align-items-center mt-3 mb-2">
    <h2 class="h6 mb-0"><i class="bi bi-truck me-1"></i>Active trips <span class="badge bg-secondary" id="rdActiveBadge" style="<?= ($live['active_count'] ?? 0) > 0 ? '' : 'display:none' ?>"><?= (int) ($live['active_count'] ?? 0) ?></span></h2>
    <a href='<?= site_url('rider/deliveries') ?>' class="small text-decoration-none">All trips</a>
</div>
<?php foreach ($active as $t): ?>
    <?= view('rider/_trip_card', ['t' => $t, 'offer' => false]) ?>
<?php endforeach; ?>
<div id="rdNoTrips" class="text-center text-secondary py-5" style="<?= empty($active) ? '' : 'display:none' ?>">
    <i class="bi bi-cup-hot display-6 d-block mb-2"></i><?= $online ? "Waiting for orders — you'll be alerted instantly." : 'Go online to start receiving orders.' ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('js/rider-live.js') ?>"></script>
<?= $this->endSection() ?>
