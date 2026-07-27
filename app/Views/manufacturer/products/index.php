<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Products</h5>
    <a class="btn btn-sm btn-primary" href="<?= site_url('manufacturer/products/new') ?>">
        <i class="bi bi-plus-lg"></i> New Product
    </a>
</div>

<form method="get" class="mb-3">
    <select name="status" class="form-select form-select-sm w-auto d-inline-block" onchange="this.form.submit()">
        <?php foreach (['' => 'All statuses', 'draft' => 'Draft', 'submitted' => 'Submitted', 'published' => 'Published', 'unpublished' => 'Unpublished'] as $v => $label): ?>
            <option value="<?= esc($v, 'attr') ?>" <?= ($filters['status'] ?? '') === $v ? 'selected' : '' ?>><?= esc($label) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Category</th>
                    <th class="text-end">Making Price</th>
                    <th class="text-end">Selling Price</th>
                    <th class="text-end">Margin</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="8" class="text-center text-secondary py-4">No products yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($products as $p):
                        $making  = (float) ($p['making_price'] ?? 0);
                        $selling = (float) ($p['base_price'] ?? 0);
                        $margin  = $selling > 0 ? (($selling - $making) / $selling) * 100 : 0;
                    ?>
                        <tr>
                            <td>
                                <a href="<?= site_url('manufacturer/products/' . (int) $p['id'] . '/edit') ?>">
                                    <?= esc($p['title'] ?? '') ?>
                                </a>
                            </td>
                            <td class="small text-secondary"><?= esc((string) ($p['sku'] ?? '')) ?></td>
                            <td class="small"><?= esc((string) ($p['category'] ?? '—')) ?></td>
                            <td class="text-end">₹<?= esc(number_format($making, 2)) ?></td>
                            <td class="text-end">₹<?= esc(number_format($selling, 2)) ?></td>
                            <td class="text-end small <?= $margin > 0 ? 'text-success' : 'text-danger' ?>">
                                <?= esc(number_format($margin, 1)) ?>%
                            </td>
                            <td><span class="badge bg-secondary"><?= esc((string) ($p['status'] ?? '')) ?></span></td>
                            <td class="text-end">
                                <?php if (($p['status'] ?? '') === 'draft'): ?>
                                    <form method="post" action="<?= site_url('manufacturer/products/' . (int) $p['id'] . '/submit') ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-primary" type="submit">Submit</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
