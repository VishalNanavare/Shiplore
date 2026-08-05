<?= $this->extend('monline/_layout') ?>
<?= $this->section('content') ?>
<?php
// Single source of truth for filter state, keyed by query-string name — mirrors
// store/products.php's $applied.
$applied = array_filter([
    'q'            => $filters['q'] ?? '',
    'category'     => $filters['category'] ?? '',
    'manufacturer' => ! empty($filters['manufacturer_id']) ? (int) $filters['manufacturer_id'] : '',
], static fn ($v) => $v !== '' && $v !== null);

$action    = site_url('monline/browse');
$mk        = static fn (array $over = []) => $action . '?' . http_build_query(array_filter(array_merge($applied, $over), static fn ($v) => $v !== '' && $v !== null));
$pages     = max(1, (int) ($pages ?? 1));
$page      = max(1, (int) ($page ?? 1));
$activeCat = (string) ($applied['category'] ?? '');

// With no categories AND no manufacturers the sidebar is a pair of cards containing
// nothing but a search box — an empty shell. Drop it and give the results the width.
$hasFacets = ($navCategories ?? []) !== [] || ($manufacturers ?? []) !== [];

// Active-filter chips: label => URL that removes it.
$chips = [];
if ($activeCat !== '') {
    $cn = $activeCat;
    foreach (($navCategories ?? []) as $c) { if ((string) $c['slug'] === $activeCat) { $cn = (string) $c['name']; break; } }
    $chips['Category: ' . $cn] = $mk(['category' => '', 'page' => '']);
}
if (! empty($applied['manufacturer'])) {
    $mn = 'Manufacturer';
    foreach (($manufacturers ?? []) as $mf) { if ((int) $mf['id'] === (int) $applied['manufacturer']) { $mn = (string) $mf['display_name']; break; } }
    $chips['Manufacturer: ' . $mn] = $mk(['manufacturer' => '', 'page' => '']);
}
if (($applied['q'] ?? '') !== '') { $chips['Search: ' . $applied['q']] = $mk(['q' => '', 'page' => '']); }
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h5 mb-0">Wholesale catalogue</h1>
    <?php if ($hasFacets): ?>
        <button class="btn btn-sm btn-outline-secondary d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#filterCol" aria-expanded="false" aria-controls="filterCol"><i class="bi bi-funnel me-1"></i>Filters</button>
    <?php endif; ?>
</div>

