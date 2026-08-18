<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php
/**
 * The unit every stock write on this page lands in.
 *
 * This used to be a hidden input pinned to $unitId, and $unitOptions was passed by the
 * controller and never rendered. For an owner spanning several plants $unitId is simply
 * allowedMshopIds()[0] — first row, no ORDER BY — so filtering the grid to Plant B,
 * clicking Manage and recording production booked it into Plant A, with a success
 * message. Nothing in the UI showed which plant had been credited.
 *
 * Rendered as a select whenever there is a choice to make, so the destination is always
 * visible and deliberate. With exactly one unit there is nothing to choose and the hidden
 * input is still correct. The posted value is re-checked server-side against
 * allowedMshopIds() either way — this fixes a silent mis-post, it is not the access check.
 */
$unitField = static function (string $id) use ($unitOptions, $unitId): string {
    if (count($unitOptions ?? []) <= 1) {
        return '<input type="hidden" name="mshop_id" value="' . (int) $unitId . '">';
    }
    $out = '<div class="col-12"><label class="form-label small" for="' . esc($id, 'attr') . '">Unit</label>'
         . '<select class="form-select form-select-sm" id="' . esc($id, 'attr') . '" name="mshop_id" required>';

    foreach ($unitOptions as $uid => $uname) {
        $out .= '<option value="' . (int) $uid . '"' . ((int) $unitId === (int) $uid ? ' selected' : '') . '>'
              . esc((string) $uname) . '</option>';
    }

    return $out . '</select></div>';
};
?>

<?php if (session('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session('success')) ?></div>
<?php endif; ?>
<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Stock — <?= esc((string) ($product['title'] ?? '')) ?></h5>
    <div class="btn-group btn-group-sm">
        <a class="btn btn-outline-secondary" href="<?= site_url('manufacturer/products/' . (int) $product['id'] . '/variants') ?>">
            <i class="bi bi-diagram-3 me-1"></i>Variants
        </a>
        <a class="btn btn-light" href="<?= site_url('manufacturer/inventory') ?>">
            <i class="bi bi-arrow-left me-1"></i>All stock
        </a>
    </div>
</div>

<?php if (empty($variants)): ?>
    <div class="card"><div class="card-body text-secondary">
        This product has no variants yet. Stock is held per variant —
        <a href="<?= site_url('manufacturer/products/' . (int) $product['id'] . '/variants') ?>">add one first</a>.
    </div></div>
<?php else: ?>
    <?php foreach ($variants as $v): ?>
        <?php $vid = (int) $v['id']; $lv = $levels[$vid] ?? ['on_hand' => 0, 'reserved' => 0, 'available' => 0]; ?>
        <div class="card mb-3">
            <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <strong><?= esc(($v['attributes'] ?? '') !== '' ? $v['attributes'] : 'Base item') ?></strong>
                    <span class="text-secondary small ms-2"><?= esc((string) ($v['sku'] ?? '')) ?></span>
                </div>
                <div class="small">
                    On hand <strong><?= esc(rtrim(rtrim((string) $lv['on_hand'], '0'), '.')) ?: '0' ?></strong>
                    · Available <strong><?= esc(rtrim(rtrim((string) $lv['available'], '0'), '.')) ?: '0' ?></strong>
                </div>
            </div>

            <?php if (! empty($canAdjust)): ?>
                <div class="card-body border-bottom">
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <form method="post" action="<?= site_url('manufacturer/products/' . (int) $product['id'] . '/stock/produce') ?>" class="row g-2 align-items-end">
                                <?= csrf_field() ?>
                                <input type="hidden" name="variant_id" value="<?= $vid ?>">
                                <div class="col-12"><span class="small fw-semibold"><i class="bi bi-hammer me-1"></i>Record production</span></div>
                                <?= $unitField('pu-' . $vid) ?>
                                <div class="col-4">
                                    <label class="form-label small" for="q-<?= $vid ?>">Quantity</label>
                                    <input class="form-control form-control-sm" id="q-<?= $vid ?>" name="qty" type="number" step="0.001" min="0.001" required>
                                </div>
                                <div class="col-4">
                                    <label class="form-label small" for="c-<?= $vid ?>">Making cost</label>
                                    <input class="form-control form-control-sm" id="c-<?= $vid ?>" name="making_cost" type="number" step="0.01" min="0">
                                </div>
                                <div class="col-4">
                                    <label class="form-label small" for="b-<?= $vid ?>">Batch</label>
                                    <input class="form-control form-control-sm" id="b-<?= $vid ?>" name="batch_no" placeholder="auto">
                                </div>
                                <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Add to stock</button></div>
                            </form>
                        </div>

                        <div class="col-lg-6">
                            <form method="post" action="<?= site_url('manufacturer/products/' . (int) $product['id'] . '/stock/adjust') ?>" class="row g-2 align-items-end">
                                <?= csrf_field() ?>
                                <input type="hidden" name="variant_id" value="<?= $vid ?>">
                                <div class="col-12"><span class="small fw-semibold"><i class="bi bi-sliders me-1"></i>Adjust</span></div>
                                <?= $unitField('au-' . $vid) ?>
                                <div class="col-4">
                                    <label class="form-label small" for="d-<?= $vid ?>">Change (+/−)</label>
                                    <input class="form-control form-control-sm" id="d-<?= $vid ?>" name="qty_delta" type="number" step="0.001" required>
                                </div>
                                <div class="col-4">
                                    <label class="form-label small" for="r-<?= $vid ?>">Reason</label>
                                    <select class="form-select form-select-sm" id="r-<?= $vid ?>" name="reason">
                                        <option value="manual">Manual correction</option>
                                        <option value="damage">Damage / scrap</option>
                                        <option value="return">Return</option>
                                    </select>
                                </div>
                                <div class="col-4">
                                    <label class="form-label small" for="n-<?= $vid ?>">Note</label>
                                    <input class="form-control form-control-sm" id="n-<?= $vid ?>" name="notes">
                                </div>
                                <div class="col-12"><button class="btn btn-sm btn-outline-secondary" type="submit">Apply</button></div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Movement</th><th class="text-end">Qty</th><th class="text-end">Balance</th><th>Note</th><th>When</th></tr></thead>
                    <tbody>
                        <?php if (empty($ledger[$vid])): ?>
                            <tr><td colspan="5" class="text-center text-secondary py-3 small">No movements yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($ledger[$vid] as $m): ?>
                                <tr>
                                    <td class="small"><?= esc(str_replace('_', ' ', (string) ($m['movement_type'] ?? ''))) ?></td>
                                    <td class="text-end small <?= ((float) ($m['qty'] ?? 0)) < 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= esc(rtrim(rtrim((string) ($m['qty'] ?? '0'), '0'), '.')) ?: '0' ?>
                                    </td>
                                    <td class="text-end small"><?= esc(rtrim(rtrim((string) ($m['balance_after'] ?? '0'), '0'), '.')) ?: '0' ?></td>
                                    <td class="small text-secondary"><?= esc((string) ($m['note'] ?? '')) ?></td>
                                    <td class="small text-secondary"><?= esc(substr((string) ($m['created_at'] ?? ''), 0, 16)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?= $this->endSection() ?>
