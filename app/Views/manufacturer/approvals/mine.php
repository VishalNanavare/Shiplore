<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php
$badge = [
    'pending_l1' => 'warning', 'pending_l2' => 'warning', 'approved' => 'success',
    'rejected' => 'danger', 'changes_requested' => 'info', 'withdrawn' => 'secondary', 'expired' => 'secondary',
];
?>

<h5 class="mb-3">My Requests</h5>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle" id="myRequestsTable">
            <thead><tr><th>What</th><th>Action</th><th>Status</th><th>Reason</th><th>Raised</th></tr></thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="5" class="text-center text-secondary py-4">
                        You haven't raised any change requests.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($requests as $r): ?>
                        <?php $st = (string) ($r['status'] ?? ''); ?>
                        <tr>
                            <td class="small">
                                <?= esc(ucwords(str_replace('_', ' ', (string) ($r['entity_type'] ?? '')))) ?>
                                <?php if (! empty($r['entity_id'])): ?>
                                    <span class="text-secondary">#<?= (int) $r['entity_id'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= esc(str_replace('_', ' ', (string) ($r['action'] ?? ''))) ?></td>
                            <td>
                                <span class="badge bg-<?= esc($badge[$st] ?? 'secondary', 'attr') ?>">
                                    <?= esc(str_replace('_', ' ', $st)) ?>
                                </span>
                            </td>
                            <td class="small text-secondary"><?= esc((string) ($r['reason'] ?? '')) ?></td>
                            <td class="small text-secondary"><?= esc(substr((string) ($r['created_at'] ?? ''), 0, 16)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
