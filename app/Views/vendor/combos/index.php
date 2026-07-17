<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold">Combo Offers</div>
            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Combo</th><th>Price</th><th>Channel</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($combos as $c): ?>
                    <tr>
                        <td class="fw-semibold small"><?= esc($c['title']) ?></td>
                        <td>₹<?= esc(number_format((float) $c['base_price'], 2)) ?></td>
                        <td><span class="badge text-bg-<?= (int) $c['is_online_enabled'] === 1 ? 'info' : 'secondary' ?>"><?= (int) $c['is_online_enabled'] === 1 ? 'online + POS' : 'POS only' ?></span></td>
                        <td class="small text-capitalize"><?= esc($c['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($combos)): ?><tr><td colspan="4" class="text-center text-secondary py-4">No combos yet.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold">New Combo</div>
            <form method="post" action="<?= site_url('vendor/combos') ?>" class="card-body">
                <?= csrf_field() ?>
                <div class="mb-2"><label class="form-label small">Combo name</label><input name="name" class="form-control form-control-sm" required></div>
                <div class="row g-2 mb-2">
                    <div class="col"><label class="form-label small">Category</label>
                        <select name="category_id" class="form-select form-select-sm">
                            <?php foreach (($categories ?? []) as $cat): ?><option value="<?= (int) $cat['id'] ?>"><?= esc($cat['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col"><label class="form-label small">Tax class</label>
                        <select name="tax_class_id" class="form-select form-select-sm">
                            <?php foreach (($masters['tax'] ?? []) as $t): ?><option value="<?= (int) $t['id'] ?>"><?= esc($t['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col"><label class="form-label small">MRP</label><input name="mrp" type="number" step="0.01" class="form-control form-control-sm"></div>
                    <div class="col"><label class="form-label small">Combo price</label><input name="price" type="number" step="0.01" class="form-control form-control-sm"></div>
                    <div class="col"><label class="form-label small">Unit</label>
                        <select name="unit_id" class="form-select form-select-sm">
                            <?php foreach (($masters['units'] ?? []) as $u): ?><option value="<?= (int) $u['id'] ?>"><?= esc($u['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col"><label class="form-label small">Channel</label>
                        <select name="channel" class="form-select form-select-sm"><option value="pos">POS only (vendor approval)</option><option value="online">Online (vendor → admin)</option></select>
                    </div>
                    <div class="col"><label class="form-label small">Inventory</label>
                        <select name="inventory_mode" class="form-select form-select-sm"><option value="virtual">Virtual (from components)</option><option value="assembled">Assembled (own stock)</option></select>
                    </div>
                </div>
                <hr>
                <label class="form-label small">Components — pick at least two of your products</label>
                <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="input-group input-group-sm mb-1">
                        <select name="component_variant_id[]" class="form-select">
                            <option value="">— select a product —</option>
                            <?php foreach (($variants ?? []) as $v): ?>
                                <option value="<?= (int) $v['id'] ?>"><?= esc($v['title']) ?> · <?= esc($v['sku']) ?> (₹<?= esc(number_format((float) $v['base_price'], 2)) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <input name="component_qty[]" type="number" min="1" value="1" class="form-control" style="max-width:90px" title="Quantity">
                    </div>
                <?php endfor; ?>
                <?php if (empty($variants ?? [])): ?><div class="form-text text-warning">Add some products first — a combo is built from your existing products.</div><?php endif; ?>
                <button class="btn btn-sm btn-primary w-100 mt-2"><i class="bi bi-box2 me-1"></i>Create combo</button>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
