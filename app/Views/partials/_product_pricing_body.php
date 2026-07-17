<?php
/**
 * Shared per-product pricing panel (Admin + Vendor). Expects:
 * $product, $rows (per variant: ['v','eff','specials','tiers','groups']),
 * $groups (customer groups), $specialUrl,$tierUrl,$groupUrl,$delSpecialBase,
 * $delTierBase, $backUrl.
 */
$kindBadge = ['flash' => 'danger', 'deal' => 'warning', 'offer' => 'info', 'base' => 'secondary', 'group' => 'primary'];
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Pricing — <?= esc($product['title']) ?></h1>
    <a href="<?= $backUrl ?>" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to products</a>
</div>

<?php foreach ($rows as $r): $v = $r['v']; ?>
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between flex-wrap mb-3">
            <div><span class="fw-semibold"><?= esc($v['sku']) ?></span> <span class="text-secondary small"><?= $v['attributes'] !== '' ? esc($v['attributes']) : 'Default' ?></span></div>
            <div class="small">MRP ₹<?= esc(number_format((float) $v['mrp'], 2)) ?> · Base ₹<?= esc(number_format((float) $v['base_price'], 2)) ?>
                · <span class="badge text-bg-success">Effective (retail/online, qty 1): ₹<?= esc(number_format((float) $r['eff']['price'], 2)) ?> <span class="opacity-75">(<?= esc($r['eff']['source']) ?>)</span></span>
            </div>
        </div>

        <div class="row g-3">
            <!-- Special overlays -->
            <div class="col-lg-5">
                <div class="fw-semibold small mb-1">Offer / Deal / Flash</div>
                <table class="table table-sm mb-1"><tbody>
                    <?php foreach ($r['specials'] as $s): ?>
                        <tr>
                            <td><span class="badge text-bg-<?= $kindBadge[$s['kind']] ?? 'secondary' ?>"><?= esc($s['kind']) ?></span></td>
                            <td>₹<?= esc(number_format((float) $s['price'], 2)) ?></td>
                            <td class="small text-secondary"><?= esc($s['channel']) ?><?= $s['group_name'] ? ' · ' . esc($s['group_name']) : '' ?><?= $s['valid_to'] ? ' · till ' . esc(substr((string) $s['valid_to'], 0, 10)) : '' ?></td>
                            <td class="text-end"><form method="post" action="<?= $delSpecialBase . $s['list_id'] ?>" data-confirm="Remove this price?"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0">&times;</button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($r['specials'])): ?><tr><td colspan="4" class="text-secondary small">No special prices.</td></tr><?php endif; ?>
                </tbody></table>
                <form method="post" action="<?= $specialUrl ?>" class="row g-1">
                    <?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= esc($v['id'], 'attr') ?>">
                    <div class="col-4"><select name="kind" class="form-select form-select-sm"><?php foreach (['offer', 'deal', 'flash'] as $k): ?><option value="<?= $k ?>"><?= ucfirst($k) ?></option><?php endforeach; ?></select></div>
                    <div class="col-4"><input name="price" type="number" step="0.01" class="form-control form-control-sm" placeholder="Price ₹" required></div>
                    <div class="col-4"><select name="channel" class="form-select form-select-sm"><option value="all">All</option><option value="online">Online</option><option value="pos">POS</option></select></div>
                    <div class="col-6"><input name="valid_from" type="date" class="form-control form-control-sm" title="From"></div>
                    <div class="col-6"><input name="valid_to" type="date" class="form-control form-control-sm" title="To"></div>
                    <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Add special price</button></div>
                </form>
            </div>

            <!-- Tiers -->
            <div class="col-lg-3">
                <div class="fw-semibold small mb-1">Quantity tiers</div>
                <table class="table table-sm mb-1"><tbody>
                    <?php foreach ($r['tiers'] as $t): ?>
                        <tr><td class="small">≥ <?= esc($t['min_qty']) ?></td><td>₹<?= esc(number_format((float) $t['price'], 2)) ?></td>
                        <td class="text-end"><form method="post" action="<?= $delTierBase . $t['id'] ?>"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0">&times;</button></form></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($r['tiers'])): ?><tr><td colspan="3" class="text-secondary small">No tiers.</td></tr><?php endif; ?>
                </tbody></table>
                <form method="post" action="<?= $tierUrl ?>" class="row g-1">
                    <?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= esc($v['id'], 'attr') ?>">
                    <div class="col-6"><input name="min_qty" type="number" step="1" min="1" class="form-control form-control-sm" placeholder="Min qty" required></div>
                    <div class="col-6"><input name="price" type="number" step="0.01" class="form-control form-control-sm" placeholder="Price ₹" required></div>
                    <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Add tier</button></div>
                </form>
            </div>

            <!-- Group prices -->
            <div class="col-lg-4">
                <div class="fw-semibold small mb-1">Customer-group prices</div>
                <table class="table table-sm mb-1"><tbody>
                    <?php foreach ($r['groups'] as $gp): ?>
                        <tr><td class="small"><?= esc($gp['group_name']) ?></td><td>₹<?= esc(number_format((float) $gp['price'], 2)) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($r['groups'])): ?><tr><td colspan="2" class="text-secondary small">No group prices.</td></tr><?php endif; ?>
                </tbody></table>
                <form method="post" action="<?= $groupUrl ?>" class="row g-1">
                    <?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= esc($v['id'], 'attr') ?>">
                    <div class="col-6"><select name="customer_group_id" class="form-select form-select-sm"><?php foreach ($groups as $cg): ?><option value="<?= esc($cg['id'], 'attr') ?>"><?= esc($cg['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><input name="price" type="number" step="0.01" class="form-control form-control-sm" placeholder="Price ₹" required></div>
                    <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Set group price</button></div>
                </form>
            </div>
        </div>
    </div></div>
<?php endforeach; ?>
<?php if (empty($rows)): ?><div class="card"><div class="card-body text-secondary text-center py-4">No variants yet.</div></div><?php endif; ?>
