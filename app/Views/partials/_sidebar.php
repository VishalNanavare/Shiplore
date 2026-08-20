<?php
$active = $active ?? '';
// [slug, label, icon, url-path]
$groups = [
    ['Pending Approval', 'bi-hourglass-split', [
        ['vendor-approvals', 'Vendor Approval', 'bi-person-check', 'admin/vendor-approvals'],
        ['shop-approvals', 'Shop Approval', 'bi-shop-window', 'admin/shop-approvals'],
    ]],
    ['Marketplace', 'bi-shop', [
        ['vendors', 'Vendors', 'bi-shop', 'admin/vendors'],
        ['manufacturers', 'Manufacturers', 'bi-building', 'admin/manufacturers'],
        ['shops', 'Shops', 'bi-geo-alt', 'admin/shops'],
        ['products', 'Products', 'bi-box-seam', 'admin/products'],
        ['product-approvals', 'Product Approvals', 'bi-patch-check', 'admin/product-approvals'],
        ['categories', 'Categories', 'bi-diagram-3', 'admin/categories'],
        ['business-types', 'Business Types', 'bi-shop-window', 'admin/business-types'],
        ['brands', 'Brands', 'bi-bookmark-star', 'admin/brands'],
        ['attributes', 'Attributes', 'bi-tags', 'admin/attributes'],
        ['media', 'Media Library', 'bi-folder2-open', 'admin/media'],
    ]],
    ['Catalog Masters', 'bi-sliders2', [
        ['units', 'Units', 'bi-rulers', 'admin/units'],
        ['tax-classes', 'Tax Classes', 'bi-percent', 'admin/tax-classes'],
        ['hsn-codes', 'HSN / SAC', 'bi-upc', 'admin/hsn-codes'],
        ['zones', 'Delivery Zones', 'bi-map', 'admin/zones'],
    ]],
    ['Sales', 'bi-bag', [
        ['orders', 'Orders', 'bi-bag', 'admin/orders'],
        ['purchase-orders', 'B2B Purchase Orders', 'bi-receipt', 'admin/purchase-orders'],
        ['deliveries', 'Deliveries', 'bi-truck', 'admin/deliveries'],
        ['riders', 'Riders', 'bi-scooter', 'admin/riders'],
        ['payments', 'Payments', 'bi-credit-card', 'admin/payments'],
        ['refunds', 'Refunds', 'bi-arrow-counterclockwise', 'admin/refunds'],
        ['returns', 'Returns', 'bi-box-arrow-in-left', 'admin/returns'],
        ['reviews', 'Reviews', 'bi-star', 'admin/reviews'],
        ['promotions', 'Promotions', 'bi-megaphone', 'admin/promotions'],
        ['banners', 'Banners', 'bi-images', 'admin/banners'],
        ['coupons', 'Coupons', 'bi-ticket-perforated', 'admin/coupons'],
    ]],
    ['Finance', 'bi-cash-stack', [
        ['settlements', 'Settlements', 'bi-cash-stack', 'admin/settlements'],
        ['rider-finance', 'Rider Payouts & COD', 'bi-scooter', 'admin/rider-finance/payouts'],
        ['commission', 'Commissions', 'bi-percent', 'admin/commission'],
        ['commission-holds', 'Commission Holds', 'bi-hourglass-split', 'admin/commission-holds'],
        ['invoices', 'Invoices', 'bi-receipt-cutoff', 'admin/invoices'],
        ['credit-notes', 'Credit Notes', 'bi-receipt', 'admin/credit-notes'],
        ['ledger', 'Ledger', 'bi-journal-text', 'admin/ledger'],
        ['gst-report', 'GST Report', 'bi-file-earmark-spreadsheet', 'admin/reports/gst'],
        ['reports', 'Reports', 'bi-bar-chart', 'admin/reports'],
    ]],
    ['Operations', 'bi-gear-wide-connected', [
        ['warehouses', 'Warehouses', 'bi-buildings', 'admin/warehouses'],
        ['transfers', 'Stock Transfers', 'bi-arrow-left-right', 'admin/transfers'],
        ['imports', 'Bulk Imports', 'bi-upload', 'admin/imports'],
    ]],
    ['Integrations', 'bi-plug', [
        ['payment-gateways', 'Payment Gateways', 'bi-credit-card-2-back', 'admin/payment-gateways'],
        ['int-firebase', 'Firebase', 'bi-fire', 'admin/integrations/firebase'],
        ['int-email', 'Email / SMTP', 'bi-envelope-gear', 'admin/integrations/email'],
        ['int-gst-api', 'GST API', 'bi-receipt', 'admin/integrations/gst-api'],
        ['int-google-maps', 'Google Maps', 'bi-geo-alt', 'admin/integrations/google-maps'],
        ['int-aws', 'AWS / S3', 'bi-hdd-network', 'admin/integrations/aws'],
        ['sync-health', 'Sync Health', 'bi-arrow-repeat', 'admin/sync-health'],
    ]],
    ['Access & Config', 'bi-shield-lock', [
        ['approvals', 'Approval Queue', 'bi-check2-square', 'admin/approvals'],
        ['roles', 'Roles & Permissions', 'bi-shield-lock', 'admin/roles'],
        ['users', 'Staff Users', 'bi-person-badge', 'admin/users'],
        ['feature-flags', 'Feature Flags', 'bi-toggles', 'admin/feature-flags'],
    ]],
    ['Platform', 'bi-sliders', [
        ['customers', 'Customers', 'bi-people', 'admin/customers'],
        ['support', 'Support', 'bi-life-preserver', 'admin/support'],
        ['notifications', 'Notifications', 'bi-bell', 'admin/notifications'],
        ['settings', 'Settings', 'bi-gear', 'admin/settings'],
        ['audit-logs', 'Audit Logs', 'bi-clipboard-data', 'admin/audit-logs'],
        ['backups', 'Backups', 'bi-hdd-stack', 'admin/backups'],
    ]],
];

