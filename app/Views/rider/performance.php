<?= $this->extend('layouts/rider') ?>

<?= $this->section('content') ?>
<?php
$p      = $perf ?? [];
$rating = (float) ($p['avg_rating'] ?? 0);
$stars  = str_repeat('★', (int) round($rating)) . str_repeat('☆', 5 - (int) round($rating));
?>
<h1 class="h5 mb-3"><i class="bi bi-graph-up-arrow me-1"></i>Performance</h1>

<div class="rd-card p-3 mb-3 text-center">
    <div class="display-6 text-warning" style="letter-spacing:2px"><?= $stars ?></div>
    <div class="fw-semibold"><?= number_format($rating, 1) ?> / 5</div>
    <div class="text-secondary small"><?= (int) ($p['rating_count'] ?? 0) ?> customer rating<?= (int) ($p['rating_count'] ?? 0) === 1 ? '' : 's' ?></div>
</div>

<div class="row g-2 mb-3">
    <?php foreach ([
        ['check2-circle', 'Acceptance', ($p['acceptance_pct'] ?? 0) . '%'],
        ['stopwatch', 'On-time', ($p['on_time_pct'] ?? 0) . '%'],
        ['bag-check', 'Completed', (int) ($p['completed'] ?? 0)],
        ['clock-history', 'Hours (wk)', ($p['week_hours'] ?? 0) . 'h'],
    ] as [$ic, $lbl, $val]): ?>
        <div class="col-6">
            <div class="rd-card p-3 h-100 d-flex align-items-center gap-2">
                <span class="rd-perf-ic"><i class="bi bi-<?= $ic ?>"></i></span>
                <div><div class="fw-bold fs-5 lh-1"><?= esc((string) $val) ?></div><div class="text-secondary small"><?= esc($lbl) ?></div></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ((int) ($p['open_disputes'] ?? 0) > 0): ?>
    <div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>You have <?= (int) $p['open_disputes'] ?> open dispute<?= (int) $p['open_disputes'] === 1 ? '' : 's' ?> under review.</div>
<?php endif; ?>

<div class="rd-card p-3 small text-secondary">
    <i class="bi bi-info-circle me-1"></i>Keep your acceptance and on-time rates high and your rating above 4.5★ to qualify for incentives.
</div>
<?= $this->endSection() ?>
