<?php
/**
 * UI Kit layout — standalone reference shell (not the application chrome).
 * Expects: $menu (grouped), $active (slug), $title.
 */
$groupIcons = [
    'Dashboards' => 'bi-speedometer2', 'Apps' => 'bi-window-stack', 'eCommerce' => 'bi-cart3',
    'Components' => 'bi-puzzle', 'Forms & Tables' => 'bi-ui-checks-grid', 'Extensions' => 'bi-box',
    'Charts & Maps' => 'bi-bar-chart-line', 'Pages' => 'bi-file-earmark-text', 'User Interface' => 'bi-brush',
];
// Breadcrumb: locate the group of the active slug
$activeGroup = '';
foreach ($menu as $g) {
    foreach ($g['items'] as $it) { if ($it['slug'] === $active) { $activeGroup = $g['header']; break 2; } }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? 'UI Kit') ?> · UI Kit</title>
    <link rel="icon" href="<?= asset('images/logo.svg') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('plugins/toastr/toastr.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/uikit.css') ?>">
    <?= $this->renderSection('head') ?>
</head>
<body class="uk-body">
<div class="uk-shell" id="ukShell">

    <!-- Sidebar -->
    <aside class="uk-sidebar">
        <a class="uk-brand text-decoration-none" href="<?= site_url('ui-kit') ?>">
            <img src="<?= asset('images/logo.svg') ?>" alt="" width="24" height="24">
            <span>UI<span style="color:var(--uk-primary)">Kit</span></span>
            <span class="badge bg-secondary ms-1">v1</span>
        </a>
        <nav class="uk-nav">
            <a class="uk-nav-header text-decoration-none d-block <?= $active === 'home' ? 'text-white' : '' ?>" href="<?= site_url('ui-kit') ?>" style="border-left:3px solid <?= $active === 'home' ? 'var(--uk-primary)' : 'transparent' ?>">
                <i class="bi bi-house-door me-1"></i> Overview
            </a>
            <?php foreach ($menu as $i => $group):
                $hasActive = false;
                foreach ($group['items'] as $it) { if ($it['slug'] === $active) { $hasActive = true; break; } }
            ?>
                <div class="uk-nav-group">
                    <a class="text-decoration-none" data-bs-toggle="collapse" href="#ukGrp<?= $i ?>" aria-expanded="<?= $hasActive ? 'true' : 'false' ?>">
                        <i class="bi <?= esc($groupIcons[$group['header']] ?? 'bi-dot') ?>"></i>
                        <span><?= esc($group['header']) ?></span>
                        <i class="bi bi-chevron-right uk-caret"></i>
                    </a>
                    <div class="uk-submenu collapse <?= $hasActive ? 'show' : '' ?>" id="ukGrp<?= $i ?>">
                        <?php foreach ($group['items'] as $it): ?>
                            <a class="<?= $it['slug'] === $active ? 'active' : '' ?>" href="<?= site_url('ui-kit/' . $it['slug']) ?>"><?= esc($it['title']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </nav>
    </aside>

    <!-- Main -->
    <div class="uk-main">
        <header class="uk-topbar">
            <button class="btn btn-light uk-burger" id="ukBurger" type="button" aria-label="Menu"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h1 class="uk-page-title"><?= esc($title ?? 'UI Kit') ?></h1>
                <nav class="uk-breadcrumb"><a href="<?= site_url('ui-kit') ?>" class="text-decoration-none text-secondary">UI Kit</a><?php if ($activeGroup !== ''): ?> <i class="bi bi-chevron-right" style="font-size:.6rem"></i> <?= esc($activeGroup) ?> <i class="bi bi-chevron-right" style="font-size:.6rem"></i> <span class="text-dark"><?= esc($title) ?></span><?php endif; ?></nav>
            </div>
            <div class="ms-auto d-flex align-items-center gap-2">
                <span class="badge bg-light text-secondary border">Reference theme — not wired to the app</span>
                <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('/') ?>"><i class="bi bi-box-arrow-left me-1"></i>App</a>
            </div>
        </header>

        <main class="uk-content">
            <?= $this->renderSection('content') ?>
        </main>

        <footer class="text-center text-secondary small py-3 border-top">
            Shiplore UI Kit — design reference · Bootstrap 5
        </footer>
    </div>
</div>

<script src="<?= asset('vendor/jquery/jquery.min.js') ?>"></script>
<script src="<?= asset('vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('plugins/chartjs/chart.umd.min.js') ?>"></script>
<script src="<?= asset('plugins/sweetalert2/sweetalert2.all.min.js') ?>"></script>
<script src="<?= asset('plugins/toastr/toastr.min.js') ?>"></script>
<script src="<?= asset('js/uikit.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
