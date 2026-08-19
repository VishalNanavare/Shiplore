<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>
<?php if (session('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session('success')) ?></div>
<?php endif; ?>

<h5 class="mb-3">Combo Offers</h5>

<div class="card mb-3">
    <div class="card-header py-2"><strong>New combo</strong></div>
    <div class="card-body">
        <form method="post" action="<?= site_url('manufacturer/combos') ?>" class="row g-2">
            <?= csrf_field() ?>

            <div class="col-md-4">
                <label class="form-label small" for="cName">Name</label>
                <input class="form-control form-control-sm" id="cName" name="name" required maxlength="191">
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="cMake">Making price (₹)</label>
                <input class="form-control form-control-sm" id="cMake" name="making_price" type="number" step="0.01" min="0.01" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="cSell">Selling price (₹)</label>
                <input class="form-control form-control-sm" id="cSell" name="base_price" type="number" step="0.01" min="0.01" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="cMode">Stock mode</label>
                <select class="form-select form-select-sm" id="cMode" name="inventory_mode">
                    <option value="virtual">Virtual — draw from components</option>
                    <option value="assembled">Assembled — stocked in its own right</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="cUnit">List at unit</label>
                <select class="form-select form-select-sm" id="cUnit" name="mshop_ids[]">
                    <?php foreach (($units ?? []) as $uid => $uname): ?>
                        <option value="<?= (int) $uid ?>"><?= esc($uname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12"><hr class="my-2"></div>
            <div class="col-12"><span class="small fw-semibold">Components — at least two of your own items</span></div>

            <?php for ($i = 0; $i < 3; $i++): ?>
                <div class="col-md-3">
                    <label class="form-label small" for="cv<?= $i ?>">Variant ID <?= $i + 1 ?><?= $i < 2 ? '' : ' (optional)' ?></label>
                    <input class="form-control form-control-sm" id="cv<?= $i ?>" name="components[<?= $i ?>][variant_id]" type="number" min="1" <?= $i < 2 ? 'required' : '' ?>>
                </div>
                <div class="col-md-1">
                    <label class="form-label small" for="cq<?= $i ?>">Qty</label>
                    <input class="form-control form-control-sm" id="cq<?= $i ?>" name="components[<?= $i ?>][qty]" type="number" step="0.001" min="0.001" value="1">
                </div>
            <?php endfor; ?>

            <div class="col-12 d-flex align-items-center gap-3 mt-2">
                <button class="btn btn-sm btn-primary" type="submit">Create combo</button>
                <span class="small text-secondary">
                    Find variant IDs on the <a href="<?= site_url('manufacturer/products') ?>">products</a> page.
                    Combos are B2B only — never shown on the customer storefront.
                </span>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <?php if (empty($combos)): ?>
        <div class="card-body text-secondary">No combos yet.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr><th>Combo</th><th>SKU</th><th>Items</th><th>Stock mode</th>
                        <th class="text-end">Making</th><th class="text-end">Selling</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($combos as $c): ?>
                        <tr>
                            <td class="fw-semibold"><?= esc((string) ($c['title'] ?? '')) ?></td>
                            <td class="text-secondary small"><?= esc((string) ($c['sku'] ?? '')) ?></td>
                            <td><?= (int) ($c['component_count'] ?? 0) ?></td>
                            <td class="small text-secondary"><?= esc((string) ($c['combo_inventory_mode'] ?? 'virtual')) ?></td>
                            <td class="text-end">₹<?= esc(number_format((float) ($c['making_price'] ?? 0), 2)) ?></td>
                            <td class="text-end">₹<?= esc(number_format((float) ($c['base_price'] ?? 0), 2)) ?></td>
                            <td><span class="badge bg-light text-dark"><?= esc((string) ($c['status'] ?? '')) ?></span></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= site_url('manufacturer/products/' . (int) ($c['id'] ?? 0) . '/variants') ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
