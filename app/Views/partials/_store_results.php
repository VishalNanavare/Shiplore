<?php
/**
 * Storefront results column: sort bar, active-filter chips, product grid, pagination.
 * @var array  $products
 * @var int    $total
 * @var int    $page
 * @var int    $perPage
 * @var array  $applied   active filter params (keys = query string names)
 * @var string $action    base URL for sort/pagination links
 * @var array  $brandFacets  (to resolve a brand id -> name for the chip)
 */
$applied = $applied ?? [];
$perPage = max(1, (int) ($perPage ?? 24));
$total   = (int) ($total ?? count($products));
$pages   = max(1, (int) ceil($total / $perPage));
$page    = max(1, (int) ($page ?? 1));
$mk      = static fn (array $over = []) => $action . '?' . http_build_query(array_filter(array_merge($applied, $over), static fn ($v) => $v !== '' && $v !== null));

// Active filter chips (label => URL that removes it)
$chips = [];
if (($applied['category'] ?? '') !== '') { $chips['Category: ' . $applied['category']] = $mk(['category' => '', 'page' => '']); }
if (($applied['type'] ?? '') !== '')     { $chips['Type: ' . ucfirst((string) $applied['type'])] = $mk(['type' => '', 'page' => '']); }
if (! empty($applied['brand'])) {
    $bn = 'Brand';
    foreach (($brandFacets ?? []) as $bf) { if ((int) $bf['id'] === (int) $applied['brand']) { $bn = $bf['name']; break; } }
    $chips['Brand: ' . $bn] = $mk(['brand' => '', 'page' => '']);
}
if (($applied['min_price'] ?? '') !== '' || ($applied['max_price'] ?? '') !== '') { $chips['Price: ₹' . ($applied['min_price'] ?? '0') . '–' . ($applied['max_price'] ?? '∞')] = $mk(['min_price' => '', 'max_price' => '', 'page' => '']); }
if (! empty($applied['in_stock'])) { $chips['In stock'] = $mk(['in_stock' => '', 'page' => '']); }
if (! empty($applied['on_offer'])) { $chips['On offer'] = $mk(['on_offer' => '', 'page' => '']); }
?>
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <div class="text-secondary small"><strong><?= number_format($total) ?></strong> products</div>
    <form method="get" action="<?= esc($action, 'attr') ?>" class="d-flex gap-2">
        <?php foreach ($applied as $k => $v): if ($k === 'sort' || $k === 'page') { continue; } ?><input type="hidden" name="<?= esc($k, 'attr') ?>" value="<?= esc($v, 'attr') ?>"><?php endforeach; ?>
        <select name="sort" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
            <?php foreach (['' => 'Sort: Relevance', 'price_asc' => 'Price: Low to High', 'price_desc' => 'Price: High to Low', 'name' => 'Name (A–Z)'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= ($applied['sort'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if ($chips): ?>
    <div class="d-flex flex-wrap gap-1 mb-3 align-items-center">
        <?php foreach ($chips as $label => $removeUrl): ?>
            <a href="<?= esc($removeUrl, 'attr') ?>" class="badge rounded-pill text-bg-light border text-decoration-none"><?= esc($label) ?> <i class="bi bi-x"></i></a>
        <?php endforeach; ?>
        <a href="<?= esc($action, 'attr') ?>" class="small text-decoration-none ms-1">Clear all</a>
    </div>
<?php endif; ?>

<div class="row g-2 g-md-3">
    <?php foreach ($products as $p): ?><div class="col-6 col-md-4 col-xl-3"><?= view('partials/_store_product_card', ['p' => $p]) ?></div><?php endforeach; ?>
    <?php if (empty($products)): ?><div class="col-12 st-empty"><i class="bi bi-search"></i>No products match your filters. Try widening your search or <a href="<?= esc($action, 'attr') ?>">clear all filters</a>.</div><?php endif; ?>
</div>

<?php if ($pages > 1): ?>
    <nav class="mt-4 d-flex justify-content-center"><ul class="pagination pagination-sm">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= esc($mk(['page' => $page - 1]), 'attr') ?>">‹ Prev</a></li>
        <?php
        $from = max(1, $page - 2);
        $to   = min($pages, $page + 2);
        if ($from > 1) { echo '<li class="page-item"><a class="page-link" href="' . esc($mk(['page' => 1]), 'attr') . '">1</a></li>'; if ($from > 2) { echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; } }
        for ($i = $from; $i <= $to; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= esc($mk(['page' => $i]), 'attr') ?>"><?= $i ?></a></li>
        <?php endfor;
        if ($to < $pages) { if ($to < $pages - 1) { echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; } echo '<li class="page-item"><a class="page-link" href="' . esc($mk(['page' => $pages]), 'attr') . '">' . $pages . '</a></li>'; }
        ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= esc($mk(['page' => $page + 1]), 'attr') ?>">Next ›</a></li>
    </ul></nav>
<?php endif; ?>
