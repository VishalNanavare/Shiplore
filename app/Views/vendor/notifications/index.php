<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php $badge = ['created' => 'secondary', 'queued' => 'info', 'sent' => 'primary', 'read' => 'success', 'failed' => 'danger']; ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Notifications</span><span class="text-secondary small"><?= count($notifications) ?></span></div>
    <div class="card-body">
        <?php if (empty($notifications)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-bell display-6 d-block mb-2"></i>No notifications.</div>
        <?php else: ?>
            <?php foreach ($notifications as $n): ?>
                <div class="d-flex gap-3 align-items-start py-2 border-bottom">
                    <span class="rounded d-grid bg-<?= ($n['category'] ?? '') === 'promotional' ? 'warning' : 'primary' ?>-subtle text-<?= ($n['category'] ?? '') === 'promotional' ? 'warning' : 'primary' ?> flex-shrink-0" style="width:38px;height:38px;place-items:center"><i class="bi bi-<?= ($n['category'] ?? '') === 'promotional' ? 'megaphone' : 'bell' ?>"></i></span>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between"><span class="fw-medium small"><?= esc($n['title'] ?? $n['event_code']) ?></span><span class="text-secondary small"><?= esc(substr((string) ($n['created_at'] ?? ''), 0, 16)) ?></span></div>
                        <div class="text-secondary small"><?= esc($n['body'] ?? '') ?></div>
                    </div>
                    <span class="badge text-bg-<?= esc($badge[$n['status']] ?? 'secondary', 'attr') ?>"><?= esc($n['status']) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
