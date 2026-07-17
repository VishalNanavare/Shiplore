<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$isEdit    = $gateway !== null;
$curCode   = $gateway['provider'] ?? array_key_first($manager->availableProviders());
$curConfig = $gateway['config_arr'] ?? [];
$action    = $isEdit ? site_url('admin/payment-gateways/' . $gateway['id'] . '/update') : site_url('admin/payment-gateways/store');
?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<div class="mb-3"><a href="<?= site_url('admin/payment-gateways') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back</a></div>

<form method="post" action="<?= $action ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-lg-5"><div class="card"><div class="card-body">
            <h2 class="uk-section-title mb-3">Gateway</h2>
            <div class="mb-3"><label class="form-label">Provider</label>
                <?php if ($isEdit): ?>
                    <input type="hidden" name="provider" value="<?= esc($curCode, 'attr') ?>">
                    <input class="form-control" value="<?= esc($manager->availableProviders()[$curCode] ?? $curCode) ?>" readonly>
                <?php else: ?>
                    <select name="provider" id="gwProvider" class="form-select">
                        <?php foreach ($manager->availableProviders() as $code => $label): ?>
                            <option value="<?= esc($code, 'attr') ?>" <?= $code === $curCode ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
            <div class="mb-3"><label class="form-label">Channel</label>
                <select name="channel" class="form-select">
                    <?php foreach (['online' => 'Online', 'pos' => 'POS', 'payout' => 'Payout'] as $c => $l): ?>
                        <option value="<?= $c ?>" <?= ($gateway['channel'] ?? 'online') === $c ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['sandbox' => 'Sandbox (test)', 'active' => 'Active (live)', 'inactive' => 'Disabled'] as $s => $l): ?>
                            <option value="<?= $s ?>" <?= ($gateway['status'] ?? 'sandbox') === $s ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6"><label class="form-label">Priority</label><input name="priority" type="number" min="0" class="form-control" value="<?= esc((string) ($gateway['priority'] ?? 0), 'attr') ?>"></div>
            </div>
        </div></div></div>

        <div class="col-lg-7"><div class="card"><div class="card-body">
            <h2 class="uk-section-title mb-3">Credentials</h2>
            <?php foreach ($manager->availableProviders() as $code => $label): ?>
                <div class="gw-fields" data-provider="<?= esc($code, 'attr') ?>" style="<?= $code === $curCode ? '' : 'display:none' ?>">
                    <?php foreach ($manager->fields($code) as $f): ?>
                        <div class="mb-3">
                            <label class="form-label"><?= esc($f['label']) ?> <?php if ($f['secret']): ?><i class="bi bi-shield-lock text-secondary small" title="secret"></i><?php endif; ?></label>
                            <input type="<?= $f['secret'] ? 'password' : 'text' ?>" class="form-control" name="config[<?= esc($f['key'], 'attr') ?>]" value="<?= $code === $curCode ? esc((string) ($curConfig[$f['key']] ?? ''), 'attr') : '' ?>" autocomplete="off">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <p class="text-secondary small mb-0"><i class="bi bi-info-circle me-1"></i>Secrets are stored in the gateway config. In production, move them to a secrets vault.</p>
        </div></div></div>
    </div>
    <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-save me-1"></i><?= $isEdit ? 'Update' : 'Add' ?> gateway</button></div>
</form>
<?= $this->endSection() ?>

<?php if (! $isEdit): ?>
<?= $this->section('scripts') ?>
<script>
document.getElementById('gwProvider').addEventListener('change', function () {
    var v = this.value;
    document.querySelectorAll('.gw-fields').forEach(function (d) { d.style.display = d.dataset.provider === v ? '' : 'none'; });
});
</script>
<?= $this->endSection() ?>
<?php endif; ?>
