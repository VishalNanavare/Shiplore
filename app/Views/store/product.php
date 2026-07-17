<?= $this->extend('layouts/store') ?>

<?= $this->section('head') ?>
<?php
$ld = [
    '@context' => 'https://schema.org', '@type' => 'Product',
    'name'  => $product['title'], 'sku' => $product['sku'] ?? '',
    'category' => $product['category'] ?? '',
    'brand' => ['@type' => 'Brand', 'name' => $product['brand'] ?? $product['vendor'] ?? service('settingsRepository')->brandName()],
    'description' => mb_substr(strip_tags((string) ($product['description'] ?? $product['title'])), 0, 300),
    'offers' => ['@type' => 'Offer', 'priceCurrency' => 'INR', 'price' => number_format((float) ($product['base_price'] ?? 0), 2, '.', ''), 'availability' => 'https://schema.org/InStock', 'url' => current_url()],
];
if (! empty($reviews)) {
    $avgLd = array_sum(array_map(static fn ($r) => (int) $r['rating'], $reviews)) / count($reviews);
    $ld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => round($avgLd, 1), 'reviewCount' => count($reviews)];
}
?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES) ?></script>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success py-2"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger py-2"><?= esc($m) ?></div><?php endif; ?>
<?php
$base   = (float) ($product['base_price'] ?? 0);
$mrp    = (float) ($product['mrp'] ?? 0);
$off    = $mrp > $base && $mrp > 0 ? (int) round(($mrp - $base) / $mrp * 100) : 0;
$min    = ! empty($rules['min_purchase_qty']) ? (float) $rules['min_purchase_qty'] : 1;
$step   = ! empty($rules['qty_step']) ? (float) $rules['qty_step'] : 1;
$max    = ! empty($rules['max_purchase_qty']) ? (float) $rules['max_purchase_qty'] : null;
$avg    = ! empty($reviews) ? array_sum(array_map(static fn ($r) => (int) $r['rating'], $reviews)) / count($reviews) : 0;
$imgs   = $images ?? [];
$loc    = $location ?? service('locationService')->get();
$c      = $content ?? [];
$specs  = $specs ?? [];
$vars   = $variants ?? [];
$picker = count($vars) > 1;
$rich   = static fn ($html) => strip_tags((string) $html, '<p><br><ul><ol><li><strong><em><b><i><h4><h5><h6>');
$about  = $c['full_description'] ?? '';
$short  = $c['short_description'] ?? ($product['description'] ?? '');
$keyFacts = array_filter(['Brand' => $product['brand'] ?? '', 'Category' => $product['category'] ?? '', 'Seller' => $product['vendor'] ?? '', 'SKU' => $product['sku'] ?? '']);
$hasInfo = ! empty($c['ingredients']) || ! empty($c['usage_instructions']) || ! empty($c['safety_instructions']) || ! empty($c['storage_instructions']) || ! empty($c['additional_info']);
?>
<nav class="small mb-3 text-secondary"><a href="<?= site_url('store') ?>" class="text-decoration-none">Home</a> › <a href="<?= site_url('store/category/' . $product['category_slug']) ?>" class="text-decoration-none"><?= esc($product['category'] ?? 'Catalog') ?></a> › <span class="text-dark"><?= esc($product['title']) ?></span></nav>

