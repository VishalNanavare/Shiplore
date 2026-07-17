<?php
/**
 * Shared specialized-type config (Admin + Vendor). Renders the panel matching
 * the product's product_type. Expects: $product, $type, plus type-specific data
 * ($bundleItems,$bundleEff,$vendorVariants,$downloads,$licenseStats,$plans,$gift)
 * and the action URLs ($bundleAddUrl,$bundleDelBase,$downloadAddUrl,
 * $downloadDelBase,$licenseAddUrl,$planAddUrl,$planDelBase,$giftUrl,$backUrl).
 */
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0"><?= ucfirst($type) ?> setup — <?= esc($product['title']) ?></h1>
    <a href="<?= $backUrl ?>" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to products</a>
</div>

<?php if (in_array($type, ['bundle', 'combo'], true)): ?>
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between flex-wrap mb-2">
            <h2 class="h6 mb-0">Components</h2>
            <div class="small">Components total ₹<?= esc(number_format((float) $bundleEff['components_total'], 2)) ?>
                · <span class="badge text-bg-success"><?= $type === 'combo' ? 'Combo price ₹' . number_format((float) $bundleEff['price'], 2) . ' (saves ₹' . number_format((float) $bundleEff['savings'], 2) . ')' : 'Bundle price ₹' . number_format((float) $bundleEff['price'], 2) ?></span>
            </div>
        </div>
        <table class="table table-sm align-middle"><thead class="table-light"><tr><th>Component</th><th>SKU</th><th class="text-end">Qty</th><th class="text-end">Unit ₹</th><th class="text-end">Contribution</th><th></th></tr></thead><tbody>
            <?php foreach ($bundleItems as $it): ?>
                <tr>
                    <td class="small"><?= esc($it['title']) ?> <?php if ($it['is_optional']): ?><span class="badge text-bg-light">optional</span><?php endif; ?></td>
                    <td class="small"><?= esc($it['sku']) ?></td>
                    <td class="text-end"><?= esc($it['qty']) ?></td>
                    <td class="text-end">₹<?= esc(number_format((float) $it['base_price'], 2)) ?></td>
                    <td class="text-end"><?= $it['price_contribution'] !== null ? '₹' . esc(number_format((float) $it['price_contribution'], 2)) : '—' ?></td>
                    <td class="text-end"><form method="post" action="<?= $bundleDelBase . $it['id'] ?>"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0">&times;</button></form></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($bundleItems)): ?><tr><td colspan="6" class="text-secondary small">No components yet.</td></tr><?php endif; ?>
        </tbody></table>
        <form method="post" action="<?= $bundleAddUrl ?>" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-5"><label class="form-label small">Component variant</label><select name="item_variant_id" class="form-select form-select-sm" required><option value="">Choose…</option><?php foreach ($vendorVariants as $vv): ?><option value="<?= esc($vv['id'], 'attr') ?>"><?= esc($vv['title']) ?> — <?= esc($vv['sku']) ?> (₹<?= esc(number_format((float) $vv['base_price'], 0)) ?>)</option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label small">Qty</label><input name="qty" type="number" step="0.001" value="1" class="form-control form-control-sm"></div>
            <div class="col-md-3"><label class="form-label small">Contribution ₹ (combo)</label><input name="price_contribution" type="number" step="0.01" class="form-control form-control-sm" placeholder="optional"></div>
            <div class="col-md-1"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="is_optional" value="1" id="opt"><label class="form-check-label small" for="opt">Opt</label></div></div>
            <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Add</button></div>
        </form>
    </div></div>

<?php elseif (in_array($type, ['digital', 'downloadable'], true)): ?>
    <div class="card mb-3"><div class="card-body">
        <h2 class="h6 mb-3">Download files</h2>
        <table class="table table-sm"><thead class="table-light"><tr><th>Label</th><th>Limit</th><th>Expiry (days)</th><th></th></tr></thead><tbody>
            <?php foreach ($downloads as $d): ?>
                <tr><td><?= esc($d['label']) ?></td><td><?= $d['download_limit'] ?? '∞' ?></td><td><?= $d['expiry_days'] ?? '∞' ?></td>
                <td class="text-end"><form method="post" action="<?= $downloadDelBase . $d['id'] ?>"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0">&times;</button></form></td></tr>
            <?php endforeach; ?>
            <?php if (empty($downloads)): ?><tr><td colspan="4" class="text-secondary small">No files yet.</td></tr><?php endif; ?>
        </tbody></table>
        <form method="post" action="<?= $downloadAddUrl ?>" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-5"><label class="form-label small">Label</label><input name="label" class="form-control form-control-sm" placeholder="e.g. User manual PDF" required></div>
            <div class="col-md-3"><label class="form-label small">Download limit</label><input name="download_limit" type="number" class="form-control form-control-sm" placeholder="∞"></div>
            <div class="col-md-3"><label class="form-label small">Expiry days</label><input name="expiry_days" type="number" class="form-control form-control-sm" placeholder="∞"></div>
            <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Add</button></div>
        </form>
    </div></div>
    <div class="card"><div class="card-body">
        <h2 class="h6 mb-2">License keys <span class="text-secondary small">(<?= (int) $licenseStats['available'] ?> available · <?= (int) $licenseStats['assigned'] ?> assigned)</span></h2>
        <form method="post" action="<?= $licenseAddUrl ?>">
            <?= csrf_field() ?>
            <textarea name="keys" class="form-control form-control-sm mb-2" rows="3" placeholder="One license key per line"></textarea>
            <button class="btn btn-sm btn-primary">Add keys</button>
        </form>
    </div></div>

