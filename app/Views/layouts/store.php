<?php
$brand     = service('settingsRepository')->brandName();
$metaTitle = $metaTitle ?? (($title ?? 'Shop') . ' · ' . $brand);
$metaDesc  = $metaDesc ?? 'Shop from nearby stores on ' . $brand . ' — your local multi-vendor marketplace.';
$canonical = $canonical ?? current_url();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($metaTitle) ?></title>
    <meta name="description" content="<?= esc($metaDesc, 'attr') ?>">
    <link rel="canonical" href="<?= esc($canonical, 'attr') ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= esc($metaTitle, 'attr') ?>">
    <meta property="og:description" content="<?= esc($metaDesc, 'attr') ?>">
    <meta name="robots" content="index,follow">
    <meta name="csrf-name" content="<?= csrf_token() ?>">
    <meta name="csrf-hash" content="<?= csrf_hash() ?>">
    <link rel="icon" href="<?= esc(service('settingsRepository')->logoUrl(), 'attr') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/store.css') ?>">
    <?= $this->renderSection('head') ?>
</head>
<body class="st-body"<?= service('locationService')->has() ? '' : ' data-need-location="1"' ?>>
    <?= $this->include('partials/_store_header') ?>
    <main class="container py-4"><?= $this->renderSection('content') ?></main>
    <?= $this->include('partials/_store_footer') ?>
    <?= $this->include('partials/_store_location_modal') ?>

    <!-- Slide-over cart drawer (quick-commerce). Body loads from store/cart/mini on open. -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="cartDrawer" aria-labelledby="cartDrawerLabel" style="max-width:92vw;width:400px" data-mini-url="<?= site_url('store/cart/mini') ?>">
        <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title" id="cartDrawerLabel"><i class="bi bi-bag me-2"></i>Your cart</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body" id="miniCartBody">
            <div class="text-center text-secondary py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>
        </div>
    </div>

    <!-- Quick-add variant picker (bottom sheet). Body loads from store/product/{id}/variants. -->
    <div class="offcanvas offcanvas-bottom st-variant-sheet" tabindex="-1" id="variantSheet" aria-labelledby="variantSheetTitle">
        <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title" id="variantSheetTitle"><i class="bi bi-sliders2 me-2"></i>Choose an option</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body" id="variantSheetBody">
            <div class="text-center text-secondary py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>
        </div>
    </div>
    <script src="<?= asset('vendor/jquery/jquery.min.js') ?>"></script>
    <script src="<?= asset('vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
    <script src="<?= asset('js/app.js') ?>"></script>
    <script src="<?= asset('plugins/sweetalert2/sweetalert2.all.min.js') ?>"></script>
    <script src="<?= asset('js/ajax-forms.js') ?>"></script>
    <script src="<?= asset('js/store-location.js') ?>"></script>
    <script src="<?= asset('js/store-ux.js') ?>"></script>
    <?= $this->renderSection('scripts') ?>
</body>
</html>
