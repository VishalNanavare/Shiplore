<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<h1 class="h4 mb-3">Notifications</h1>
<div class="card"><div class="card-body">
    <?php foreach ($notifications as $n): ?>
        <div class="d-flex gap-3 py-2 border-bottom">
            <span class="rounded d-grid bg-primary-subtle text-primary flex-shrink-0" style="width:36px;height:36px;place-items:center"><i class="bi bi-<?= ($n['category'] ?? '') === 'promotional' ? 'megaphone' : 'bell' ?>"></i></span>
            <div class="flex-grow-1"><div class="d-flex justify-content-between"><span class="fw-medium small"><?= esc($n['title'] ?? $n['event_code']) ?></span><span class="text-secondary small"><?= esc(substr((string) ($n['created_at'] ?? ''), 0, 16)) ?></span></div><div class="text-secondary small"><?= esc($n['body'] ?? '') ?></div></div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($notifications)): ?><div class="st-empty"><i class="bi bi-bell"></i>No notifications.</div><?php endif; ?>
</div></div>
<?= $this->endSection() ?>
