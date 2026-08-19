<?php
$active = $active ?? '';
// Each item: [slug, label, icon, url, permission]. Owners see everything; unit staff
// see only items whose permission their role grants. Mirrors _vendor_sidebar.php, but
// kept separate so the vendor nav is never touched.
$navIsOwner = $navIsOwner ?? true;
$navPerms   = $navPerms ?? [];
// perm 'OWNER' = owner-only; '' = everyone.
$visible = static fn (string $perm): bool => $perm === 'OWNER' ? $navIsOwner : ($navIsOwner || $perm === '' || isset($navPerms[$perm]));

// Orders arrive as monline purchase orders, never as consumer orders — manufacturers
// sell B2B only, which is enforced upstream by is_online_enabled=0 / visibility='vendor'
// on their products and by the party_type exclusion in StoreCatalogRepository.
//
// Delivery and rider entries were deliberately absent here for a long time, on the
// grounds that manufacturers do not deliver; that decision was reversed when full
// parity with the vendor panel was asked for (77_manufacturer_delivery.sql). Both are
// permission-gated, so a manufacturer that does not deliver simply never grants them.
$groups = [
    ['Catalog', 'bi-grid', [
        ['units', 'Units', 'bi-buildings', 'manufacturer/units', 'mfg.unit.view'],
        ['products', 'Products', 'bi-box-seam', 'manufacturer/products', 'mfg.product.view'],
        ['inventory', 'Stock', 'bi-clipboard-data', 'manufacturer/inventory', 'mfg.inventory.view'],
        ['combos', 'Combo Offers', 'bi-boxes', 'manufacturer/combos', 'mfg.combo.manage'],
    ]],
    ['Sales', 'bi-bag', [
        ['pos', 'Counter', 'bi-shop', 'manufacturer/pos', 'mfg.pos.view'],
        ['returns', 'Returns', 'bi-arrow-return-left', 'manufacturer/pos/returns', 'mfg.pos.return'],
        ['orders', 'Purchase Orders', 'bi-receipt', 'manufacturer/purchase-orders', 'mfg.po.view'],
        ['deliveries', 'Deliveries', 'bi-truck', 'manufacturer/deliveries', 'mfg.delivery.assign'],
        ['riders', 'Riders', 'bi-person-badge', 'manufacturer/riders', 'mfg.rider.manage'],
    ]],
    // Operations. Stock moving between this manufacturer's own units — a warehouse is
    // a KIND of mshop (81_mfg_warehouses.sql), so it reuses mfg_inventory and the unit
    // switcher rather than needing a parallel table.
    ['Operations', 'bi-arrow-left-right', [
        ['transfers', 'Stock Transfers', 'bi-arrow-left-right', 'manufacturer/transfers', 'mfg.transfer.view'],
    ]],
    // Finance. The vendor panel has six entries here (Settlements, Commission,
    // Commission Holds, Invoices, Credit Notes, GST) and this panel had none — a
    // manufacturer could sell through the platform and never see a rupee of it.
    // Earnings is the first and the one that matters; the rest need a B2B payout cycle
    // and commission rate to be decided before they mean anything.
    ['Finance', 'bi-cash-coin', [
        ['earnings', 'Earnings', 'bi-graph-up-arrow', 'manufacturer/earnings', 'mfg.invoice.view'],
        ['settlements', 'Settlements', 'bi-cash-stack', 'manufacturer/settlements', 'mfg.settlement.view'],
    ]],
    // Business identity and the per-user feed. Mirrors the vendor panel's Team group;
    // staff, media and documents join it as those screens land.
    ['Governance', 'bi-check2-square', [
        ['approvals', 'Approvals Inbox', 'bi-check2-square', 'manufacturer/approvals', 'mfg.request.approve'],
        ['requests', 'My Requests', 'bi-send', 'manufacturer/requests', ''],
    ]],
    ['Team', 'bi-people', [
        ['staff', 'Staff', 'bi-people', 'manufacturer/staff', 'mfg.staff.view'],
        ['media', 'Media Library', 'bi-folder2-open', 'manufacturer/media', 'mfg.media.view'],
        ['profile', 'Business Profile', 'bi-building', 'manufacturer/profile', 'OWNER'],
        ['documents', 'Documents', 'bi-folder', 'manufacturer/documents', 'mfg.document.view'],
        ['notifications', 'Notifications', 'bi-bell', 'manufacturer/notifications', ''],
    ]],
];

// Fall back to the current URL when a controller didn't set $active.
if ($active === '') {
    $path = trim(uri_string(), '/');
    if ($path === 'manufacturer' || $path === 'manufacturer/dashboard') {
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
        <span><?= esc(service('settingsRepository')->brandName()) ?> <span class="text-primary fw-semibold">MakerHub</span></span>
    </div>
    <?php if (! empty($manufacturerName)): ?>
        <div class="px-3 py-2 small d-flex align-items-center" style="color:#8b90a7;border-bottom:1px solid #2a2f44">
            <i class="bi bi-gear-wide-connected me-2"></i>
            <span class="text-truncate"><?= esc($manufacturerName) ?></span>
        </div>
    <?php endif; ?>
    <nav class="py-2">
        <a class="nav-link <?= $active === 'dashboard' ? 'active' : '' ?>" href="<?= site_url('manufacturer/dashboard') ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>

        <?php foreach ($groups as $gi => [$header, $gicon, $items]):
            $items = array_values(array_filter($items, static fn ($it) => $visible($it[4] ?? '')));
            if ($items === []) { continue; }
            $hasActive = false;
            foreach ($items as $it) { if ($it[0] === $active) { $hasActive = true; break; } }
        ?>
            <a class="nav-group-toggle" data-bs-toggle="collapse" href="#mfg-g<?= $gi ?>" role="button" aria-expanded="<?= $hasActive ? 'true' : 'false' ?>">
                <i class="bi <?= esc($gicon, 'attr') ?>"></i> <span><?= esc($header) ?></span> <i class="bi bi-chevron-right nav-caret"></i>
            </a>
            <div class="collapse <?= $hasActive ? 'show' : '' ?> nav-sub" id="mfg-g<?= $gi ?>">
                <?php foreach ($items as [$slug, $label, $icon, $url]): ?>
                    <a class="<?= $active === $slug ? 'active' : '' ?>" href="<?= site_url($url) ?>"><i class="bi <?= esc($icon, 'attr') ?>"></i> <?= esc($label) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>
</aside>
