<?= $this->extend('monline/_layout') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mo-section-title mb-0">Wholesale catalogue <span class="text-secondary fw-normal small">(<?= (int) $total ?> products)</span></h2>
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
                <?= view('monline/_product_card', ['p' => $p, 'showPrices' => $showPrices]) ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?= $this->endSection() ?>