<div class="row g-4 mb-4">
    <!-- Gallery -->
    <div class="col-md-5">
        <div class="position-sticky" style="top:88px">
            <div class="card border-0 shadow-sm p-3">
                <div class="st-gallery mb-2 d-flex align-items-center justify-content-center" id="stGallery" style="aspect-ratio:1;background:#fff">
                    <?php if ($imgs !== []): ?>
                        <img id="stMainImg" src="<?= site_url('media/' . $imgs[0]['uuid']) ?>" alt="<?= esc($product['title'], 'attr') ?>" style="max-width:100%;max-height:100%;object-fit:contain">
                    <?php else: ?>
                        <div class="st-thumb st-ph-g<?= (int) $product['id'] % 6 ?> w-100 h-100"><span class="st-ph-letter" style="font-size:4rem"><?= esc(mb_strtoupper(mb_substr((string) $product['title'], 0, 1))) ?></span></div>
                    <?php endif; ?>
                </div>
                <?php if (count($imgs) > 1): ?>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php foreach (array_slice($imgs, 0, 6) as $im): ?>
                            <img src="<?= site_url('media/' . $im['uuid']) ?>" alt="" onclick="document.getElementById('stMainImg').src=this.src" style="width:56px;height:56px;object-fit:cover;border:1px solid #eee;border-radius:.4rem;cursor:pointer">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Buy box -->
    <div class="col-md-7">
        <?php if (! empty($product['brand'])): ?><div class="text-uppercase small fw-semibold text-primary mb-1" style="letter-spacing:.04em"><?= esc($product['brand']) ?></div><?php endif; ?>
        <h1 class="h4 mb-1"><?= esc($product['title']) ?></h1>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <?php if ($avg > 0): ?><span class="badge bg-success"><?= number_format($avg, 1) ?> ★</span><a href="#reviews" class="small text-decoration-none text-secondary"><?= count($reviews) ?> rating<?= count($reviews) === 1 ? '' : 's' ?></a><?php endif; ?>
            <span class="text-secondary small"><i class="bi bi-shop me-1"></i><?= esc($product['vendor'] ?? '') ?></span>
            <?php foreach (($labels ?? []) as $lab): ?><span class="badge text-bg-<?= esc($lab['color'] ?: 'secondary', 'attr') ?>"><?= esc($lab['name']) ?></span><?php endforeach; ?>
        </div>

        <!-- Delivery promise (Blinkit-style) -->
        <div class="d-inline-flex align-items-center gap-2 px-3 py-2 mb-3 rounded" style="background:#f4f7ff">
            <i class="bi bi-lightning-charge-fill text-primary fs-5"></i>
            <div class="small lh-sm">
                <div class="fw-semibold">Fast delivery</div>
                <div class="text-secondary"><?= $loc ? 'to ' . esc($loc['label']) : '<a href="#" onclick="openLocationPicker&&openLocationPicker();return false;">Set your location</a>' ?></div>
            </div>
        </div>

        <?php if ($picker): ?>
            <div class="mb-3">
                <label class="form-label small fw-semibold mb-1">Select option <span class="text-secondary fw-normal">(<?= count($vars) ?>)</span></label>
                <div class="st-variants" id="stVariants">
                    <?php foreach ($vars as $i => $v): ?>
                        <button type="button" class="st-variant<?= $i === 0 ? ' active' : '' ?>"
                                data-variant-id="<?= esc($v['variant_id'], 'attr') ?>"
                                data-price="<?= esc($v['base_price'], 'attr') ?>"
                                data-mrp="<?= esc($v['mrp'] ?? 0, 'attr') ?>">
                            <span class="st-variant-label"><?= esc($v['label'] ?: $v['sku']) ?></span>
                            <span class="st-variant-price">₹<?= esc(number_format((float) $v['base_price'], 0)) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-3"><div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <div class="d-flex align-items-baseline gap-2">
                    <span class="h3 mb-0 fw-bold" id="stPrice">₹<?= esc(number_format($base, 0)) ?></span>
                    <span class="text-secondary text-decoration-line-through" id="stMrp" style="<?= $off > 0 ? '' : 'display:none' ?>">₹<?= esc(number_format($mrp ?: $base, 0)) ?></span>
                    <span class="badge bg-success" id="stOff" style="<?= $off > 0 ? '' : 'display:none' ?>"><?= $off ?>% OFF</span>
                </div>
                <div class="text-secondary small">Inclusive of all taxes</div>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <?php if (! empty($product['variant_id']) || $picker): ?>
                    <form method="post" action="<?= site_url('store/cart/add') ?>" class="d-flex gap-2 align-items-center" data-ajax-stay><?= csrf_field() ?>
                        <input type="hidden" name="variant_id" id="stVarId" value="<?= esc($picker ? $vars[0]['variant_id'] : $product['variant_id'], 'attr') ?>">
                        <input name="qty" type="number" min="<?= esc($min, 'attr') ?>" step="<?= esc($step, 'attr') ?>" <?= $max ? 'max="' . esc($max, 'attr') . '"' : '' ?> value="<?= esc($min, 'attr') ?>" class="form-control form-control-sm" style="width:74px">
                        <button class="btn btn-success btn-lg px-4 fw-semibold"><i class="bi bi-bag-plus me-1"></i>Add</button>
                    </form>
                <?php endif; ?>
                <form method="post" action="<?= site_url('store/wishlist/add') ?>" data-ajax-stay><?= csrf_field() ?><input type="hidden" name="product_id" value="<?= esc($product['id'], 'attr') ?>"><button class="btn btn-outline-secondary btn-lg" title="Save"><i class="bi bi-heart"></i></button></form>
            </div>
        </div></div>

        <?php if (! empty($highlights)): ?>
            <h2 class="h6 mb-2">Highlights</h2>
            <ul class="st-highlights small mb-3"><?php foreach (array_slice($highlights, 0, 6) as $h): ?><li><?= esc($h) ?></li><?php endforeach; ?></ul>
        <?php elseif (! empty($short)): ?>
            <p class="text-secondary mb-3"><?= esc(mb_substr(strip_tags((string) $short), 0, 240)) ?></p>
        <?php endif; ?>

        <div class="row g-2 text-secondary" style="font-size:.78rem">
            <div class="col-6 col-md-3"><i class="bi bi-lightning-charge me-1 text-primary"></i>Fast delivery</div>
            <div class="col-6 col-md-3"><i class="bi bi-arrow-repeat me-1 text-primary"></i>Easy returns</div>
            <div class="col-6 col-md-3"><i class="bi bi-shield-check me-1 text-primary"></i>Secure payments</div>
            <div class="col-6 col-md-3"><i class="bi bi-patch-check me-1 text-primary"></i>100% genuine</div>
        </div>
    </div>
