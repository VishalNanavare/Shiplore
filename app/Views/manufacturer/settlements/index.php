<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php $m = static fn ($v): string => '₹' . number_format((float) $v, 2); ?>

<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>

<h5 class="mb-3">Settlements</h5>

<?php if ($policy->isUnconfigured()): ?>
    <div class="alert alert-info py-2">
        No commission rate or payout cycle has been set for B2B trade yet, so no payout runs
        are being generated. Your earnings are still tracked in full on the
        <a href="<?= site_url('manufacturer/earnings') ?>">Earnings</a> page.
    </div>
<?php endif; ?>

<?php if (! empty($holds)): ?>
    <div class="card mb-3 border-warning">
        <div class="card-header py-2 bg-warning-subtle"><strong>On hold</strong></div>
        <ul class="list-group list-group-flush">
            <?php foreach ($holds as $h): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                    <a href="<?= site_url('manufacturer/settlements/' . (int) $h['id']) ?>">
                        <?= esc((string) $h['period_start']) ?> — <?= esc((string) $h['period_end']) ?>
                    </a>
                    <span class="fw-semibold"><?= esc($m($h['net_payable'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
    <?php if (empty($settlements)): ?>
        <div class="card-body text-secondary">No payout runs yet.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr><th>Period</th><th class="text-end">Gross</th><th class="text-end">Commission</th>
                        <th class="text-end">Net payable</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($settlements as $s): ?>
                        <?php
                        $badge = match ($s['status'] ?? '') {
                            'paid' => 'success', 'held' => 'warning', 'failed' => 'danger', default => 'secondary',
                        };
                        ?>
                        <tr>
                            <td><?= esc((string) $s['period_start']) ?> — <?= esc((string) $s['period_end']) ?></td>
                            <td class="text-end"><?= esc($m($s['gross'])) ?></td>
                            <td class="text-end text-secondary">−<?= esc($m($s['commission_total'])) ?></td>
                            <td class="text-end fw-semibold"><?= esc($m($s['net_payable'])) ?></td>
                            <td><span class="badge bg-<?= esc($badge, 'attr') ?>-subtle text-<?= esc($badge, 'attr') ?>-emphasis"><?= esc((string) $s['status']) ?></span></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('manufacturer/settlements/' . (int) $s['id']) ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
