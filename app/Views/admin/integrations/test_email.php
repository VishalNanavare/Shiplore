<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="row g-3">
    <div class="col-lg-8"><div class="card"><div class="card-body">
        <h2 class="uk-section-title mb-3"><i class="bi bi-envelope-paper me-1"></i>Send a test email</h2>

        <form method="post" action="<?= site_url('admin/integrations/email/compose') ?>" autocomplete="off">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label class="form-label">To</label>
                <input type="email" name="to" class="form-control" required
                       value="<?= esc(old('to', $to), 'attr') ?>" placeholder="you@example.com">
                <div class="form-text">Use a mailbox you can actually open. Delivery is the only proof.</div>
            </div>

            <div class="mb-3">
                <label class="form-label">Subject</label>
                <input type="text" name="subject" class="form-control" required maxlength="200"
                       value="<?= esc(old('subject', 'Test email from the admin panel'), 'attr') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Message</label>
                <textarea name="message" class="form-control" rows="8" required
                          placeholder="Type the message body to send."><?= esc(old('message', "If you are reading this, outbound email is working.")) ?></textarea>
                <div class="form-text">Plain text. Line breaks are kept; HTML is sent as text, not markup.</div>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Send test email</button>
                <a class="btn btn-outline-secondary" href="<?= site_url('admin/integrations/email') ?>"><i class="bi bi-sliders me-1"></i>Email settings</a>
            </div>
        </form>
    </div></div></div>

    <div class="col-lg-4"><div class="card"><div class="card-body">
        <h2 class="uk-section-title mb-2">What it will use</h2>
        <dl class="row small mb-3">
            <dt class="col-5 text-secondary">Transport</dt><dd class="col-7"><?= esc($transport) ?></dd>
            <dt class="col-5 text-secondary">Host</dt><dd class="col-7"><?= esc(($config['host'] ?? '') ?: '—') ?></dd>
            <dt class="col-5 text-secondary">Port</dt><dd class="col-7"><?= esc(($config['port'] ?? '') ?: '—') ?></dd>
            <dt class="col-5 text-secondary">Encryption</dt><dd class="col-7"><?= esc(($config['encryption'] ?? '') ?: '—') ?></dd>
            <dt class="col-5 text-secondary">From</dt><dd class="col-7"><?= esc(($config['from_email'] ?? '') ?: '—') ?></dd>
        </dl>
        <p class="text-secondary small mb-2">
            <i class="bi bi-info-circle me-1"></i>Change any of these on the
            <a href="<?= site_url('admin/integrations/email') ?>">Email settings</a> page. Port 465 needs
            encryption <b>ssl</b>; port 587 needs <b>tls</b> — a mismatched pair does not error, it hangs
            until the connection times out.
        </p>
        <p class="text-secondary small mb-0">
            <i class="bi bi-shield-check me-1"></i>Every message sent here carries a footer identifying it
            as an admin test and naming the account that sent it.
        </p>
    </div></div></div>
</div>

<?= $this->endSection() ?>
