<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php
$badge = ['pending_l1' => 'warning', 'pending_l2' => 'info', 'changes_requested' => 'secondary',
          'approved' => 'primary', 'applied' => 'success', 'apply_failed' => 'danger',
          'rejected' => 'danger', 'withdrawn' => 'secondary', 'expired' => 'dark'];
?>
<div class="card">
    <div class="card-header fw-semibold">My Change Requests</div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Req #</th><th>Change</th><th>Submitted</th><th>SLA due</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <tr>
                <td class="fw-semibold small"><?= esc($r['request_no']) ?></td>
                <td><code class="small"><?= esc($r['entity_type'] . '.' . $r['action']) ?></code><?php if ($r['entity_id']): ?> <span class="text-secondary small">#<?= esc($r['entity_id']) ?></span><?php endif; ?></td>
                <td class="small text-secondary"><?= esc(substr((string) ($r['created_at'] ?? ''), 0, 16)) ?></td>
                <td class="small text-secondary"><?= esc(substr((string) ($r['sla_due_at'] ?? '—'), 0, 16)) ?></td>
                <td>
                    <span class="badge text-bg-<?= esc($badge[$r['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $r['status'])) ?></span>
                    <?php if ($r['status'] === 'apply_failed'): ?><div class="text-danger small mt-1"><?= esc($r['apply_error'] ?? '') ?></div><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($requests)): ?><tr><td colspan="5" class="text-center text-secondary py-4">You haven't submitted any change requests yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
