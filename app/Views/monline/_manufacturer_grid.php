<?php
/**
 * Featured-manufacturer grid. Extracted so home.php can render it in either of two
 * positions: promoted directly under the hero when there are suppliers but nothing
 * published yet, or in its usual slot once the catalogue has products.
 *
 * @var list<array<string,mixed>> $manufacturers
 * @var string                    $heading
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mo-section-title mb-0"><?= esc($heading) ?></h2>
    <a href="<?= site_url('monline/browse') ?>" class="small text-decoration-none">Browse their products <i class="bi bi-arrow-right"></i></a>
</div>
<div class="row g-3 mb-4">
    <?php foreach (array_slice($manufacturers, 0, 6) as $m): ?>
        <div class="col-6 col-md-4 col-lg-2"><a href="<?= site_url('monline/browse') . '?manufacturer=' . (int) $m['id'] ?>" class="card mo-mfrcard h-100 text-decoration-none">
            <div class="card-body p-3">
                <?php // Gradient monogram rather than a constant building glyph — the same
                      // .mo-ph-g* ramp the product monograms use, so the grid carries colour. ?>
                <div class="mo-mfr-ico mo-ph-g<?= (int) $m['id'] % 6 ?> mb-2"><?= esc(mb_strtoupper(mb_substr((string) $m['display_name'], 0, 1))) ?></div>
                <div class="fw-semibold small text-truncate"><?= esc($m['display_name']) ?></div>
                <div class="mt-2 d-flex flex-wrap gap-1">
                    <span class="badge mo-count"><i class="bi bi-box-seam me-1"></i><?= (int) $m['product_count'] ?> products</span>
                </div>
            </div>
        </a></div>
    <?php endforeach; ?>
</div>
