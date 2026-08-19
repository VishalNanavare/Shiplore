<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$config    = $row['config_arr'] ?? [];
$status    = $row['status'] ?? null;
$statusMap = ['connected' => 'success', 'disconnected' => 'secondary', 'error' => 'danger'];
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8"><div class="card"><div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="rounded d-grid bg-primary-subtle text-primary" style="width:40px;height:40px;place-items:center;font-size:1.2rem"><i class="bi <?= esc($spec['icon']) ?>"></i></span>
            <div><h2 class="h5 mb-0"><?= esc($spec['label']) ?></h2><div class="text-secondary small"><?= esc($spec['blurb']) ?></div></div>
            <?php if ($status): ?><span class="badge text-bg-<?= esc($statusMap[$status] ?? 'secondary', 'attr') ?> ms-auto"><?= esc($status) ?></span><?php endif; ?>
        </div>
        <hr>
        <form method="post" action="<?= site_url('admin/integrations/' . $slug) ?>">
            <?= csrf_field() ?>
            <?php foreach ($spec['fields'] as $f): [$key, $label, $type, $req] = $f; $opts = $f[4] ?? []; $val = (string) ($config[$key] ?? ''); ?>
                <div class="mb-3">
                    <label class="form-label"><?= esc($label) ?> <?php if ($req): ?><span class="text-danger">*</span><?php endif; ?> <?php if (in_array($type, ['password'], true)): ?><i class="bi bi-shield-lock text-secondary small" title="secret"></i><?php endif; ?></label>
                    <?php if ($type === 'textarea'): ?>
                        <textarea name="<?= esc($key, 'attr') ?>" class="form-control" rows="3"><?= esc($val) ?></textarea>
                    <?php elseif ($type === 'select'): ?>
                        <select name="<?= esc($key, 'attr') ?>" class="form-select"><?php foreach ($opts as $o): ?><option value="<?= esc($o, 'attr') ?>" <?= $val === $o ? 'selected' : '' ?>><?= esc(strtoupper($o)) ?></option><?php endforeach; ?></select>
                    <?php else: ?>
                        <input type="<?= $type === 'password' ? 'password' : 'text' ?>" name="<?= esc($key, 'attr') ?>" class="form-control" value="<?= esc($val, 'attr') ?>" autocomplete="off">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save settings</button>
                <button class="btn btn-outline-info" formaction="<?= site_url('admin/integrations/' . $slug . '/test') ?>"><i class="bi bi-plug me-1"></i>Test connection</button>
                <?php if ($slug === 'email'): ?>
                    <?php // A link, not a formaction: this leaves the page, and posting the
                          // unsaved form to it would silently discard whatever was typed here. ?>
                    <a class="btn btn-outline-secondary" href="<?= site_url('admin/integrations/email/compose') ?>"><i class="bi bi-envelope-paper me-1"></i>Send a test email…</a>
                <?php endif; ?>
            </div>
        </form>
    </div></div></div>
    <div class="col-lg-4"><div class="card"><div class="card-body">
        <h2 class="uk-section-title mb-2">About this integration</h2>
        <p class="text-secondary small mb-2"><?= esc($spec['blurb']) ?></p>
        <?php if ($slug === 'email'): ?>
            <p class="text-secondary small mb-2"><i class="bi bi-info-circle me-1"></i>"Test connection" sends a <strong>real email</strong> with fixed content to the "Send test email to" address. Check that inbox <em>and its spam folder</em> — a delivered message is the only proof the transport works. The failure message names the actual reason.</p>
            <p class="text-secondary small mb-0"><i class="bi bi-envelope-paper me-1"></i>Use <a href="<?= site_url('admin/integrations/email/compose') ?>">Send a test email</a> when you need to choose the recipient, subject and message body yourself.</p>
        <?php else: ?>
            <p class="text-secondary small mb-0"><i class="bi bi-info-circle me-1"></i>"Test connection" validates that all required fields are present. Live calls run from the corresponding service at runtime.</p>
        <?php endif; ?>
    </div></div></div>
</div>
<?= $this->endSection() ?>
