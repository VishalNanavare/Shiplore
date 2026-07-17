<?= $this->extend('layouts/rider') ?>

<?= $this->section('content') ?>
<?php $e = $earn ?? []; ?>
<h1 class="h5 mb-3"><i class="bi bi-wallet2 me-1"></i>Earnings</h1>

<div class="rd-card p-3 mb-3">
    <div class="row g-0 text-center">
        <div class="col-4 rd-stat border-end"><div class="n">₹<?= esc($e['today'] ?? '0.00') ?></div><div class="l">Today</div></div>
        <div class="col-4 rd-stat border-end"><div class="n">₹<?= esc($e['week'] ?? '0.00') ?></div><div class="l">This week</div></div>
        <div class="col-4 rd-stat"><div class="n">₹<?= esc($e['total'] ?? '0.00') ?></div><div class="l">All time</div></div>
    </div>
</div>

<div class="rd-card p-3 mb-3 d-flex justify-content-between align-items-center">
    <div>
        <div class="text-secondary small">Cash in hand</div>
        <div class="h5 mb-0">₹<?= esc($e['cash_in_hand'] ?? '0.00') ?></div>
    </div>
    <div class="text-secondary small text-end" style="max-width:55%">COD you've collected — deposit it to the store to clear your balance.</div>
</div>

<h2 class="h6 mb-2">Recent payouts</h2>
<?php $entries = $e['entries'] ?? []; ?>
<?php if (empty($entries)): ?>
    <div class="text-center text-secondary py-4"><i class="bi bi-receipt display-6 d-block mb-2"></i>No earnings yet — complete trips to start earning.</div>
<?php else: ?>
    <div class="rd-card">
        <?php $sb = ['accrued' => 'warning', 'batched' => 'info', 'paid' => 'success', 'reversed' => 'danger']; ?>
        <?php foreach ($entries as $x): ?>
            <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                <div class="min-w-0">
                    <div class="fw-medium small"><?= esc($x['order_no'] ?? 'Trip') ?></div>
                    <div class="text-secondary" style="font-size:.72rem"><?= esc(substr((string) ($x['created_at'] ?? ''), 0, 16)) ?> · <?= esc(number_format((float) ($x['distance_km'] ?? 0), 1)) ?> km</div>
                </div>
                <div class="text-end">
                    <div class="fw-semibold">₹<?= esc(number_format((float) ($x['amount'] ?? 0), 2)) ?></div>
                    <span class="badge text-bg-<?= esc($sb[$x['status']] ?? 'secondary', 'attr') ?>"><?= esc($x['status'] ?? '') ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
