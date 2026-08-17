<?php
$active = $active ?? '';
// Each item: [slug, label, icon, url, permission]. Owners see everything; unit staff
// see only items whose permission their role grants. Mirrors _vendor_sidebar.php, but
// kept separate so the vendor nav is never touched.
$navIsOwner = $navIsOwner ?? true;
$navPerms   = $navPerms ?? [];
// perm 'OWNER' = owner-only; '' = everyone.
$visible = static fn (string $perm): bool => $perm === 'OWNER' ? $navIsOwner : ($navIsOwner || $perm === '' || isset($navPerms[$perm]));

// Deliberately NO delivery, rider, POS or serviceability entries — manufacturers
// do none of those. Orders arrive as monline purchase orders (phase B).
$groups = [
    ['Catalog', 'bi-grid', [
        ['units', 'Units', 'bi-buildings', 'manufacturer/units', 'mfg.unit.view'],
        ['products', 'Products', 'bi-box-seam', 'manufacturer/products', 'mfg.product.view'],
    ]],
    ['Sales', 'bi-bag', [
        ['orders', 'Purchase Orders', 'bi-receipt', 'manufacturer/purchase-orders', 'mfg.po.view'],
    ]],
    // Business identity and the per-user feed. Mirrors the vendor panel's Team group;
    // staff, media and documents join it as those screens land.
    ['Team', 'bi-people', [
        ['staff', 'Staff', 'bi-people', 'manufacturer/staff', 'mfg.staff.view'],
        ['profile', 'Business Profile', 'bi-building', 'manufacturer/profile', 'OWNER'],
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