</div>

<!-- Details (expandable, Blinkit-style) -->
<div class="card border-0 shadow-sm mb-4"><div class="card-body p-0">
    <ul class="nav nav-tabs px-3 pt-2" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details" type="button">Product details</button></li>
        <?php if ($specs !== [] || $keyFacts !== []): ?><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-specs" type="button">Specifications</button></li><?php endif; ?>
        <?php if ($hasInfo): ?><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-info" type="button">More info</button></li><?php endif; ?>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-reviews" type="button">Reviews<?= $reviews ? ' (' . count($reviews) . ')' : '' ?></button></li>
        <?php if (! empty($faqs)): ?><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-faqs" type="button">FAQs</button></li><?php endif; ?>
    </ul>
    <div class="tab-content p-3 p-md-4">
        <div class="tab-pane fade show active" id="tab-details">
            <?php if (! empty($about)): ?><div class="st-prose"><?= $rich($about) ?></div>
            <?php elseif (! empty($short)): ?><p class="mb-0"><?= esc($short) ?></p>
            <?php else: ?><p class="text-secondary mb-0">No description provided yet.</p><?php endif; ?>
        </div>
        <?php if ($specs !== [] || $keyFacts !== []): ?>
        <div class="tab-pane fade" id="tab-specs">
            <table class="table table-sm st-spec-table mb-0"><tbody>
                <?php foreach ($keyFacts as $k => $v): ?><tr><th class="text-secondary fw-normal" style="width:40%"><?= esc($k) ?></th><td class="fw-medium"><?= esc($v) ?></td></tr><?php endforeach; ?>
                <?php foreach ($specs as $s): if (($s['value'] ?? '') === '') { continue; } ?><tr><th class="text-secondary fw-normal"><?= esc($s['name']) ?></th><td class="fw-medium"><?= esc($s['value']) ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
        <?php endif; ?>
        <?php if ($hasInfo): ?>
        <div class="tab-pane fade" id="tab-info">
            <?php foreach (['ingredients' => 'Ingredients', 'usage_instructions' => 'How to use', 'safety_instructions' => 'Safety', 'storage_instructions' => 'Storage', 'additional_info' => 'Additional information'] as $key => $heading): ?>
                <?php if (! empty($c[$key])): ?><h3 class="h6 mb-1"><?= $heading ?></h3><div class="text-secondary mb-3"><?= $rich($c[$key]) ?></div><?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="tab-pane fade" id="tab-reviews">
            <div id="reviews"></div>
            <?php if ($avg > 0): ?><div class="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom"><div class="text-center"><div class="display-6 fw-bold"><?= number_format($avg, 1) ?></div><div class="st-rating"><?php for ($i = 1; $i <= 5; $i++) { echo $i <= round($avg) ? '★' : '☆'; } ?></div><div class="small text-secondary"><?= count($reviews) ?> rating<?= count($reviews) === 1 ? '' : 's' ?></div></div></div><?php endif; ?>
            <?php foreach ($reviews as $r): ?>
                <div class="border-bottom pb-2 mb-2"><div class="st-rating"><?php for ($i = 1; $i <= 5; $i++) { echo $i <= (int) $r['rating'] ? '★' : '☆'; } ?> <span class="text-secondary small ms-1"><?= esc($r['author'] ?? 'Customer') ?></span></div><?php if (! empty($r['title'])): ?><div class="fw-medium small"><?= esc($r['title']) ?></div><?php endif; ?><div class="small text-secondary"><?= esc($r['body'] ?? '') ?></div></div>
            <?php endforeach; ?>
            <?php if (empty($reviews)): ?><div class="text-secondary small">No reviews yet — be the first to review.</div><?php endif; ?>
            <?php if (session()->get('customer_id')): ?>
                <form method="post" action="<?= site_url('store/product/' . $product['id'] . '/review') ?>" class="mt-3"><?= csrf_field() ?>
                    <div class="row g-2">
                        <div class="col-md-3"><select name="rating" class="form-select form-select-sm"><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?> ★</option><?php endfor; ?></select></div>
                        <div class="col-md-9"><input name="title" class="form-control form-control-sm" placeholder="Review title"></div>
                        <div class="col-12"><textarea name="body" class="form-control form-control-sm" rows="2" placeholder="Share your experience…"></textarea></div>
                        <div class="col-12"><button class="btn btn-sm btn-primary">Submit review</button></div>
                    </div>
                </form>
            <?php else: ?><p class="small text-secondary mt-3 mb-0"><a href="<?= site_url('store/login') ?>">Sign in</a> to write a review.</p><?php endif; ?>
        </div>
        <?php if (! empty($faqs)): ?>
        <div class="tab-pane fade" id="tab-faqs">
            <div class="accordion accordion-flush" id="pfaq">
                <?php foreach ($faqs as $i => $f): ?><div class="accordion-item"><h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= $i ?>"><?= esc($f['question']) ?></button></h3><div id="faq<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#pfaq"><div class="accordion-body small text-secondary"><?= esc($f['answer']) ?></div></div></div><?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div></div>

