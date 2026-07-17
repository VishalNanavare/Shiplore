<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="card uk-card mb-4"><div class="card-body text-center p-4" style="background:linear-gradient(120deg,#eef0fe,#fff)">
    <h2 class="h4">How can we help?</h2>
    <div class="input-group mx-auto mt-3" style="max-width:480px"><span class="input-group-text"><i class="bi bi-search"></i></span><input class="form-control form-control-lg" placeholder="Search articles…"></div>
    <div class="text-secondary small mt-2">Popular: GST setup, payouts, POS sync</div>
</div></div>

<div class="row g-3">
    <?php
    $cats = [
        ['Getting Started','rocket','primary',6],['Payments & Payouts','cash-stack','success',9],
        ['GST & Taxes','receipt','warning',7],['Products & Catalog','box-seam','info',12],
        ['Orders & Shipping','truck','danger',8],['POS & Offline','pc-display','secondary',5],
    ];
    foreach ($cats as $c): ?>
        <div class="col-sm-6 col-lg-4">
            <div class="card uk-card h-100"><div class="card-body">
                <div class="uk-stat-icon bg-<?= $c[2] ?>-subtle text-<?= $c[2] ?> mb-3"><i class="bi bi-<?= $c[1] ?>"></i></div>
                <h3 class="h6 mb-1"><?= $c[0] ?></h3>
                <p class="text-secondary small mb-2"><?= $c[3] ?> articles</p>
                <a href="#" class="small">Browse <i class="bi bi-arrow-right"></i></a>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card uk-card mt-3"><div class="card-body">
    <h2 class="uk-section-title mb-3">Popular articles</h2>
    <?php foreach (['How do I verify my GSTIN?','When will I receive my payout?','Setting up offline POS sync','Bulk importing products via CSV','Configuring delivery radius'] as $a): ?>
        <a href="#" class="d-flex align-items-center justify-content-between py-2 border-bottom text-reset text-decoration-none"><span><i class="bi bi-file-text me-2 text-secondary"></i><?= $a ?></span><i class="bi bi-chevron-right text-secondary"></i></a>
    <?php endforeach; ?>
</div></div>
<?= $this->endSection() ?>
