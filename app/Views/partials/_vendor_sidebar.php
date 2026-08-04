<?php
$active = $active ?? '';
// Each item: [slug, label, icon, url, permission]. Owners see everything;
// staff see only items whose permission their shop-scoped role grants.
$navIsOwner = $navIsOwner ?? true;
$navPerms   = $navPerms ?? [];
// perm 'OWNER' = owner-only (hidden from shop staff/managers); '' = everyone.
$visible    = static fn (string $perm): bool => $perm === 'OWNER' ? $navIsOwner : ($navIsOwner || $perm === '' || isset($navPerms[$perm]));
$groups = [
    ['Catalog', 'bi-grid', [
        ['shops', 'My Shops', 'bi-geo-alt', 'vendor/shops', 'shop.update'],
        ['products', 'Products', 'bi-box-seam', 'vendor/products', 'product.view'],
        ['combos', 'Combo Offers', 'bi-box2', 'vendor/combos', 'combo.manage'],
        ['inventory', 'Inventory & Pricing', 'bi-clipboard-data', 'vendor/inventory', 'inventory.view'],
    ]],
    ['Sales', 'bi-bag', [
        ['pos', 'POS Billing', 'bi-shop', 'vendor/pos', 'pos.sell'],
        ['orders', 'Orders', 'bi-bag', 'vendor/orders', 'order.view.own'],
        ['deliveries', 'Deliveries', 'bi-truck', 'vendor/deliveries', 'delivery.assign'],
        ['refunds', 'Refunds', 'bi-arrow-counterclockwise', 'vendor/refunds', 'refund.process'],
    ]],
    ['Finance', 'bi-cash-stack', [
        ['settlements', 'Settlements', 'bi-cash-stack', 'vendor/settlements', 'settlement.view'],
        ['commission', 'Commission', 'bi-percent', 'vendor/commission', 'commission.view'],
        ['commission-holds', 'Commission Holds', 'bi-hourglass-split', 'vendor/commission-holds', 'commission.hold.view'],
        ['invoices', 'Invoices', 'bi-receipt-cutoff', 'vendor/invoices', 'invoice.view'],
        ['credit-notes', 'Credit Notes', 'bi-receipt', 'vendor/credit-notes', 'creditnote.view'],
        ['gst', 'GST', 'bi-receipt', 'vendor/gst', 'gst.view'],
    ]],
    ['Operations', 'bi-gear-wide-connected', [
        ['transfers', 'Stock Transfers', 'bi-arrow-left-right', 'vendor/transfers', 'transfer.view'],
        ['warehouses', 'Warehouses', 'bi-buildings', 'vendor/warehouses', 'warehouse.view'],
    ]],
    // Procurement — buying stock IN, as opposed to selling it. The purchase-intake
    // screens below were routed and built but had no nav entry at all, so they were
    // reachable only by typing the URL; monline gives them a natural home.
    ['Procurement', 'bi-cart-plus', [
        ['monline', 'Buy on monline', 'bi-shop-window', 'monline/browse', 'monline.browse'],
        ['po', 'Purchase Orders', 'bi-receipt', 'monline/orders', 'monline.po.view'],
        ['purchase', 'Add Inventory', 'bi-box-arrow-in-down', 'vendor/purchase/add', 'inventory.adjust'],
        ['purchase-history', 'Purchase History', 'bi-clock-history', 'vendor/purchase/history', 'inventory.view'],
    ]],
    ['Team', 'bi-people', [
        ['staff', 'Staff & Riders', 'bi-people', 'vendor/staff', 'staff.request'],
        ['media', 'Media Library', 'bi-folder2-open', 'vendor/media', 'media.view'],
        ['profile', 'Business Profile', 'bi-building', 'vendor/profile', 'OWNER'],
        ['documents', 'Documents', 'bi-folder', 'vendor/kyc', ''],
        ['notifications', 'Notifications', 'bi-bell', 'vendor/notifications', ''],
    ]],
    ['Governance', 'bi-check2-square', [
        ['approvals', 'Approvals Inbox', 'bi-check2-square', 'vendor/approvals', 'request.approve.vendor'],
        ['requests', 'My Requests', 'bi-send', 'vendor/requests', ''],
    ]],
];

// Fall back to the current URL when a controller didn't set $active.
if ($active === '') {
    $path = trim(uri_string(), '/');
    if ($path === 'vendor' || $path === 'vendor/dashboard') {
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
        <span><?= esc(service('settingsRepository')->brandName()) ?> <span class="text-primary fw-semibold">VendorHub</span></span>
    </div>
    <?php if (! empty($vendorName)): ?>
        <div class="px-3 py-2 small d-flex align-items-center" style="color:#8b90a7;border-bottom:1px solid #2a2f44">
            <?php if (! empty($vendorLogoUuid)): ?>
                <img src="<?= site_url('media/' . $vendorLogoUuid) ?>" alt="" width="22" height="22" class="rounded me-2" style="object-fit:cover;background:#fff">
            <?php else: ?>
                <i class="bi bi-shop me-2"></i>
            <?php endif; ?>
            <span class="text-truncate"><?= esc($vendorName) ?></span>
        </div>
    <?php endif; ?>
    <nav class="py-2">
        <a class="nav-link <?= $active === 'dashboard' ? 'active' : '' ?>" href="<?= site_url('vendor/dashboard') ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>

        <?php foreach ($groups as $gi => [$header, $gicon, $items]):
            $items = array_values(array_filter($items, static fn ($it) => $visible($it[4] ?? '')));
            if ($items === []) { continue; }
            $hasActive = false;
            foreach ($items as $it) { if ($it[0] === $active) { $hasActive = true; break; } }
        ?>
            <a class="nav-group-toggle" data-bs-toggle="collapse" href="#ven-g<?= $gi ?>" role="button" aria-expanded="<?= $hasActive ? 'true' : 'false' ?>">
                <i class="bi <?= esc($gicon, 'attr') ?>"></i> <span><?= esc($header) ?></span> <i class="bi bi-chevron-right nav-caret"></i>
            </a>
            <div class="collapse <?= $hasActive ? 'show' : '' ?> nav-sub" id="ven-g<?= $gi ?>">
                <?php foreach ($items as [$slug, $label, $icon, $url]): ?>
                    <a class="<?= $active === $slug ? 'active' : '' ?>" href="<?= site_url($url) ?>"><i class="bi <?= esc($icon, 'attr') ?>"></i> <?= esc($label) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>
</aside>
