<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="uk-section-title mb-0">My Wishlist <span class="text-secondary">(6 items)</span></h2>
    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-cart-plus me-1"></i>Move all to cart</button>
</div>
<div class="row g-3">
    <?php
    $items = [
        ['Wireless Earbuds','₹1,999','₹3,499','box-seam','danger',true],
        ['Smart Watch','₹4,999','','smartwatch','success',true],
        ['Running Shoes','₹2,499','₹2,999','bag','primary',true],
        ['Bluetooth Speaker','₹2,199','','speaker','danger',false],
        ['Backpack','₹1,299','₹1,799','backpack','secondary',true],
        ['Coffee Beans 1kg','₹899','','cup-hot','warning',true],
    ];
    foreach ($items as $p): ?>
        <div class="col-6 col-md-4 col-xl-3">
            <div class="card uk-card h-100">
                <div class="position-relative">
                    <div style="height:140px;background:#fbfcfe" class="d-grid border-bottom"><i class="bi bi-<?= $p[3] ?> align-self-center text-center text-<?= $p[4] ?>" style="font-size:2.8rem"></i></div>
                    <button class="btn btn-sm btn-light position-absolute top-0 end-0 m-2 text-danger"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="card-body p-3 d-flex flex-column">
                    <div class="fw-medium small text-truncate"><?= $p[0] ?></div>
                    <div class="mb-2"><span class="fw-semibold"><?= $p[1] ?></span> <?php if($p[2]):?><span class="text-secondary text-decoration-line-through small"><?= $p[2] ?></span><?php endif;?></div>
                    <div class="mt-auto">
                        <?php if ($p[5]): ?>
                            <button class="btn btn-sm btn-primary w-100"><i class="bi bi-cart-plus me-1"></i>Add to cart</button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-outline-secondary w-100" disabled>Out of stock</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?= $this->endSection() ?>