<div class="row g-3">
    <?php if ($hasFacets): ?>
    <div class="col-lg-3">
        <div class="collapse d-lg-block" id="filterCol">

            <?php // Categories: link-driven, with counts (mirrors _store_filters.php) ?>
            <div class="card mb-3"><div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Categories</h2>
                    <?php if ($activeCat !== ''): ?><a href="<?= esc($mk(['category' => '', 'page' => '']), 'attr') ?>" class="small text-decoration-none">Clear</a><?php endif; ?>
                </div>
                <div class="list-group list-group-flush mo-filter-cats">
                    <a href="<?= esc($mk(['category' => '', 'page' => '']), 'attr') ?>" class="list-group-item list-group-item-action border-0 px-2 <?= $activeCat === '' ? 'active' : '' ?>">All categories</a>
                    <?php foreach (($navCategories ?? []) as $c): ?>
                        <a href="<?= esc($mk(['category' => $c['slug'], 'page' => '']), 'attr') ?>" class="list-group-item list-group-item-action border-0 px-2 d-flex justify-content-between align-items-center <?= $activeCat === (string) $c['slug'] ? 'active' : '' ?>">
                            <span class="text-truncate"><?= esc($c['name']) ?></span>
                            <span class="badge bg-light text-secondary ms-2"><?= (int) $c['product_count'] ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (empty($navCategories)): ?><span class="text-secondary small px-2 py-1">No categories here.</span><?php endif; ?>
                </div>
            </div></div>

            <?php // Everything else in one GET form ?>
            <form method="get" action="<?= esc($action, 'attr') ?>" class="card"><div class="card-body">
                <h2 class="h6 mb-3">Filters</h2>
                <?php // preserve the category chosen outside this form ?>
                <?php if ($activeCat !== ''): ?><input type="hidden" name="category" value="<?= esc($activeCat, 'attr') ?>"><?php endif; ?>

                <label class="form-label small fw-semibold mb-1">Search</label>
                <input name="q" class="form-control form-control-sm mb-3" placeholder="Product, SKU or manufacturer" value="<?= esc($applied['q'] ?? '', 'attr') ?>">

                <?php if (! empty($manufacturers)): ?>
                    <label class="form-label small fw-semibold mb-1">Manufacturer</label>
                    <select name="manufacturer" class="form-select form-select-sm mb-3">
                        <option value="">All manufacturers</option>
                        <?php foreach ($manufacturers as $mf): ?>
                            <option value="<?= (int) $mf['id'] ?>" <?= (int) ($applied['manufacturer'] ?? 0) === (int) $mf['id'] ? 'selected' : '' ?>><?= esc($mf['display_name']) ?> (<?= (int) $mf['product_count'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <button class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel me-1"></i>Apply filters</button>
                <a href="<?= esc($action, 'attr') ?>" class="btn btn-sm btn-link w-100 mt-1">Clear all</a>
            </div></form>

            <?php if (empty($isBuyer)): ?>
                <div class="alert mo-promptbar mt-3 mb-0 small">
                    <i class="bi bi-lock-fill text-primary me-1"></i>Sign in to see wholesale prices and place orders.
                    <a class="d-block mt-2 btn btn-sm btn-primary" href="<?= site_url('login') ?>">Sign in</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?= $hasFacets ? 'col-lg-9' : 'col-12' ?>">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
            <div class="text-secondary small"><strong><?= number_format((int) $total) ?></strong> products</div>
            <?php /* Sort slot: the repository has no sort option yet, so this states the
                      active ordering instead of offering a dead control. Drop a
                      <select name="sort"> here once products() accepts one. */ ?>
            <div class="text-secondary small">
                <?php if (! empty($nearLabel)): ?>
                    <i class="bi bi-signpost-2 me-1"></i>Sorted nearest to <?= esc($nearLabel) ?>
                <?php else: ?>
                    <i class="bi bi-clock-history me-1"></i>Newest first
                <?php endif; ?>
            </div>
        </div>

        <?php if ($chips): ?>
            <div class="d-flex flex-wrap gap-1 mb-3 align-items-center">
                <?php foreach ($chips as $label => $removeUrl): ?>
                    <a href="<?= esc($removeUrl, 'attr') ?>" class="badge rounded-pill text-bg-light border mo-chip"><?= esc($label) ?> <i class="bi bi-x"></i></a>
                <?php endforeach; ?>
                <a href="<?= esc($action, 'attr') ?>" class="small text-decoration-none ms-1">Clear all</a>
            </div>
        <?php endif; ?>

        <div class="row g-2 g-md-3">
            <?php foreach ($products as $p): ?>
                <div class="col-6 col-md-4 col-xl-3"><?= view('monline/_product_card', ['p' => $p, 'showPrices' => $showPrices]) ?></div>
            <?php endforeach; ?>
            <?php if (empty($products)): ?>
                <div class="col-12 mo-empty"><i class="bi bi-search"></i>No products match your filters. Try widening your search or <a href="<?= esc($action, 'attr') ?>">clear all filters</a>.</div>
            <?php endif; ?>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="mt-4 d-flex justify-content-center"><ul class="pagination pagination-sm">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= esc($mk(['page' => $page - 1]), 'attr') ?>">&lsaquo; Prev</a></li>
                <?php
                $from = max(1, $page - 2);
                $to   = min($pages, $page + 2);
                if ($from > 1) { echo '<li class="page-item"><a class="page-link" href="' . esc($mk(['page' => 1]), 'attr') . '">1</a></li>'; if ($from > 2) { echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>'; } }
                for ($i = $from; $i <= $to; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= esc($mk(['page' => $i]), 'attr') ?>"><?= $i ?></a></li>
                <?php endfor;
                if ($to < $pages) { if ($to < $pages - 1) { echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>'; } echo '<li class="page-item"><a class="page-link" href="' . esc($mk(['page' => $pages]), 'attr') . '">' . $pages . '</a></li>'; }
                ?>
                <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= esc($mk(['page' => $page + 1]), 'attr') ?>">Next &rsaquo;</a></li>
            </ul></nav>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
