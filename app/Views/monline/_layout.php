<?php
$brand = service('settingsRepository')->brandName();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-name" content="<?= csrf_token() ?>">
    <meta name="csrf-hash" content="<?= csrf_hash() ?>">
    <title><?= esc($title ?? ($brand . ' monline')) ?></title>
    <link rel="icon" href="<?= esc(service('settingsRepository')->logoUrl(), 'attr') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/monline.css') ?>">
    <?= $this->renderSection('head') ?>
</head>
<body class="mo-body">
<nav class="mo-header">
    <div class="container d-flex align-items-center gap-3 py-2 flex-wrap">
        <a class="mo-brand" href="<?= site_url('monline') ?>">
            <img src="<?= esc(service('settingsRepository')->logoUrl(), 'attr') ?>" alt="" width="26" height="26">
            <span><?= esc($brand) ?> <span class="mo-tag">monline</span></span>
        </a>
        <a class="mo-navlink" href="<?= site_url('monline/browse') ?>">Catalogue</a>

        <form method="get" action="<?= site_url('monline/browse') ?>" class="mo-search flex-grow-1 mx-auto">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white border-0"><i class="bi bi-search"></i></span>
                <input type="search" name="q" class="form-control" placeholder="Search products, SKU or manufacturer…">
            </div>
        </form>

        <div class="ms-auto d-flex align-items-center gap-3">
            <?php if (! empty($isBuyer)): ?>
                <a class="mo-navlink" href="<?= site_url('monline/orders') ?>">My orders</a>
                <a class="btn btn-sm btn-light mo-cartbtn" href="<?= site_url('monline/cart') ?>">
                    <i class="bi bi-cart"></i> Order
                    <?php if (! empty($cartCount)): ?><span class="badge rounded-pill"><?= (int) $cartCount ?></span><?php endif; ?>
                </a>
                <span class="mo-navlink d-none d-lg-inline"><?= esc($buyerName ?? '') ?></span>
            <?php else: ?>
                <a class="btn btn-sm mo-signin-cta" href="<?= site_url('login') ?>">Sign in to order</a>
            <?php endif; ?>
        </div>
    </div>
</nav>
<main class="container py-4">
    <?php if ($m = session('success')): ?><div class="alert alert-success py-2"><?= esc($m) ?></div><?php endif; ?>
    <?php if ($m = session('error')): ?><div class="alert alert-danger py-2"><?= esc($m) ?></div><?php endif; ?>
    <?= $this->renderSection('content') ?>
</main>
<footer class="mo-footer text-center py-4">
    <?= esc($brand) ?> monline — wholesale marketplace for registered vendors and shops.
</footer>
<script src="<?= asset('vendor/jquery/jquery.min.js') ?>"></script>
<script src="<?= asset('vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
