<?php
/**
 * Storefront faceted filter sidebar (reused by store/products + store/shop).
 * @var string $action       base URL the filters submit to
 * @var array  $applied      active filter params (keys = form field names)
 * @var array  $catFacets    [{id,name,slug,level,cnt}] categories w/ counts (desc)
 * @var array  $brandFacets  [{id,name,cnt}]
 * @var array  $typeFacets   [{type,cnt}]
 * @var array  $priceBounds  ['lo'=>float,'hi'=>float]
 */
$applied = $applied ?? [];
$url = static function (array $over = []) use ($action, $applied) {
    $p = array_filter(array_merge($applied, $over), static fn ($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');

    return $action . ($p ? '?' . http_build_query($p) : '');
};
$activeCat = (string) ($applied['category'] ?? '');
?>
<!-- Categories (dynamic, with available-product counts, most first) -->
<div class="card mb-3"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Categories</h2>
        <?php if ($activeCat !== ''): ?><a href="<?= esc($url(['category' => '', 'page' => '']), 'attr') ?>" class="small text-decoration-none">Clear</a><?php endif; ?>
    </div>
    <div class="list-group list-group-flush st-filter-cats" style="max-height:340px;overflow:auto">
        <a href="<?= esc($url(['category' => '', 'page' => '']), 'attr') ?>" class="list-group-item list-group-item-action border-0 px-2 <?= $activeCat === '' ? 'active' : '' ?>">All categories</a>
        <?php foreach ($catFacets as $c): ?>
            <a href="<?= esc($url(['category' => $c['slug'], 'page' => '']), 'attr') ?>" class="list-group-item list-group-item-action border-0 px-2 d-flex justify-content-between align-items-center <?= $activeCat === $c['slug'] ? 'active' : '' ?>" style="padding-left:<?= ((int) $c['level'] + 1) * .5 ?>rem!important">
                <span class="text-truncate"><?= esc($c['name']) ?></span>
                <span class="badge bg-light text-secondary ms-2"><?= (int) $c['cnt'] ?></span>
            </a>
        <?php endforeach; ?>
        <?php if (empty($catFacets)): ?><span class="text-secondary small px-2 py-1">No categories here.</span><?php endif; ?>
    </div>
</div></div>

<!-- All other filters in one GET form -->
<form method="get" action="<?= esc($action, 'attr') ?>" class="card"><div class="card-body">
    <h2 class="h6 mb-3">Filters</h2>
    <?php // preserve context that lives outside this form ?>
    <?php if (($applied['category'] ?? '') !== ''): ?><input type="hidden" name="category" value="<?= esc($applied['category'], 'attr') ?>"><?php endif; ?>
    <?php if (($applied['q'] ?? '') !== ''): ?><input type="hidden" name="q" value="<?= esc($applied['q'], 'attr') ?>"><?php endif; ?>
    <?php if (($applied['sort'] ?? '') !== ''): ?><input type="hidden" name="sort" value="<?= esc($applied['sort'], 'attr') ?>"><?php endif; ?>

    <label class="form-label small fw-semibold mb-1">Price (₹)</label>
    <?php if ((float) ($priceBounds['hi'] ?? 0) > 0): ?><div class="text-secondary mb-1" style="font-size:.72rem">In stock: ₹<?= number_format((float) $priceBounds['lo']) ?> – ₹<?= number_format((float) $priceBounds['hi']) ?></div><?php endif; ?>
    <div class="d-flex gap-2 mb-3">
        <input type="number" name="min_price" class="form-control form-control-sm" placeholder="Min" min="0" value="<?= esc($applied['min_price'] ?? '', 'attr') ?>">
        <input type="number" name="max_price" class="form-control form-control-sm" placeholder="Max" min="0" value="<?= esc($applied['max_price'] ?? '', 'attr') ?>">
    </div>

    <?php if (! empty($brandFacets)): ?>
        <label class="form-label small fw-semibold mb-1">Brand</label>
        <select name="brand" class="form-select form-select-sm mb-3">
            <option value="">All brands</option>
            <?php foreach ($brandFacets as $bf): ?><option value="<?= (int) $bf['id'] ?>" <?= (int) ($applied['brand'] ?? 0) === (int) $bf['id'] ? 'selected' : '' ?>><?= esc($bf['name']) ?> (<?= (int) $bf['cnt'] ?>)</option><?php endforeach; ?>
        </select>
    <?php endif; ?>

    <?php if (! empty($typeFacets)): ?>
        <label class="form-label small fw-semibold mb-1">Product type</label>
        <select name="type" class="form-select form-select-sm mb-3">
            <option value="">All types</option>
            <?php foreach ($typeFacets as $tf): ?><option value="<?= esc($tf['type'], 'attr') ?>" <?= ($applied['type'] ?? '') === $tf['type'] ? 'selected' : '' ?>><?= esc(ucfirst((string) $tf['type'])) ?> (<?= (int) $tf['cnt'] ?>)</option><?php endforeach; ?>
        </select>
    <?php endif; ?>

    <div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" name="in_stock" value="1" id="fIn" <?= ! empty($applied['in_stock']) ? 'checked' : '' ?>><label class="form-check-label small" for="fIn">In stock only</label></div>
    <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="on_offer" value="1" id="fOff" <?= ! empty($applied['on_offer']) ? 'checked' : '' ?>><label class="form-check-label small" for="fOff">On offer</label></div>

    <button class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel me-1"></i>Apply filters</button>
    <a href="<?= esc($action, 'attr') ?>" class="btn btn-sm btn-link w-100 mt-1">Clear all</a>
</div></form>
