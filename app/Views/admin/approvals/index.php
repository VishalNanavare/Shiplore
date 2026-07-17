<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<?php
$badge = ['pending_l1' => 'warning', 'pending_l2' => 'info', 'changes_requested' => 'secondary',
          'approved' => 'primary', 'applied' => 'success', 'apply_failed' => 'danger',
          'rejected' => 'danger', 'withdrawn' => 'secondary', 'expired' => 'dark'];
$summary = static function (array $r): string {
    $s = json_encode($r['payload_new'] ?? [], JSON_UNESCAPED_UNICODE);

    return mb_strlen((string) $s) > 90 ? mb_substr((string) $s, 0, 90) . '…' : (string) $s;
};
?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Approval Queue — platform level (L2)</span>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary <?= $status === '' ? 'active' : '' ?>" href="<?= site_url('admin/approvals') ?>">Pending L2</a>
            <?php foreach (['pending_l1', 'applied', 'apply_failed', 'rejected', 'expired'] as $f): ?>
                <a class="btn btn-sm btn-outline-secondary <?= $status === $f ? 'active' : '' ?>" href="<?= site_url('admin/approvals?status=' . $f) ?>"><?= esc(str_replace('_', ' ', $f)) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="table-responsive" data-ajax-region><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Req #</th><th>Vendor</th><th>Change</th><th>Requested by</th><th>Payload</th><th>SLA due</th><th>Status</th><th class="text-end">Decision</th></tr></thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <tr>
                <td class="fw-semibold small"><?= esc($r['request_no']) ?></td>
                <td class="small"><?= esc($r['vendor_name'] ?? '—') ?></td>
                <td><code class="small"><?= esc($r['entity_type'] . '.' . $r['action']) ?></code><?php if ($r['entity_id']): ?> <span class="text-secondary small">#<?= esc($r['entity_id']) ?></span><?php endif; ?></td>
                <td class="small"><?= esc($r['requester_name'] ?? ('user ' . $r['requested_by'])) ?> <span class="text-secondary">(<?= esc($r['requester_role']) ?>)</span></td>
                <td class="small text-secondary" style="max-width:260px"><code class="small"><?= esc($summary($r)) ?></code></td>
                <td class="small text-secondary"><?= esc(substr((string) ($r['sla_due_at'] ?? '—'), 0, 16)) ?></td>
                <td><span class="badge text-bg-<?= esc($badge[$r['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $r['status'])) ?></span></td>
                <td class="text-end" style="min-width:300px">
                    <?php if ($r['status'] === 'pending_l2'): ?>
                        <form method="post" action="<?= site_url('admin/approvals/' . $r['id'] . '/decide') ?>" class="d-flex gap-1 justify-content-end">
                            <?= csrf_field() ?>
                            <input class="form-control form-control-sm" style="max-width:140px" name="reason" placeholder="Reason…">
                            <button class="btn btn-sm btn-success" name="decision" value="approved">Approve</button>
                            <button class="btn btn-sm btn-outline-warning" name="decision" value="changes_requested">Changes</button>
                            <button class="btn btn-sm btn-outline-danger" name="decision" value="rejected">Reject</button>
                        </form>
                    <?php elseif ($r['status'] === 'apply_failed'): ?>
                        <span class="text-danger small"><?= esc($r['apply_error'] ?? 'apply failed') ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($requests)): ?><tr><td colspan="8" class="text-center text-secondary py-4">The queue is clear.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
