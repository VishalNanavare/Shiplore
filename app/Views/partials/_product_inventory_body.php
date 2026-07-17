<?php
/**
 * Shared per-product inventory panel (Admin + Vendor). Expects:
 * $product, $rows (list of ['v'=>variant,'levels'=>[shopId=>lvl],'val'=>valuation]),
 * $shops (vendor's shops), $receiveUrl, $adjustUrl, $backUrl.
 */
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Inventory — <?= esc($product['title']) ?></h1>
    <a href="<?= $backUrl ?>" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to products</a>
</div>

<?php if (empty($shops)): ?><div class="alert alert-warning">This vendor has no shops yet — stock is held per shop. Create a shop first.</div><?php endif; ?>

<?php foreach ($rows as $r): $v = $r['v']; ?>
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between flex-wrap mb-2">
            <div><span class="fw-semibold"><?= esc($v['sku']) ?></span> <span class="text-secondary small"><?= $v['attributes'] !== '' ? esc($v['attributes']) : 'Default' ?></span></div>
            <div class="text-secondary small">On-hand value (<?= esc($r['val']['method']) ?>): <span class="fw-semibold">₹<?= esc(number_format((float) $r['val']['on_hand_value'], 2)) ?></span> · avg cost ₹<?= esc(number_format((float) $r['val']['wavg_cost'], 2)) ?> · qty <?= esc($r['val']['on_hand_qty']) ?></div>
        </div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-2">
            <thead class="table-light"><tr><th>Shop</th><th class="text-end">On hand</th><th class="text-end">Reserved</th><th class="text-end">Available</th><th class="text-end">Reorder</th></tr></thead>
            <tbody>
            <?php foreach ($shops as $s): $lvl = $r['levels'][$s['id']] ?? ['on_hand' => 0, 'reserved' => 0, 'available' => 0, 'reorder_level' => null]; ?>
                <tr>
                    <td><?= esc($s['name']) ?></td>
                    <td class="text-end"><?= esc($lvl['on_hand']) ?></td>
                    <td class="text-end"><?= esc($lvl['reserved']) ?></td>
                    <td class="text-end fw-semibold <?= ($lvl['reorder_level'] !== null && (float) $lvl['available'] <= (float) $lvl['reorder_level']) ? 'text-danger' : '' ?>"><?= esc($lvl['available']) ?></td>
                    <td class="text-end"><?= $lvl['reorder_level'] !== null ? esc($lvl['reorder_level']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="row g-3">
            <div class="col-md-6">
                <form method="post" action="<?= $receiveUrl ?>" class="row g-1 align-items-end">
                    <?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= esc($v['id'], 'attr') ?>">
                    <div class="col-12"><label class="form-label small mb-0 fw-semibold text-success"><i class="bi bi-box-arrow-in-down me-1"></i>Receive stock</label></div>
                    <div class="col"><select name="shop_id" class="form-select form-select-sm"><?php foreach ($shops as $s): ?><option value="<?= esc($s['id'], 'attr') ?>"><?= esc($s['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col"><input name="qty" type="number" step="0.001" class="form-control form-control-sm" placeholder="Qty" required></div>
                    <div class="col"><input name="cost" type="number" step="0.01" class="form-control form-control-sm" placeholder="Cost ₹"></div>
                    <div class="col"><input name="batch_no" class="form-control form-control-sm" placeholder="Batch"></div>
                    <div class="col-auto"><button class="btn btn-sm btn-success">Receive</button></div>
                </form>
            </div>
            <div class="col-md-6">
                <form method="post" action="<?= $adjustUrl ?>" class="row g-1 align-items-end">
                    <?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= esc($v['id'], 'attr') ?>">
                    <div class="col-12"><label class="form-label small mb-0 fw-semibold text-warning"><i class="bi bi-sliders me-1"></i>Adjust (±)</label></div>
                    <div class="col"><select name="shop_id" class="form-select form-select-sm"><?php foreach ($shops as $s): ?><option value="<?= esc($s['id'], 'attr') ?>"><?= esc($s['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col"><input name="qty_delta" type="number" step="0.001" class="form-control form-control-sm" placeholder="±Qty" required></div>
                    <div class="col"><select name="reason" class="form-select form-select-sm"><?php foreach (['manual' => 'Manual', 'damage' => 'Damaged', 'return' => 'Returned', 'audit' => 'Stock audit'] as $rv => $rl): ?><option value="<?= $rv ?>"><?= $rl ?></option><?php endforeach; ?></select></div>
                    <div class="col-auto"><button class="btn btn-sm btn-warning">Adjust</button></div>
                </form>
            </div>
        </div>
    </div></div>
<?php endforeach; ?>
<?php if (empty($rows)): ?><div class="card"><div class="card-body text-secondary text-center py-4">No variants — create variants first, then receive stock per shop.</div></div><?php endif; ?>
