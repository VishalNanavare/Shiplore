<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php if (session('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session('success')) ?></div>
<?php endif; ?>
<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>

<?php
// Status vocabulary, matching vendor/products/index.php. The filter previously offered
// only 4 of the 6 real statuses, so products sitting in under_review/approved/rejected
// were unfilterable — and every badge rendered grey, so "under_review" and "published"
// looked alike.
$statuses = [
    '' => 'All statuses', 'draft' => 'Draft', 'submitted' => 'Submitted',
    'under_review' => 'Under review', 'approved' => 'Approved', 'rejected' => 'Rejected',
    'published' => 'Published', 'unpublished' => 'Unpublished',
];
$badge = [
    'draft' => 'secondary', 'submitted' => 'info', 'under_review' => 'info',
    'approved' => 'primary', 'rejected' => 'danger',
    'published' => 'success', 'unpublished' => 'warning',
];
$money = static fn ($v): string => $v === null || $v === '' ? '—' : '₹' . number_format((float) $v, 2);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Products <span class="text-secondary small">(<?= count($products) ?>)</span></h5>
    <a class="btn btn-sm btn-primary" href="<?= site_url('manufacturer/products/new') ?>">
        <i class="bi bi-plus-lg"></i> New Product
    </a>
</div>

<form method="get" class="mb-3 d-flex gap-2 flex-wrap">
    <select name="status" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Status">
        <?php foreach ($statuses as $v => $label): ?>
            <option value="<?= esc($v, 'attr') ?>" <?= ($filters['status'] ?? '') === $v ? 'selected' : '' ?>><?= esc($label) ?></option>
        <?php endforeach; ?>
    </select>

    <?php // index() already honours ?mshop_id= through effectiveMshopId(); it simply had no control. ?>
    <?php if (! empty($unitOptions) && count($unitOptions) > 1): ?>
        <select name="mshop_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Unit">
            <option value="">All units</option>
            <?php foreach ($unitOptions as $uid => $uname): ?>
                <option value="<?= (int) $uid ?>" <?= (int) ($activeUnit ?? 0) === (int) $uid ? 'selected' : '' ?>>
                    <?= esc($uname) ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle" id="productsTable">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Category</th>
                    <th class="text-end">Making Price</th>
                    <th class="text-end">Selling Price</th>
                    <th class="text-end">Margin</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="8" class="text-center text-secondary py-4">
                        No products yet. Create one, then set its SKU and price on the Variants page.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($products as $p):
                        $pid     = (int) $p['id'];
                        $st      = (string) ($p['status'] ?? '');
                        $making  = $p['making_price'] ?? null;
                        $selling = $p['base_price'] ?? null;
                        // Margin is only meaningful once BOTH prices are set; a draft with
                        // neither previously rendered as "0.0%" in red, reading as a loss.
                        $hasPrices = $making !== null && $making !== '' && $selling !== null && $selling !== '' && (float) $selling > 0;
                        $margin    = $hasPrices ? (((float) $selling - (float) $making) / (float) $selling) * 100 : null;
                    ?>
                        <tr>
                            <td>
                                <a href="<?= site_url('manufacturer/products/' . $pid . '/edit') ?>">
                                    <?= esc($p['title'] ?? '') ?>
                                </a>
                            </td>
                            <td class="small text-secondary"><?= esc(($p['sku'] ?? '') !== '' ? $p['sku'] : '—') ?></td>
                            <td class="small"><?= esc((string) ($p['category'] ?? '—')) ?></td>
                            <td class="text-end"><?= esc($money($making)) ?></td>
                            <td class="text-end"><?= esc($money($selling)) ?></td>
                            <td class="text-end small <?= $margin === null ? 'text-secondary' : ($margin > 0 ? 'text-success' : 'text-danger') ?>">
                                <?= $margin === null ? '—' : esc(number_format($margin, 1)) . '%' ?>
                            </td>
                            <td><span class="badge bg-<?= esc($badge[$st] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $st)) ?></span></td>
                            <td class="text-end">
                                <?php
                                // SKU, price, stock and barcodes live on the Variants page for
                                // every panel, so without this group a manufacturer could not
                                // price a product at all except by typing the URL.
                                ?>
                                <div class="btn-group btn-group-sm">
                                    <a class="btn btn-outline-secondary" title="Variants"
                                       href="<?= site_url('manufacturer/products/' . $pid . '/variants') ?>"><i class="bi bi-grid-3x3-gap"></i></a>
                                    <a class="btn btn-outline-secondary" title="Stock"
                                       href="<?= site_url('manufacturer/products/' . $pid . '/stock') ?>"><i class="bi bi-boxes"></i></a>
                                    <?php if (! empty($canUpdate)): ?>
                                        <a class="btn btn-outline-secondary" title="Edit"
                                           href="<?= site_url('manufacturer/products/' . $pid . '/edit') ?>"><i class="bi bi-pencil"></i></a>
                                    <?php endif; ?>
                                </div>

                                <?php // A rejected product was previously a dead end — no way back to submitted. ?>
                                <?php if (in_array($st, ['draft', 'rejected'], true)): ?>
                                    <form method="post" action="<?= site_url('manufacturer/products/' . $pid . '/submit') ?>" class="d-inline ms-1">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-primary" type="submit" title="Submit for approval">
                                            <i class="bi bi-send me-1"></i>Submit
                                        </button>
                                    </form>
                                <?php elseif (in_array($st, ['approved', 'unpublished'], true) && ! empty($canUpdate)): ?>
                                    <form method="post" action="<?= site_url('manufacturer/products/' . $pid . '/publish') ?>" class="d-inline ms-1">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-success" type="submit" title="List on monline for vendor buyers">
                                            <i class="bi bi-globe me-1"></i>Publish
                                        </button>
                                    </form>
                                <?php elseif ($st === 'published' && ! empty($canUpdate)): ?>
                                    <form method="post" action="<?= site_url('manufacturer/products/' . $pid . '/unpublish') ?>" class="d-inline ms-1"
                                          data-confirm="Hide this product from monline buyers?">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-secondary" type="submit" title="Hide from monline">
                                            <i class="bi bi-eye-slash me-1"></i>Unpublish
                                        </button>
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
