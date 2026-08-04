<?= $this->include('partials/_head') ?>
<body class="bg-body-tertiary">
<nav class="navbar navbar-expand-lg bg-white border-bottom mb-4">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= site_url('monline') ?>">
            <img src="<?= asset('images/logo.svg') ?>" alt="" width="28" height="28">
            <span><?= esc(service('settingsRepository')->brandName()) ?> <span class="text-primary fw-semibold">monline</span></span>
        </a>
        <div class="ms-auto d-flex align-items-center gap-3">
            <?php if (! empty($isBuyer)): ?>
                <a class="text-decoration-none small" href="<?= site_url('monline/browse') ?>">Catalogue</a>
                <a class="text-decoration-none small" href="<?= site_url('monline/orders') ?>">My orders</a>
                <a class="btn btn-sm btn-outline-primary" href="<?= site_url('monline/cart') ?>">
                    <i class="bi bi-cart"></i> Order
                    <?php if (! empty($cartCount)): ?><span class="badge bg-primary ms-1"><?= (int) $cartCount ?></span><?php endif; ?>
                </a>
                <span class="small text-secondary d-none d-lg-inline"><?= esc($buyerName ?? '') ?></span>
            <?php else: ?>
                <a class="btn btn-sm btn-primary" href="<?= site_url('login') ?>">Sign in to order</a>
            <?php endif; ?>
        </div>
    </div>
</nav>
<main class="container pb-5">
    <?php if ($m = session('success')): ?><div class="alert alert-success py-2"><?= esc($m) ?></div><?php endif; ?>
    <?php if ($m = session('error')): ?><div class="alert alert-danger py-2"><?= esc($m) ?></div><?php endif; ?>
    <?= $this->renderSection('content') ?>
</main>
<?= $this->include('partials/_scripts') ?>
</body>
</html>
