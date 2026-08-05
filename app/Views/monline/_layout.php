<?php
$brand     = service('settingsRepository')->brandName();
$metaTitle = $title ?? ($brand . ' monline');
$metaDesc  = $metaDesc ?? 'Wholesale marketplace — buy direct from manufacturers on ' . $brand . ' monline.';
$catNav    = array_slice($navCategories ?? [], 0, 8); // header shows top 8; home shows all
$q         = (string) (service('request')->getGet('q') ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($metaTitle) ?></title>
    <meta name="description" content="<?= esc($metaDesc, 'attr') ?>">
    <meta name="robots" content="index,follow">
    <meta name="csrf-name" content="<?= csrf_token() ?>">
    <meta name="csrf-hash" content="<?= csrf_hash() ?>">
    <link rel="icon" href="<?= esc(service('settingsRepository')->logoUrl(), 'attr') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/monline.css') ?>">
    <?= $this->renderSection('head') ?>
</head>
<body class="mo-body">

<header class="mo-header">
    <div class="container">
        <div class="d-flex align-items-center gap-2 gap-md-3 py-2">
            <a class="mo-brand" href="<?= site_url('monline') ?>">
                <img src="<?= esc(service('settingsRepository')->logoUrl(), 'attr') ?>" alt="" width="28" height="28">
                <?= esc($brand) ?><span>monline</span>
            </a>

            <?php if (! empty($isBuyer)): ?>
                <button type="button" class="mo-loc" onclick="window.openMonlineLocationPicker&&openMonlineLocationPicker()">
                    <i class="bi bi-geo-alt-fill text-primary"></i>
                    <span>
                        <small class="d-block text-secondary lh-1">Sorting from</small>
                        <span class="fw-semibold text-truncate d-inline-block align-bottom mo-loc-label"><?= esc($nearLabel ?? 'your shop') ?></span>
                    </span>
                    <i class="bi bi-chevron-down ms-1 small"></i>
                </button>
                <?php if (! empty($hasLocationOverride)): ?>
                    <form method="post" action="<?= site_url('monline/location/clear') ?>" class="m-0 d-none d-sm-block">
                        <?= csrf_field() ?>
                        <input type="hidden" name="return" value="<?= esc(uri_string(), 'attr') ?>">
                        <button type="submit" class="mo-locreset" title="Back to your shop's location"><i class="bi bi-arrow-counterclockwise"></i></button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <form class="flex-grow-1 d-none d-md-block" method="get" action="<?= site_url('monline/browse') ?>">
                <div class="mo-search input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-secondary"></i></span>
                    <input name="q" class="form-control border-start-0" placeholder="Search products, SKU or manufacturer…" value="<?= esc($q, 'attr') ?>">
                </div>
            </form>

            <?php if (! empty($isBuyer)): ?>
                <a href="<?= site_url('monline/orders') ?>" class="btn btn-light mo-iconbtn d-none d-sm-inline-flex" title="Purchase orders"><i class="bi bi-receipt fs-5"></i></a>
                <a href="<?= site_url('monline/cart') ?>" class="btn btn-light mo-iconbtn mo-cartbtn" title="Your order">
                    <i class="bi bi-cart fs-5"></i>
                    <span class="badge rounded-pill bg-primary"<?= empty($cartCount) ? ' style="display:none"' : '' ?>><?= ! empty($cartCount) ? (int) $cartCount : '' ?></span>
                </a>
            <?php else: ?>
                <a href="<?= site_url('login') ?>" class="btn btn-primary btn-sm d-none d-lg-inline-flex fw-semibold">Sign in to order</a>
            <?php endif; ?>

            <div class="dropdown">
                <button class="btn btn-light mo-iconbtn dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-person-circle fs-5"></i></button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <?php if (! empty($isBuyer)): ?>
                        <li><h6 class="dropdown-header"><?= esc($buyerName ?? '') ?></h6></li>
                        <li><a class="dropdown-item" href="<?= site_url('monline/orders') ?>"><i class="bi bi-receipt me-2"></i>My purchase orders</a></li>
                        <li><a class="dropdown-item" href="<?= site_url('monline/cart') ?>"><i class="bi bi-cart me-2"></i>Current order</a></li>
                        <li><a class="dropdown-item" href="<?= site_url('vendor/dashboard') ?>"><i class="bi bi-shop me-2"></i>Vendor panel</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><form method="post" action="<?= site_url('logout') ?>" class="m-0"><?= csrf_field() ?><button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button></form></li>
                    <?php else: ?>
                        <li><a class="dropdown-item fw-semibold" href="<?= site_url('login') ?>"><i class="bi bi-box-arrow-in-right me-2"></i>Sign in to order</a></li>
                        <li><a class="dropdown-item" href="<?= site_url('register') ?>"><i class="bi bi-shop me-2"></i>Become a vendor</a></li>
                        <li><a class="dropdown-item" href="<?= site_url('manufacturer-register') ?>"><i class="bi bi-buildings me-2"></i>Become a manufacturer</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <form class="d-md-none pb-2" method="get" action="<?= site_url('monline/browse') ?>">
            <div class="mo-search input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-secondary"></i></span>
                <input name="q" class="form-control border-start-0" placeholder="Search products…" value="<?= esc($q, 'attr') ?>">
            </div>
        </form>

        <nav class="mo-catnav d-flex gap-1 pb-2 overflow-auto">
            <a href="<?= site_url('monline/browse') ?>" class="mo-catlink"><i class="bi bi-grid me-1"></i>Full catalogue</a>
            <?php foreach ($catNav as $c): ?>
                <a href="<?= site_url('monline/browse') . '?category=' . rawurlencode((string) $c['slug']) ?>" class="mo-catlink"><?= esc($c['name']) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</header>

<main class="container py-4">
    <?php if ($m = session('success')): ?><div class="alert alert-success py-2"><?= esc($m) ?></div><?php endif; ?>
    <?php if ($m = session('error')): ?><div class="alert alert-danger py-2"><?= esc($m) ?></div><?php endif; ?>
    <?= $this->renderSection('content') ?>
</main>

<footer class="mo-footer">
    <div class="container py-4">
        <div class="row g-4">
            <div class="col-md-4">
                <div class="mo-brand mb-2"><?= esc($brand) ?><span>monline</span></div>
                <p class="small mb-0">The wholesale side of <?= esc($brand) ?> — registered vendors and shop managers buying direct from manufacturers, at factory prices.</p>
            </div>
            <div class="col-6 col-md-2"><h6 class="text-white small text-uppercase">Buy</h6>
                <ul class="list-unstyled small mb-0">
                    <li><a href="<?= site_url('monline/browse') ?>">Full catalogue</a></li>
                    <li><a href="<?= site_url('monline/cart') ?>">Current order</a></li>
                    <li><a href="<?= site_url('monline/orders') ?>">Purchase orders</a></li>
                </ul>
            </div>
            <div class="col-6 col-md-2"><h6 class="text-white small text-uppercase">Shop retail</h6>
                <ul class="list-unstyled small mb-0">
                    <li><a href="<?= site_url('/') ?>"><?= esc($brand) ?> storefront</a></li>
                    <li><a href="<?= site_url('vendor/dashboard') ?>">Vendor panel</a></li>
                </ul>
            </div>
            <div class="col-md-4"><h6 class="text-white small text-uppercase">Sell on monline</h6>
                <p class="small mb-2">List your factory catalogue and take purchase orders from shops across the network.</p>
                <a href="<?= site_url('manufacturer-register') ?>" class="btn btn-sm btn-outline-light">Become a manufacturer</a>
            </div>
        </div>
        <hr class="border-secondary">
        <div class="small text-center">&copy; <?= date('Y') ?> <?= esc($brand) ?> monline &middot; Wholesale marketplace for registered vendors and shops</div>
    </div>
</footer>

<?php if (! empty($isBuyer)): ?>
    <?= $this->include('monline/_location_modal') ?>
<?php endif; ?>
<script src="<?= asset('vendor/jquery/jquery.min.js') ?>"></script>
<script src="<?= asset('vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<?php if (! empty($isBuyer)): ?>
    <script src="<?= asset('js/monline-location.js') ?>"></script>
<?php endif; ?>
<?= $this->renderSection('scripts') ?>
</body>
</html>
