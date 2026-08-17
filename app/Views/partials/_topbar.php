<header class="app-topbar">
    <button class="btn btn-light d-lg-none" type="button" data-toggle="sidebar" aria-label="Toggle navigation">
        <i class="bi bi-list fs-5"></i>
    </button>
    <h1 class="h6 mb-0 fw-semibold"><?= esc($pageTitle ?? 'Dashboard') ?></h1>
    <div class="ms-auto d-flex align-items-center gap-2">
        <?php
        /*
         * S1 — active-LOCATION context for location-scoped staff.
         *
         * Generalised from "shop" so the manufacturer panel can use it too: its staff
         * are scoped to manufacturing units (mshops), and BaseManufacturerController
         * exported activeMshopName/unitSwitch/activeMshopId all along — the topbar just
         * read three different names, so unit staff never got a chip or a switcher.
         *
         * The shop names are the fallback, so the vendor and admin panels are unchanged.
         */
        $locName   = $activeLocationName ?? ($activeShopName ?? null);
        $locSwitch = $locationSwitch ?? ($shopSwitch ?? []);
        $locId     = $activeLocationId ?? ($activeShopId ?? 0);
        $locField  = $locationField ?? 'shop_id';
        $locIcon   = $locationIcon ?? 'bi-shop';
        ?>
        <?php if (! empty($locName)): ?>
            <?php if (! empty($locSwitch) && count($locSwitch) > 1): ?>
                <form method="get" class="d-none d-sm-block mb-0">
                    <select name="<?= esc($locField, 'attr') ?>" class="form-select form-select-sm" onchange="this.form.submit()" title="Active location" aria-label="Active location">
                        <?php foreach ($locSwitch as $sid => $sname): ?>
                            <option value="<?= esc($sid, 'attr') ?>" <?= (int) $locId === (int) $sid ? 'selected' : '' ?>><?= esc($sname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php else: ?>
                <span class="badge text-bg-primary"><i class="bi <?= esc($locIcon, 'attr') ?> me-1"></i><?= esc($locName) ?></span>
            <?php endif; ?>
        <?php endif; ?>
        <?php
        // This partial is shared by admin, vendor and manufacturer layouts. It used to
        // be a 2-way ternary (admin vs "everything else -> vendor"), which silently
        // sent a manufacturer session into the vendor panel's notifications page — a
        // route that panel restriction also 404s on the manufacturer host, since
        // 'vendor/...' only resolves on vendor./shop. The manufacturer branch pointed
        // at the dashboard purely as a placeholder while that panel had no
        // notifications page; it has one now, so the link finally goes where the
        // label says.
        if (str_starts_with(uri_string(), 'admin')) {
            $notifAllUrl = site_url('admin/notifications');
        } elseif (str_starts_with(uri_string(), 'manufacturer')) {
            $notifAllUrl = site_url('manufacturer/notifications');
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
                // Same panel detection as the notifications link above. This dropdown is
                // the personal account, so manufacturer resolves to manufacturer/me (the
                // counterpart of vendor/me), not to the business profile.
                if (str_starts_with(uri_string(), 'admin')) {
                    $profileUrl = site_url('admin/profile');
                } elseif (str_starts_with(uri_string(), 'manufacturer')) {
                    $profileUrl = site_url('manufacturer/me');
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
