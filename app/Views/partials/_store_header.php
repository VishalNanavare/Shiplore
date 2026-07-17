<?php
$loc       = service('locationService')->get();
$cartCount = service('cartService')->count();
$customer  = session()->get('customer_name');
$catNav    = service('storeCatalogRepository')->rootCategoryFacets([], 8); // top stocked categories only (hide empties)
?>
<header class="st-header">
    <div class="container">
        <div class="d-flex align-items-center gap-2 gap-md-3 py-2">
            <a class="st-brand" href="<?= site_url('/') ?>">
                <img src="<?= esc(service('settingsRepository')->logoUrl(), 'attr') ?>" alt="" width="28" height="28" style="border-radius:5px;object-fit:contain">
                <?= esc(service('settingsRepository')->brandName()) ?>
            </a>

            <button type="button" class="st-loc" onclick="window.openLocationPicker&&openLocationPicker()">
                <i class="bi bi-geo-alt-fill text-primary"></i>
                <span class="st-loc-text">
                    <small class="d-block text-secondary lh-1">Deliver to</small>
                    <span class="fw-semibold text-truncate d-inline-block align-bottom" style="max-width:130px"><?= $loc ? esc($loc['label']) : 'Select location' ?></span>
                </span>
                <i class="bi bi-chevron-down ms-1 small"></i>
            </button>

            <form class="flex-grow-1 d-none d-md-block" method="get" action="<?= site_url('store/products') ?>" data-no-ajax>
                <div class="st-search input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-secondary"></i></span>
                    <input name="q" class="form-control border-start-0" placeholder="Search for products, brands and shops…" value="<?= esc(service('request')->getGet('q') ?? '', 'attr') ?>">
                </div>
            </form>

            <a href="<?= site_url('store/wishlist') ?>" class="btn btn-light st-iconbtn d-none d-sm-inline-flex" title="Wishlist"><i class="bi bi-heart fs-5"></i></a>
            <a href="<?= site_url('store/cart') ?>" class="btn btn-light st-iconbtn st-cartbtn" title="Cart" data-bs-toggle="offcanvas" data-bs-target="#cartDrawer" role="button"><i class="bi bi-bag fs-5"></i><span class="badge rounded-pill bg-primary" id="cartCountBadge"<?= $cartCount > 0 ? '' : ' style="display:none"' ?>><?= $cartCount ?: '' ?></span></a>

            <div class="dropdown">
                <button class="btn btn-light st-iconbtn dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-person-circle fs-5"></i></button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <?php if ($customer): ?>
                        <li><h6 class="dropdown-header"><?= esc($customer) ?></h6></li>
                        <li><a class="dropdown-item" href="<?= site_url('store/account') ?>"><i class="bi bi-person me-2"></i>My Account</a></li>
                        <li><a class="dropdown-item" href="<?= site_url('store/account/orders') ?>"><i class="bi bi-bag-check me-2"></i>My Orders</a></li>
                        <li><a class="dropdown-item" href="<?= site_url('store/wishlist') ?>"><i class="bi bi-heart me-2"></i>Wishlist</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><form method="post" action="<?= site_url('store/logout') ?>" class="m-0"><?= csrf_field() ?><button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button></form></li>
                    <?php else: ?>
                        <li><a class="dropdown-item fw-semibold" href="<?= site_url('store/login') ?>"><i class="bi bi-box-arrow-in-right me-2"></i>Sign in / Sign up</a></li>
                        <li><a class="dropdown-item" href="<?= site_url('store/wishlist') ?>"><i class="bi bi-heart me-2"></i>Wishlist</a></li>
                        <li><a class="dropdown-item" href="<?= site_url('store/track') ?>"><i class="bi bi-truck me-2"></i>Track order</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <form class="d-md-none pb-2" method="get" action="<?= site_url('store/products') ?>" data-no-ajax>
            <div class="st-search input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-secondary"></i></span>
                <input name="q" class="form-control border-start-0" placeholder="Search products…" value="<?= esc(service('request')->getGet('q') ?? '', 'attr') ?>">
            </div>
        </form>

        <nav class="st-catnav d-flex gap-1 pb-2 overflow-auto">
            <a href="<?= site_url('store/shops') ?>" class="st-catlink"><i class="bi bi-shop me-1"></i>Shops near you</a>
            <a href="<?= site_url('store/products') ?>" class="st-catlink"><i class="bi bi-grid me-1"></i>All</a>
            <?php foreach ($catNav as $c): ?>
                <a href="<?= site_url('store/category/' . $c['slug']) ?>" class="st-catlink"><?= esc($c['name']) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</header>