<?php elseif ($type === 'subscription'): ?>
    <div class="card"><div class="card-body">
        <h2 class="h6 mb-3">Subscription plans</h2>
        <table class="table table-sm"><thead class="table-light"><tr><th>Name</th><th>Interval</th><th class="text-end">Price</th><th>Cycles</th><th>Trial</th><th>Auto-renew</th><th></th></tr></thead><tbody>
            <?php foreach ($plans as $p): ?>
                <tr><td><?= esc($p['name']) ?></td><td><?= esc($p['interval_unit']) ?></td><td class="text-end">₹<?= esc(number_format((float) $p['price'], 2)) ?></td><td><?= $p['billing_cycles'] ?? '∞' ?></td><td><?= esc($p['trial_days']) ?>d</td><td><?= $p['auto_renew'] ? 'Yes' : 'No' ?></td>
                <td class="text-end"><form method="post" action="<?= $planDelBase . $p['id'] ?>"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0">&times;</button></form></td></tr>
            <?php endforeach; ?>
            <?php if (empty($plans)): ?><tr><td colspan="7" class="text-secondary small">No plans yet.</td></tr><?php endif; ?>
        </tbody></table>
        <form method="post" action="<?= $planAddUrl ?>" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-3"><label class="form-label small">Plan name</label><input name="name" class="form-control form-control-sm" required></div>
            <div class="col-md-2"><label class="form-label small">Interval</label><select name="interval_unit" class="form-select form-select-sm"><?php foreach (['daily', 'weekly', 'monthly', 'yearly'] as $iv): ?><option value="<?= $iv ?>" <?= $iv === 'monthly' ? 'selected' : '' ?>><?= ucfirst($iv) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label small">Price ₹</label><input name="price" type="number" step="0.01" class="form-control form-control-sm" required></div>
            <div class="col-md-2"><label class="form-label small">Cycles</label><input name="billing_cycles" type="number" class="form-control form-control-sm" placeholder="∞"></div>
            <div class="col-md-1"><label class="form-label small">Trial</label><input name="trial_days" type="number" value="0" class="form-control form-control-sm"></div>
            <div class="col-md-1"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="auto_renew" value="1" checked id="ar"><label class="form-check-label small" for="ar">Renew</label></div></div>
            <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Add</button></div>
        </form>
    </div></div>

<?php elseif ($type === 'giftcard'): ?>
    <div class="card"><div class="card-body">
        <h2 class="h6 mb-3">Gift-card settings</h2>
        <form method="post" action="<?= $giftUrl ?>" class="row g-3">
            <?= csrf_field() ?>
            <div class="col-md-6"><label class="form-label">Fixed denominations <span class="text-secondary small">(comma-separated ₹; leave blank for a range)</span></label><input name="denominations" class="form-control" value="<?= esc(implode(', ', $gift['denominations'] ?? []), 'attr') ?>" placeholder="100, 500, 1000"></div>
            <div class="col-md-3"><label class="form-label">Min amount ₹</label><input name="min_amount" type="number" step="0.01" class="form-control" value="<?= esc($gift['min_amount'] ?? '', 'attr') ?>"></div>
            <div class="col-md-3"><label class="form-label">Max amount ₹</label><input name="max_amount" type="number" step="0.01" class="form-control" value="<?= esc($gift['max_amount'] ?? '', 'attr') ?>"></div>
            <div class="col-md-3"><label class="form-label">Validity (days)</label><input name="validity_days" type="number" class="form-control" value="<?= esc($gift['validity_days'] ?? '', 'attr') ?>"></div>
            <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_reloadable" value="1" id="rl" <?= ! empty($gift['is_reloadable']) ? 'checked' : '' ?>><label class="form-check-label" for="rl">Reloadable</label></div></div>
            <div class="col-12"><button class="btn btn-primary">Save gift-card settings</button></div>
        </form>
    </div></div>

<?php elseif ($type === 'rental'): ?>
    <div class="card"><div class="card-body">
        <h2 class="h6 mb-3">Rental terms</h2>
        <form method="post" action="<?= $rentalUrl ?>" class="row g-3">
            <?= csrf_field() ?>
            <div class="col-md-3"><label class="form-label">Price per day ₹</label><input name="price_per_day" type="number" step="0.01" class="form-control" value="<?= esc($rental['price_per_day'] ?? '', 'attr') ?>"></div>
            <div class="col-md-3"><label class="form-label">Security deposit ₹</label><input name="deposit" type="number" step="0.01" class="form-control" value="<?= esc($rental['deposit'] ?? '', 'attr') ?>"></div>
            <div class="col-md-3"><label class="form-label">Min days</label><input name="min_days" type="number" class="form-control" value="<?= esc($rental['min_days'] ?? '1', 'attr') ?>"></div>
            <div class="col-md-3"><label class="form-label">Max days</label><input name="max_days" type="number" class="form-control" value="<?= esc($rental['max_days'] ?? '', 'attr') ?>" placeholder="∞"></div>
            <div class="col-12"><button class="btn btn-primary">Save rental terms</button></div>
        </form>
    </div></div>
<?php else: ?>
    <div class="card"><div class="card-body text-secondary">This product is a <strong><?= esc($type) ?></strong> product and needs no extra type setup. Change the Product Type on the edit page to bundle/combo, digital, subscription, gift card or rental to configure those.</div></div>
<?php endif; ?>
