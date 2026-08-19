<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="row"><div class="col-lg-9">
    <div class="card"><div class="card-body p-4">
        <div class="alert alert-info small mb-3"><i class="bi bi-info-circle me-1"></i>S3 connection + path prefixes for the <strong>test_s3</strong> storage server. The vendor document upload uses <code>endpoint</code>, <code>bucket</code>, <code>region</code>, <code>client_key</code> and <code>client_secret</code>.</div>
        <div class="alert alert-warning small mb-3"><i class="bi bi-hdd me-1"></i><strong>storage_mode</strong> controls where uploads go:
            <code>local</code> = store on this server and serve same-origin (no S3 — use this when the endpoint isn't a real S3 API, to avoid CORS/"405 Not Allowed" on upload).
            <code>s3</code> = upload straight to the S3 endpoint via presigned URLs (only works against a real S3-compatible host with CORS configured to allow <code>PUT</code> from this site). Use <em>Test connection</em> below before switching to <code>s3</code>.</div>
        <form method="post" action="<?= site_url('admin/integrations/aws/save') ?>">
            <?= csrf_field() ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead class="table-light"><tr><th style="width:240px">Setting</th><th>Value</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        // Secret rows render EMPTY — a type="password" input's value still
                        // sits in the page source. Blank means keep (the controller skips
                        // writing a blank secret). Keep the name test in step with
                        // AwsSettingsController::isSecretName().
                        $secret = str_contains(strtolower((string) $r['name']), 'secret');
                        ?>
                        <tr>
                            <td class="small fw-medium"><?= esc($r['name']) ?><input type="hidden" name="name[]" value="<?= esc($r['name'], 'attr') ?>"></td>
                            <td><input name="value[]" class="form-control form-control-sm" value="<?= $secret ? '' : esc($r['key_value'], 'attr') ?>"<?= $secret ? ' type="password" placeholder="saved — leave blank to keep"' : '' ?>></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="2" class="text-secondary small">No aws_settings rows found (the table may be missing). Add keys below.</td></tr>
                    <?php endif; ?>
                    <?php for ($i = 0; $i < 2; $i++): ?>
                        <tr>
                            <td><input name="name[]" class="form-control form-control-sm" placeholder="new key (e.g. documents)"></td>
                            <td><input name="value[]" class="form-control form-control-sm" placeholder="value"></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save settings</button>
        </form>
        <hr class="my-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="small text-secondary"><i class="bi bi-hdd-network me-1"></i>Verify the saved credentials actually accept uploads (write → read → delete a tiny test object).</div>
            <form method="post" action="<?= site_url('admin/integrations/aws/test') ?>" data-no-ajax><?= csrf_field() ?>
                <button class="btn btn-outline-secondary"><i class="bi bi-plug me-1"></i>Test connection</button>
            </form>
        </div>
    </div></div>
</div></div>
<?= $this->endSection() ?>