// Hide optional modules an admin has switched off (data is kept, menu + pages gated).
$disabled = [];
foreach (\App\Models\SettingsRepository::OPTIONAL_MODULES as $modKey => $modLabel) {
    if (! service('settingsRepository')->moduleEnabled($modKey)) {
        $disabled[$modKey] = true;
    }
}
if ($disabled !== []) {
    foreach ($groups as $gi => $g) {
        $groups[$gi][2] = array_values(array_filter($g[2], static fn ($it) => ! isset($disabled[$it[0]])));
    }
}

// Fall back to the current URL to decide the active item when a controller didn't
// set $active — so every page (incl. detail pages) highlights the right menu.
if ($active === '') {
    $path = trim(uri_string(), '/');
    if ($path === 'admin' || $path === 'admin/dashboard') {
        $active = 'dashboard';
    } else {
        $bestLen = 0;
        foreach ($groups as $g) {
            foreach ($g[2] as $it) {
                if (($path === $it[3] || str_starts_with($path, $it[3] . '/')) && strlen($it[3]) > $bestLen) {
                    $bestLen = strlen($it[3]);
                    $active  = $it[0];
                }
            }
        }
    }
}
?>
<aside class="app-sidebar">
    <div class="sidebar-brand">
        <img src="<?= esc(service('settingsRepository')->logoUrl(), 'attr') ?>" alt="" width="28" height="28" style="object-fit:contain;border-radius:4px">
        <span><?= esc(service('settingsRepository')->brandName()) ?></span>
    </div>
    <nav class="py-2">
        <a class="nav-link <?= $active === 'dashboard' ? 'active' : '' ?>" href="<?= site_url('admin/dashboard') ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>

        <?php foreach ($groups as $gi => [$header, $gicon, $items]):
            $hasActive = false;
            foreach ($items as $it) { if ($it[0] === $active) { $hasActive = true; break; } }
        ?>
            <a class="nav-group-toggle" data-bs-toggle="collapse" href="#adm-g<?= $gi ?>" role="button" aria-expanded="<?= $hasActive ? 'true' : 'false' ?>">
                <i class="bi <?= esc($gicon, 'attr') ?>"></i> <span><?= esc($header) ?></span> <i class="bi bi-chevron-right nav-caret"></i>
            </a>
            <div class="collapse <?= $hasActive ? 'show' : '' ?> nav-sub" id="adm-g<?= $gi ?>">
                <?php foreach ($items as [$slug, $label, $icon, $url]): ?>
                    <a class="<?= $active === $slug ? 'active' : '' ?>" href="<?= site_url($url) ?>"><i class="bi <?= esc($icon, 'attr') ?>"></i> <?= esc($label) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>
</aside>
