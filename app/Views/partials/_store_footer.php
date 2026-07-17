<footer class="st-footer mt-5">
    <div class="container py-4">
        <div class="row g-4">
            <div class="col-md-4">
                <div class="st-brand text-white mb-2">Commerce<span>Hub</span></div>
                <p class="small mb-0">Your neighbourhood multi-vendor marketplace — shop from nearby stores with fast local delivery.</p>
            </div>
            <div class="col-6 col-md-2"><h6 class="text-white small text-uppercase">Shop</h6>
                <ul class="list-unstyled small mb-0">
                    <li><a href="<?= site_url('store/shops') ?>">Nearby Shops</a></li>
                    <li><a href="<?= site_url('store/products') ?>">All Products</a></li>
                    <li><a href="<?= site_url('store/track') ?>">Track Order</a></li>
                </ul>
            </div>
            <div class="col-6 col-md-2"><h6 class="text-white small text-uppercase">Account</h6>
                <ul class="list-unstyled small mb-0">
                    <li><a href="<?= site_url('store/account') ?>">My Account</a></li>
                    <li><a href="<?= site_url('store/account/orders') ?>">My Orders</a></li>
                    <li><a href="<?= site_url('store/account/support') ?>">Support</a></li>
                </ul>
            </div>
            <div class="col-md-4"><h6 class="text-white small text-uppercase">Become a Vendor</h6>
                <p class="small mb-2">Sell to your neighbourhood. List your shop on <?= esc(service('settingsRepository')->brandName()) ?>.</p>
                <a href="<?= site_url('register') ?>" class="btn btn-sm btn-outline-light">Register your business</a>
            </div>
        </div>
        <hr class="border-secondary">
        <div class="small text-center">© <?= date('Y') ?> <?= esc(service('settingsRepository')->brandName()) ?> · Omnichannel Multi-Vendor Commerce · <a href="<?= site_url('login') ?>">Staff sign-in</a></div>
    </div>
</footer>