<!-- Similar products — nearby only, horizontal rail -->
<?php if (! empty($related)): ?>
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="st-section-title mb-0">Similar products near you</h2>
        <a href="<?= site_url('store/category/' . $product['category_slug']) ?>" class="small text-decoration-none">View all <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="st-rail">
        <?php foreach (array_slice($related, 0, 12) as $p): ?><div class="st-rail-item"><?= view('partials/_store_product_card', ['p' => $p]) ?></div><?php endforeach; ?>
    </div>
    <style>
    .st-rail{display:flex;gap:.75rem;overflow-x:auto;scroll-snap-type:x mandatory;padding:.25rem .1rem 1rem}
    .st-rail::-webkit-scrollbar{height:6px}.st-rail::-webkit-scrollbar-thumb{background:#d4d7e0;border-radius:6px}
    .st-rail-item{flex:0 0 auto;width:170px;scroll-snap-align:start}@media(min-width:768px){.st-rail-item{width:190px}}
    </style>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if ($picker): ?>
<script>
(function () {
    var wrap = document.getElementById('stVariants'); if (!wrap) { return; }
    var hid = document.getElementById('stVarId');
    var fmt = function (n) { return '₹' + Math.round(n).toLocaleString('en-IN'); };
    wrap.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.st-variant') : null; if (!b) { return; }
        wrap.querySelectorAll('.st-variant').forEach(function (x) { x.classList.remove('active'); });
        b.classList.add('active');
        if (hid) { hid.value = b.getAttribute('data-variant-id'); }
        var p = parseFloat(b.getAttribute('data-price')) || 0, m = parseFloat(b.getAttribute('data-mrp')) || 0;
        var priceEl = document.getElementById('stPrice'); if (priceEl) { priceEl.textContent = fmt(p); }
        var mrpEl = document.getElementById('stMrp'), offEl = document.getElementById('stOff');
        if (m > p) { mrpEl.textContent = fmt(m); mrpEl.style.display = ''; offEl.textContent = Math.round((m - p) / m * 100) + '% OFF'; offEl.style.display = ''; }
        else { if (mrpEl) { mrpEl.style.display = 'none'; } if (offEl) { offEl.style.display = 'none'; } }
    });
})();
</script>
<?php endif; ?>
<?= $this->endSection() ?>
