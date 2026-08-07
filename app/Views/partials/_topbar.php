<header class="app-topbar">
    <button class="btn btn-light d-lg-none" type="button" data-toggle="sidebar" aria-label="Toggle navigation">
        <i class="bi bi-list fs-5"></i>
    </button>
    <h1 class="h6 mb-0 fw-semibold"><?= esc($pageTitle ?? 'Dashboard') ?></h1>
    <div class="ms-auto d-flex align-items-center gap-2">
        <?php /* S1 — active-shop context for branch staff (vendor panel only). */ ?>
        <?php if (! empty($activeShopName)): ?>
            <?php if (! empty($shopSwitch) && count($shopSwitch) > 1): ?>
                <form method="get" class="d-none d-sm-block mb-0">
                    <select name="shop_id" class="form-select form-select-sm" onchange="this.form.submit()" title="Active store" aria-label="Active store">
                        <?php foreach ($shopSwitch as $sid => $sname): ?>
                            <option value="<?= esc($sid, 'attr') ?>" <?= (int) ($activeShopId ?? 0) === (int) $sid ? 'selected' : '' ?>><?= esc($sname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php else: ?>
                <span class="badge text-bg-primary"><i class="bi bi-shop me-1"></i><?= esc($activeShopName) ?></span>
            <?php endif; ?>
        <?php endif; ?>
        <?php
        // This partial is shared by admin, vendor and manufacturer layouts. It used to
        // be a 2-way ternary (admin vs "everything else -> vendor"), which silently
        // sent a manufacturer session into the vendor panel's notifications page — a
        // route that panel restriction now also 404s on the manufacturer host, since
        // 'vendor/...' only resolves on vendor./shop. There is no manufacturer
        // notifications page yet, so that branch lands on the manufacturer dashboard
        // instead of a broken or wrong-panel destination.
        if (str_starts_with(uri_string(), 'admin')) {
            $notifAllUrl = site_url('admin/notifications');
        } elseif (str_starts_with(uri_string(), 'manufacturer')) {
            $notifAllUrl = site_url('manufacturer/dashboard');
        } else {
            $notifAllUrl = site_url('vendor/notifications');
        }
        ?>
        <div class="dropdown notif-dropdown">
            <button class="btn btn-light position-relative" type="button" id="notifBell" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications">
                <i class="bi bi-bell"></i>
                <span class="notif-badge d-none" id="notifBadge">0</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end notif-panel p-0" aria-labelledby="notifBell">
                <div class="notif-head d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Notifications</span>
                    <button type="button" class="btn btn-link p-0 notif-clear" id="notifClear">Clear All</button>
                </div>
                <div class="notif-list" id="notifList" data-feed-url="<?= site_url('notifications/feed') ?>">
                    <div class="notif-empty text-center text-secondary py-4">
                        <i class="bi bi-bell d-block mb-2 fs-4"></i>No notifications yet.
                    </div>
                </div>
                <div class="notif-foot text-center">
                    <a href="<?= esc($notifAllUrl, 'attr') ?>">View All Notifications</a>
                </div>
            </div>
        </div>
        <div class="dropdown">
            <button class="btn btn-light dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle fs-5"></i>
                <span class="d-none d-sm-inline"><?= esc($userName ?? 'Super Admin') ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <?php
                // Same panel-detection fix as the notifications link above: manufacturer
                // has no profile page yet, so it must not fall through to vendor's.
                if (str_starts_with(uri_string(), 'admin')) {
                    $profileUrl = site_url('admin/profile');
                } elseif (str_starts_with(uri_string(), 'manufacturer')) {
                    $profileUrl = site_url('manufacturer/dashboard');
                } else {
                    $profileUrl = site_url('vendor/me');
                }
                ?>
                <li><a class="dropdown-item" href="<?= esc($profileUrl, 'attr') ?>"><i class="bi bi-person me-2"></i>Profile</a></li>
                <?php if (str_starts_with(uri_string(), 'admin') && service('policyEngine')->can(service('scopeContext')->all(), 'settings.view')): ?>
                <li><a class="dropdown-item" href="<?= site_url('admin/settings') ?>"><i class="bi bi-gear me-2"></i>Settings</a></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><form method="post" action="<?= site_url('logout') ?>" class="m-0"><?= csrf_field() ?><button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Logout</button></form></li>
            </ul>
        </div>
    </div>
</header>
