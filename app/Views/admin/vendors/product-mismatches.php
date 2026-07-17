<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$products     = $products ?? [];
$targetByType = $targetByType ?? [];
// Group products by correct_type_id for display.
$byType = [];
foreach ($products as $p) {
    $key = (int) ($p['correct_type_id'] ?? 0);
    $byType[$key][] = $p;
}
ksort($byType);
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('warning')): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <a href="<?= site_url('admin/vendors/type-mismatches') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back to mismatches</a>
    <div class="d-flex gap-2 align-items-center">
        <span class="badge text-bg-info text-dark"><?= esc($vendor['business_type'] ?? 'Unknown type') ?></span>
        <a href="<?= site_url('admin/vendors/' . $vendor['id']) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye me-1"></i>View vendor</a>
    </div>
</div>

<?php if (empty($products)): ?>
<div class="card">
    <div class="card-body text-center text-secondary py-5">
        <i class="bi bi-patch-check fs-1 text-success d-block mb-3"></i>
        <strong>No mismatched products for <?= esc($vendor['display_name'] ?? '') ?>.</strong><br>
        All products are in categories allowed by <em><?= esc($vendor['business_type'] ?? '') ?></em>.
    </div>
</div>
<?php else: ?>

<div class="alert alert-warning small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    <strong><?= count($products) ?> product(s)</strong> belong to categories not covered by
    <em><?= esc($vendor['business_type'] ?? '') ?></em>. Products with existing orders are shown but cannot be moved.
    Select products, pick a target vendor, then click <strong>Move selected</strong>.
</div>

<form method="post" action="<?= site_url('admin/vendors/' . $vendor['id'] . '/product-mismatches/reassign') ?>"
      id="reassignForm"
      onsubmit="return confirm('Move selected products to the chosen vendor?');">
    <?= csrf_field() ?>

    <?php foreach ($byType as $typeId => $typeProducts): ?>
        <?php
        $typeName    = $typeProducts[0]['correct_type'] ?? 'Unknown type';
        $typeTargets = $targetByType[$typeId] ?? [];
        ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <input type="checkbox" class="form-check-input group-check" data-group="type-<?= $typeId ?>"
                       title="Select all in this group" id="gc-<?= $typeId ?>">
                <label for="gc-<?= $typeId ?>" class="fw-semibold mb-0">
                    Should be: <span class="badge text-bg-success"><?= esc($typeName) ?></span>
                    <span class="text-secondary small fw-normal">(<?= count($typeProducts) ?> product<?= count($typeProducts) !== 1 ? 's' : '' ?>)</span>
                </label>
            </div>
            <?php if ($typeTargets !== []): ?>
            <div class="d-flex align-items-center gap-2">
                <label class="small text-secondary mb-0 text-nowrap">Move to:</label>
                <select name="target_vendor_id" class="form-select form-select-sm" style="max-width:260px">
                    <option value="">— select vendor —</option>
                    <?php foreach ($typeTargets as $tv): ?>
                        <option value="<?= (int) $tv['id'] ?>"><?= esc($tv['display_name']) ?> (#<?= (int) $tv['id'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <span class="badge text-bg-warning text-dark small"><i class="bi bi-exclamation-triangle me-1"></i>No active vendor of this type available</span>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:32px"></th>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th class="text-center">Orders</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($typeProducts as $p): ?>
                    <?php $hasOrders = (int) ($p['order_count'] ?? 0) > 0; ?>
                    <tr class="<?= $hasOrders ? 'table-secondary text-muted' : '' ?>">
                        <td>
                            <?php if (! $hasOrders): ?>
                            <input type="checkbox" name="product_ids[]" value="<?= (int) $p['id'] ?>"
                                   class="form-check-input product-check type-<?= $typeId ?>">
                            <?php else: ?>
                            <i class="bi bi-lock text-secondary" title="Has orders — cannot be moved"></i>
                            <?php endif; ?>
                        </td>
                        <td class="fw-medium"><?= esc($p['name']) ?></td>
                        <td class="small text-secondary"><code><?= esc($p['sku'] ?? '—') ?></code></td>
                        <td class="small">
                            <?php if (! empty($p['parent_category'])): ?>
                                <span class="text-secondary"><?= esc($p['parent_category']) ?> › </span>
                            <?php endif; ?>
                            <?= esc($p['category_name']) ?>
                        </td>
                        <td class="text-center">
                            <?php if ($hasOrders): ?>
                                <span class="badge text-bg-secondary"><?= (int) $p['order_count'] ?></span>
                            <?php else: ?>
                                <span class="text-secondary small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="d-flex justify-content-between align-items-center mt-3">
        <span class="small text-secondary" id="selCount">0 product(s) selected</span>
        <button type="submit" class="btn btn-warning"><i class="bi bi-arrow-right-square me-1"></i>Move selected</button>
    </div>
</form>
<?php endif; ?>

<script>
(function () {
    const form     = document.getElementById('reassignForm');
    const countEl  = document.getElementById('selCount');
    if (!form) return;

    function refreshCount() {
        const n = form.querySelectorAll('input.product-check:checked').length;
        if (countEl) countEl.textContent = n + ' product(s) selected';
    }

    // Group select-all checkboxes.
    form.querySelectorAll('.group-check').forEach(gc => {
        gc.addEventListener('change', function () {
            const group = this.dataset.group;
            form.querySelectorAll('.' + group).forEach(cb => { cb.checked = this.checked; });
            refreshCount();
        });
    });

    form.querySelectorAll('input.product-check').forEach(cb => {
        cb.addEventListener('change', refreshCount);
    });

    // On submit: validate that a target vendor is selected for every chosen product's group.
    form.addEventListener('submit', function (e) {
        const checkedProducts = form.querySelectorAll('input.product-check:checked');
        if (checkedProducts.length === 0) {
            e.preventDefault();
            alert('Select at least one product to move.');
            return;
        }
        const targetSelect = form.querySelector('select[name="target_vendor_id"]');
        if (targetSelect && !targetSelect.value) {
            e.preventDefault();
            alert('Please select a target vendor from the dropdown.');
        }
    });
})();
</script>
<?= $this->endSection() ?>
