<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php if (session('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session('success')) ?></div>
<?php endif; ?>
<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>

<?php
$badge = [
    'pending_l1' => 'warning', 'pending_l2' => 'warning', 'approved' => 'success',
    'rejected' => 'danger', 'changes_requested' => 'info', 'withdrawn' => 'secondary', 'expired' => 'secondary',
];
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Approvals</h5>
    <form method="get" class="d-flex align-items-center gap-2">
        <label class="form-label mb-0 small text-secondary" for="statusFilter">Status</label>
        <select class="form-select form-select-sm" id="statusFilter" name="status" onchange="this.form.submit()">
            <option value="">Pending</option>
            <?php foreach (array_keys($badge) as $s): ?>
                <option value="<?= esc($s, 'attr') ?>" <?= ($status ?? '') === $s ? 'selected' : '' ?>>
                    <?= esc(ucwords(str_replace('_', ' ', $s))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if (empty($requests)): ?>
    <div class="card"><div class="card-body text-center text-secondary py-5">
        <i class="bi bi-check2-circle display-6 d-block mb-2"></i>
        Nothing awaiting your decision.
    </div></div>
<?php else: ?>
    <?php foreach ($requests as $r): ?>
        <?php $st = (string) ($r['status'] ?? ''); ?>
        <div class="card mb-3">
            <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <strong><?= esc(ucwords(str_replace('_', ' ', (string) ($r['entity_type'] ?? '')))) ?></strong>
                    <span class="text-secondary">·</span>
                    <?= esc(str_replace('_', ' ', (string) ($r['action'] ?? ''))) ?>
                    <?php if (! empty($r['entity_id'])): ?>
                        <span class="text-secondary small">#<?= (int) $r['entity_id'] ?></span>
                    <?php endif; ?>
                </div>
                <span class="badge bg-<?= esc($badge[$st] ?? 'secondary', 'attr') ?>">
                    <?= esc(str_replace('_', ' ', $st)) ?>
                </span>
            </div>
            <div class="card-body">
                <div class="small text-secondary mb-2">
                    Requested by <strong><?= esc((string) ($r['requester_name'] ?? 'Unknown')) ?></strong>
                    (<?= esc((string) ($r['requester_role'] ?? '')) ?>)
                    on <?= esc(substr((string) ($r['created_at'] ?? ''), 0, 16)) ?>
                    <?php if (! empty($r['sla_due_at'])): ?>
                        · due <?= esc(substr((string) $r['sla_due_at'], 0, 16)) ?>
                    <?php endif; ?>
                </div>

                <?php if (! empty($r['payload_new'])): ?>
                    <details class="mb-3">
                        <summary class="small">What changes</summary>
                        <pre class="small bg-light border rounded p-2 mb-0" style="max-height:220px;overflow:auto"><?= esc(json_encode($r['payload_new'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                    </details>
                <?php endif; ?>

                <?php if (in_array($st, ['pending_l1', 'pending_l2'], true)): ?>
                    <form method="post" action="<?= site_url('manufacturer/approvals/' . (int) $r['id'] . '/decide') ?>" class="row g-2 align-items-end">
                        <?= csrf_field() ?>
                        <div class="col-md-7">
                            <label class="form-label small" for="reason-<?= (int) $r['id'] ?>">Reason (required to reject or ask for changes)</label>
                            <input class="form-control form-control-sm" id="reason-<?= (int) $r['id'] ?>" name="reason">
                        </div>
                        <div class="col-md-5 d-flex gap-2">
                            <button class="btn btn-sm btn-success" name="decision" value="approved" type="submit">Approve</button>
                            <button class="btn btn-sm btn-outline-secondary" name="decision" value="changes_requested" type="submit">Ask for changes</button>
                            <button class="btn btn-sm btn-outline-danger" name="decision" value="rejected" type="submit">Reject</button>
                        </div>
                    </form>
                <?php elseif (! empty($r['reason'])): ?>
                    <div class="small text-secondary">Reason: <?= esc((string) $r['reason']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?= $this->endSection() ?>
