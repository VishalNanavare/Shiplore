<?= $this->extend('monline/_layout') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Wholesale catalogue <span class="text-secondary fw-normal small">(<?= (int) $total ?> products)</span></h5>
</div>

<form method="get" action="<?= site_url('monline/browse') ?>" class="row g-2 mb-4">
    <div class="col-md-5">
        <input name="q" class="form-control" placeholder="Search products, SKU or manufacturer…" value="<?= esc($filters['q'] ?? '', 'attr') ?>">
    </div>
    <div class="col-md-4">
        <select name="manufacturer" class="form-select">
            <option value="">All manufacturers</option>
            <?php foreach ($manufacturers as $m): ?>
                <option value="<?= (int) $m['id'] ?>" <?= (int) ($filters['manufacturer_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>>
                    <?= esc($m['display_name']) ?> (<?= (int) $m['product_count'] ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3"><button class="btn btn-primary w-100">Search</button></div>
</form>

<?php if (empty($products)): ?>
    <div class="card"><div class="card-body text-center text-secondary py-5">No products match that search.</div></div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($products as $p): ?>
            <div class="col-sm-6 col-lg-4 col-xl-3">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="small text-secondary mb-1"><?= esc($p['manufacturer'] ?? '') ?></div>
                        <a class="fw-semibold text-decoration-none mb-1" href="<?= site_url('monline/product/' . rawurlencode((string) $p['slug'])) ?>">
                            <?= esc($p['title'] ?? '') ?>
                        </a>
                        <div class="small text-secondary mb-2"><?= esc($p['category'] ?? '') ?></div>

                        <?php
                        /*
                         * Prices only exist in $p when the controller opted in for a
                         * signed-in buyer. For a logged-out visitor the key is absent —
                         * not zero, not hidden — so there is nothing to leak here.
                         */
                        ?>
                        <?php if (! empty($showPrices) && isset($p['base_price'])): ?>
                            <div class="fs-5 fw-semibold text-primary mt-auto">₹<?= esc(number_format((float) $p['base_price'], 2)) ?></div>
                            <?php if (! empty($p['min_purchase_qty']) && (float) $p['min_purchase_qty'] > 1): ?>
                                <div class="small text-secondary">Min order <?= esc(rtrim(rtrim(number_format((float) $p['min_purchase_qty'], 3), '0'), '.')) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="mt-auto">
                                <a class="btn btn-sm btn-outline-primary w-100" href="<?= site_url('login') ?>">Sign in to see price</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?= $this->endSection() ?>
