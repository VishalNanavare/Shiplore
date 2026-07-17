<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$statusBadge   = ['open' => 'primary', 'pending' => 'warning', 'resolved' => 'info', 'closed' => 'secondary'];
$priorityBadge = ['low' => 'secondary', 'medium' => 'info', 'high' => 'warning', 'urgent' => 'danger'];
$isClosed      = in_array($ticket['status'], ['resolved', 'closed'], true);
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <a href="<?= site_url('admin/support') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back to tickets</a>
    <div class="d-flex gap-2">
        <?php if (! $isClosed): ?>
            <form method="post" action="<?= site_url('admin/support/' . $ticket['id'] . '/resolve') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-info"><i class="bi bi-check2-circle me-1"></i>Resolve</button></form>
            <form method="post" action="<?= site_url('admin/support/' . $ticket['id'] . '/close') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Close</button></form>
        <?php else: ?>
            <form method="post" action="<?= site_url('admin/support/' . $ticket['id'] . '/reopen') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-clockwise me-1"></i>Reopen</button></form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3"><div class="card-body">
            <h2 class="h5 mb-1"><?= esc($ticket['subject']) ?></h2>
            <div class="text-secondary small mb-3"><?= esc($ticket['ticket_no']) ?> · opened <?= esc(substr((string) ($ticket['created_at'] ?? ''), 0, 16)) ?></div>
            <p class="mb-0"><?= nl2br(esc($ticket['body'] ?? '')) ?></p>
        </div></div>

        <div class="card mb-3"><div class="card-header fw-semibold">Conversation</div><div class="card-body">
            <?php foreach ($messages as $msg): ?>
                <div class="d-flex gap-2 mb-3">
                    <span class="rounded-circle bg-primary-subtle text-primary d-grid flex-shrink-0" style="width:36px;height:36px;place-items:center;font-weight:600;font-size:.72rem"><?= esc(strtoupper(substr((string) ($msg['author'] ?? 'S'), 0, 2))) ?></span>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between"><span class="fw-medium small"><?= esc($msg['author'] ?? 'System') ?> <?php if ($msg['is_internal']): ?><span class="badge bg-warning-subtle text-warning ms-1">internal</span><?php endif; ?></span><span class="text-secondary small"><?= esc(substr((string) $msg['created_at'], 0, 16)) ?></span></div>
                        <div class="small"><?= nl2br(esc($msg['body'])) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($messages)): ?><div class="text-secondary small">No replies yet.</div><?php endif; ?>
        </div></div>

        <?php if (! $isClosed): ?>
        <div class="card"><div class="card-body">
            <form method="post" action="<?= site_url('admin/support/' . $ticket['id'] . '/reply') ?>">
                <?= csrf_field() ?>
                <label class="form-label">Reply to requester</label>
                <textarea name="body" class="form-control mb-2" rows="3" placeholder="Type your response…" required></textarea>
                <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Send reply</button>
            </form>
        </div></div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4"><div class="card"><div class="card-header fw-semibold">Details</div><div class="card-body">
        <dl class="row mb-0 small">
            <dt class="col-5 text-secondary">Status</dt><dd class="col-7"><span class="badge text-bg-<?= esc($statusBadge[$ticket['status']] ?? 'secondary', 'attr') ?>"><?= esc($ticket['status']) ?></span></dd>
            <dt class="col-5 text-secondary">Priority</dt><dd class="col-7"><span class="badge text-bg-<?= esc($priorityBadge[$ticket['priority']] ?? 'secondary', 'attr') ?>"><?= esc($ticket['priority']) ?></span></dd>
            <dt class="col-5 text-secondary">Category</dt><dd class="col-7"><?= esc($ticket['category'] ?? '—') ?></dd>
            <dt class="col-5 text-secondary">Requester</dt><dd class="col-7"><?= esc($ticket['requester'] ?? '—') ?></dd>
            <dt class="col-5 text-secondary">Email</dt><dd class="col-7 small"><?= esc($ticket['requester_email'] ?? '—') ?></dd>
            <dt class="col-5 text-secondary">Type</dt><dd class="col-7 text-capitalize"><?= esc($ticket['requester_type']) ?></dd>
            <?php if (!empty($ticket['vendor'])): ?><dt class="col-5 text-secondary">Vendor</dt><dd class="col-7"><?= esc($ticket['vendor']) ?></dd><?php endif; ?>
        </dl>
    </div></div></div>
</div>
<?= $this->endSection() ?>
