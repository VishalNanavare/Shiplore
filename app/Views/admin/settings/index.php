<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card mb-3"><div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-palette me-1"></i>Branding &amp; Identity</h2>
    <form method="post" action="<?= site_url('admin/settings/system') ?>" enctype="multipart/form-data" style="max-width:580px">
        <?= csrf_field() ?>

        <!-- Platform name -->
        <div class="mb-4">
            <label class="form-label small fw-semibold">Platform name</label>
            <input name="brand_name" value="<?= esc($brandName ?? 'Shiplore', 'attr') ?>" maxlength="60"
                   class="form-control" style="max-width:300px" placeholder="Shiplore">
            <div class="form-text">Used in browser tabs, panel headers, invoices and emails.</div>
        </div>

        <!-- Logo upload -->
        <div class="mb-4">
            <label class="form-label small fw-semibold">Platform logo</label>
            <div class="d-flex align-items-center gap-3 mb-2 flex-wrap">
                <div style="width:60px;height:60px;border:1px solid #e0e5ec;border-radius:10px;background:#f8f9fa;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:4px">
                    <img id="logoPreview" src="<?= esc($logoUrl ?? '', 'attr') ?>" alt=""
                         style="max-width:100%;max-height:100%;object-fit:contain">
                </div>
                <div>
                    <div class="form-text mb-2">PNG, SVG or JPG · max 2 MB · recommended 200×200 px square</div>
                    <input type="file" name="brand_logo" id="brand_logo"
                           accept="image/png,image/svg+xml,image/jpeg,image/gif"
                           class="form-control form-control-sm" style="max-width:340px"
                           onchange="if(this.files[0]){document.getElementById('logoPreview').src=URL.createObjectURL(this.files[0])}">
                </div>
            </div>
            <div class="input-group mt-2" style="max-width:400px">
                <span class="input-group-text small text-secondary">Or URL</span>
                <input name="brand_logo_url" value="" class="form-control form-control-sm"
                       placeholder="https://…/logo.png">
            </div>
            <div class="form-text">Paste a public URL to use a hosted logo instead of uploading a file.</div>
        </div>

        <!-- Optional modules -->
        <div class="mb-3">
            <label class="form-label small fw-semibold d-block">Optional modules</label>
            <div class="form-text mb-2">Turn a module off to hide it everywhere. Existing data is kept.</div>
            <?php foreach (\App\Models\SettingsRepository::OPTIONAL_MODULES as $key => $label): ?>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="mod-<?= esc($key, 'attr') ?>" name="module_<?= esc($key, 'attr') ?>" value="1"
                           <?= ! empty($modules[$key]) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mod-<?= esc($key, 'attr') ?>">
                        <?= esc($label) ?>
                        <span class="text-secondary small">(<?= ! empty($modules[$key]) ? 'visible' : 'hidden' ?>)</span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <button class="btn btn-primary">Save branding &amp; modules</button>
    </form>
</div></div>

<div class="card mb-3"><div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-geo me-1"></i>Delivery</h2>
    <form method="post" action="<?= site_url('admin/settings/delivery') ?>" class="row g-2 align-items-end" style="max-width:560px">
        <?= csrf_field() ?>
        <div class="col-auto">
            <label class="form-label small">Max delivery radius (km)</label>
            <input name="max_radius_km" type="number" step="0.5" min="0.5" max="100" value="<?= esc(rtrim(rtrim(number_format((float) $maxRadius, 2), '0'), '.'), 'attr') ?>" class="form-control" style="max-width:160px" required>
        </div>
        <div class="col-auto"><button class="btn btn-primary">Save</button></div>
        <div class="col-12"><div class="form-text">The largest radius a vendor can pick at registration — they default to this and may reduce it.</div></div>
    </form>
</div></div>

<div class="alert alert-info d-flex align-items-center mb-3"><i class="bi bi-info-circle me-2"></i><div class="small">The lists below are read-only.</div></div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3"><div class="card-header fw-semibold">Settings</div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Namespace</th><th>Key</th><th>Value</th><th>Scope</th></tr></thead>
                <tbody>
                <?php foreach ($settings as $s): ?>
                    <tr><td class="small text-secondary"><?= esc($s['namespace']) ?></td><td class="small fw-medium"><?= esc($s['skey']) ?></td><td class="small"><code class="uk-code"><?= esc(mb_strimwidth((string) $s['value'], 0, 48, '…')) ?></code></td><td class="small text-capitalize"><?= esc($s['scope_type']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($settings)): ?><tr><td colspan="4" class="text-center text-secondary py-3">No settings.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
        <div class="card"><div class="card-header fw-semibold">Feature Flags</div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Flag</th><th>Description</th><th class="text-end">Enabled</th></tr></thead>
                <tbody>
                <?php foreach ($flags as $f): ?>
                    <tr><td class="small fw-medium"><?= esc($f['fkey']) ?></td><td class="small text-secondary"><?= esc($f['description'] ?? '—') ?></td>
                        <td class="text-end"><span class="badge text-bg-<?= $f['is_enabled'] ? 'success' : 'secondary' ?>"><?= $f['is_enabled'] ? 'On' : 'Off' ?></span></td></tr>
                <?php endforeach; ?>
                <?php if (empty($flags)): ?><tr><td colspan="3" class="text-center text-secondary py-3">No feature flags.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card"><div class="card-header fw-semibold"><i class="bi bi-receipt me-1"></i>GST Configuration</div><div class="card-body">
            <?php if ($gst): ?>
                <dl class="row mb-0 small">
                    <dt class="col-6 text-secondary">Rounding mode</dt><dd class="col-6 text-capitalize"><?= esc($gst['rounding_mode'] ?? '—') ?></dd>
                    <dt class="col-6 text-secondary">Composition</dt><dd class="col-6"><?= !empty($gst['composition']) ? 'Yes' : 'No' ?></dd>
                    <dt class="col-6 text-secondary">RCM default</dt><dd class="col-6"><?= !empty($gst['rcm_default']) ? 'Yes' : 'No' ?></dd>
                    <dt class="col-6 text-secondary">E-invoice</dt><dd class="col-6"><?= !empty($gst['einvoice_enabled']) ? 'Enabled' : 'Disabled' ?></dd>
                    <dt class="col-6 text-secondary">Invoice series</dt><dd class="col-6 small"><code class="uk-code"><?= esc($gst['invoice_series_format'] ?? '—') ?></code></dd>
                    <dt class="col-6 text-secondary">FY start month</dt><dd class="col-6"><?= esc($gst['fy_start_month'] ?? '—') ?></dd>
                </dl>
            <?php else: ?>
                <div class="text-secondary small">No GST configuration found.</div>
            <?php endif; ?>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
