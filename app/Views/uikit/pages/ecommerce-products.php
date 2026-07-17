<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="row g-3">
    <!-- Filters -->
    <div class="col-lg-3">
        <div class="card uk-card"><div class="card-body">
            <h2 class="uk-section-title mb-3">Filters</h2>
            <div class="mb-3"><label class="form-label small">Search</label><input class="form-control form-control-sm" placeholder="Product name…"></div>
            <div class="mb-3"><label class="form-label small">Category</label>
                <?php foreach (['Apparel','Grocery','Electronics','Footwear'] as $i=>$c): ?>
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="cat<?= $i ?>" <?= $i<2?'checked':'' ?>><label class="form-check-label small" for="cat<?= $i ?>"><?= $c ?></label></div>
                <?php endforeach; ?>
            </div>
            <div class="mb-3"><label class="form-label small">Max price: ₹5,000</label><input type="range" class="form-range" value="60"></div>
            <button class="btn btn-sm btn-primary w-100">Apply</button>
        </div></div>
    </div>
    <!-- Grid -->
    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="text-secondary small">Showing 8 of 124 products</span>
            <select class="form-select form-select-sm w-auto"><option>Sort: Popular</option><option>Price: Low to High</option><option>Newest</option></select>
        </div>
        <div class="row g-3">
            <?php
            $prods = [
                ['Wireless Earbuds','Electronics','₹1,999','★ 4.6','danger','box-seam'],
                ['Running Shoes','Footwear','₹2,499','★ 4.4','primary','bag'],
                ['Smart Watch','Electronics','₹4,999','★ 4.8','success','smartwatch'],
                ['Cotton T-Shirt','Apparel','₹699','★ 4.2','info','bag-heart'],
                ['Coffee Beans 1kg','Grocery','₹899','★ 4.7','warning','cup-hot'],
                ['Backpack','Apparel','₹1,299','★ 4.3','secondary','backpack'],
                ['Bluetooth Speaker','Electronics','₹2,199','★ 4.5','danger','speaker'],
                ['Yoga Mat','Sports','₹799','★ 4.1','success','dribbble'],
            ];
            foreach ($prods as $p): ?>
                <div class="col-6 col-md-4 col-xl-3">
                    <div class="card uk-card h-100">
                        <div style="height:130px;background:#fbfcfe" class="d-grid border-bottom"><i class="bi bi-<?= $p[5] ?> align-self-center text-center text-<?= $p[4] ?>" style="font-size:2.6rem"></i></div>
                        <div class="card-body p-3">
                            <div class="text-secondary" style="font-size:.7rem"><?= $p[1] ?></div>
                            <a href="<?= site_url('ui-kit/ecommerce-product-view') ?>" class="d-block fw-medium small text-truncate text-reset text-decoration-none"><?= $p[0] ?></a>
                            <div class="uk-rating small"><?= $p[3] ?></div>
                            <div class="d-flex justify-content-between align-items-center mt-1">
                                <span class="fw-semibold"><?= $p[2] ?></span>
                                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-cart-plus"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <nav class="mt-3"><ul class="pagination justify-content-center mb-0">
            <li class="page-item disabled"><a class="page-link" href="#"><i class="bi bi-chevron-left"></i></a></li>
            <li class="page-item active"><a class="page-link" href="#">1</a></li>
            <li class="page-item"><a class="page-link" href="#">2</a></li>
            <li class="page-item"><a class="page-link" href="#">3</a></li>
            <li class="page-item"><a class="page-link" href="#"><i class="bi bi-chevron-right"></i></a></li>
        </ul></nav>
    </div>
</div>
<?= $this->endSection() ?>
