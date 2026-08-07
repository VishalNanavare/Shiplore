<?php
/**
 * Fixes two blockers that make the customer home page blank:
 *
 * 1. delivery_radius_km → NULL on all shops.
 *    Set to a random 2.0–4.0 km per shop (bulk UPDATE in 1 query using
 *    a VALUES() trick to avoid 10 000 individual queries).
 *
 * 2. product_shops → 0 rows.
 *    The customer home API filters categories/products via:
 *      EXISTS (SELECT 1 FROM product_shops WHERE shop_id IN (...))
 *    Without rows here, every category shows count=0 → "No categories yet".
 *    Each shop gets all products from its category vendor linked.
 *    We insert (product_id, shop_id, status='active') for every
 *    inventory row (which already correctly links shop↔variant).
 *
 * 3. serviceable_areas (pincode coverage) → 0 rows.
 *    Seed pincodes from the shop address_json so delivery-area checks pass.
 */
declare(strict_types=1);

require __DIR__ . '/_db.php';
set_time_limit(0);
ini_set('memory_limit','512M');

$pdo = shiplore_pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0; SET autocommit=0');
$start = microtime(true);

// ── 1. delivery_radius_km: random 2.00–4.00 per shop ─────────────────────────
echo "Setting delivery_radius_km...\n";

// Build a CASE expression: one value per shop using batch SELECTs
// Faster: do it in chunks of 2000 shops using a VALUES-based temp join
$shopIds = $pdo->query("SELECT id FROM shops ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$total   = count($shopIds);
$chunkSz = 2000;

for ($i = 0; $i < $total; $i += $chunkSz) {
    $chunk  = array_slice($shopIds, $i, $chunkSz);
    $cases  = [];
    foreach ($chunk as $sid) {
        $radius = number_format(mt_rand(200, 400) / 100, 2, '.', '');
        $cases[] = "WHEN id=$sid THEN $radius";
    }
    $pdo->exec("UPDATE shops SET delivery_radius_km = CASE " . implode(' ', $cases) . " END
                WHERE id IN (" . implode(',', $chunk) . ")");
    $pdo->exec('COMMIT; SET autocommit=0');
    echo "  updated shops " . ($i+1) . "–" . ($i + count($chunk)) . "\n";
}

$checkRadius = (float)$pdo->query('SELECT AVG(delivery_radius_km) FROM shops')->fetchColumn();
echo "  avg delivery_radius_km = " . round($checkRadius, 2) . " km\n";

// ── 2. product_shops: link each product to shops via existing inventory ────────
echo "\nPopulating product_shops from inventory...\n";

// product_shops columns
$cols = $pdo->query('SHOW COLUMNS FROM product_shops')->fetchAll(PDO::FETCH_ASSOC);
echo "  product_shops columns: " . implode(', ', array_column($cols, 'Field')) . "\n";

// Derive product_id from inventory via product_variants
// inventory has: variant_id, shop_id
// product_variants has: id, product_id
// We want: INSERT IGNORE INTO product_shops(product_id, shop_id, status)
//   SELECT DISTINCT pv.product_id, i.shop_id, 'active'
//   FROM inventory i JOIN product_variants pv ON pv.id = i.variant_id
//
// 5.4M inventory rows → ~5.4M distinct (product_id, shop_id) pairs but many are same
// (each product has 1-3 variants, each variant in 3 shops → avg 3-9 rows per product per shop)
// We process in variant_id chunks to keep memory manageable.

// Check what columns product_shops actually has (pivot vs full table)
$psColNames = array_column($cols, 'Field');
echo "  columns: " . implode(', ', $psColNames) . "\n";

// product_categories is a pivot table with (product_id, category_id)
// We want product_shops which has (product_id, shop_id, ...)
// Check if it has 'status' column
$hasStatus  = in_array('status',  $psColNames);
$hasVendor  = in_array('vendor_id', $psColNames);
$hasListPr  = in_array('list_price', $psColNames);

echo "  has_status=$hasStatus has_vendor=$hasVendor has_list_price=$hasListPr\n";

// Build INSERT based on available columns
$maxVariantId = (int)$pdo->query('SELECT MAX(id) FROM product_variants')->fetchColumn();
$chunkV = 50000; // variants per chunk → ~150K inventory rows each
$inserted = 0;

for ($vFrom = 1; $vFrom <= $maxVariantId; $vFrom += $chunkV) {
    $vTo = $vFrom + $chunkV - 1;

    if ($hasStatus && $hasVendor) {
        $sql = "INSERT IGNORE INTO product_shops (product_id, vendor_id, shop_id, status)
                SELECT DISTINCT pv.product_id, p.vendor_id, i.shop_id, 'active'
                FROM inventory i
                JOIN product_variants pv ON pv.id = i.variant_id
                JOIN products p ON p.id = pv.product_id
                WHERE i.variant_id BETWEEN $vFrom AND $vTo";
    } elseif ($hasStatus) {
        $sql = "INSERT IGNORE INTO product_shops (product_id, shop_id, status)
                SELECT DISTINCT pv.product_id, i.shop_id, 'active'
                FROM inventory i
                JOIN product_variants pv ON pv.id = i.variant_id
                WHERE i.variant_id BETWEEN $vFrom AND $vTo";
    } else {
        $sql = "INSERT IGNORE INTO product_shops (product_id, shop_id)
                SELECT DISTINCT pv.product_id, i.shop_id, 'active'
                FROM inventory i
                JOIN product_variants pv ON pv.id = i.variant_id
                WHERE i.variant_id BETWEEN $vFrom AND $vTo";
    }

    $pdo->exec($sql);
    $pdo->exec('COMMIT; SET autocommit=0');
    $inserted = (int)$pdo->query('SELECT COUNT(*) FROM product_shops')->fetchColumn();
    echo "  variants $vFrom–$vTo done | product_shops=$inserted [" . round(microtime(true)-$start) . "s]\n";
}

// ── 3. serviceable_areas: seed from shop pincodes ────────────────────────────
echo "\nPopulating serviceable_areas...\n";
$saColNames = array_column($pdo->query('SHOW COLUMNS FROM serviceable_areas')->fetchAll(PDO::FETCH_ASSOC), 'Field');
echo "  columns: " . implode(', ', $saColNames) . "\n";

// Extract distinct pincodes from address_json
$pincodes = $pdo->query(
    "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(address_json, '$.pincode')) AS pc
     FROM shops
     WHERE JSON_EXTRACT(address_json, '$.pincode') IS NOT NULL
       AND JSON_EXTRACT(address_json, '$.pincode') != 'null'"
)->fetchAll(PDO::FETCH_COLUMN);

echo "  distinct pincodes: " . count($pincodes) . "\n";

if (count($pincodes) > 0) {
    $saBuf = [];
    foreach ($pincodes as $pc) {
        $pc = (int)$pc;
        if ($pc < 100000) continue; // skip invalid
        if (in_array('is_serviceable', $saColNames)) {
            $saBuf[] = "('$pc', 1)";
        } else {
            $saBuf[] = "('$pc')";
        }
        if (count($saBuf) >= 200) {
            $cols_str = in_array('is_serviceable', $saColNames)
                ? "(pincode, is_serviceable)"
                : "(pincode)";
            $pdo->exec("INSERT IGNORE INTO serviceable_areas $cols_str VALUES " . implode(',', $saBuf));
            $saBuf = [];
        }
    }
    if ($saBuf) {
        $cols_str = in_array('is_serviceable', $saColNames)
            ? "(pincode, is_serviceable)"
            : "(pincode)";
        $pdo->exec("INSERT IGNORE INTO serviceable_areas $cols_str VALUES " . implode(',', $saBuf));
    }
    $pdo->exec('COMMIT');
    echo "  serviceable_areas inserted: " . (int)$pdo->query('SELECT COUNT(*) FROM serviceable_areas')->fetchColumn() . "\n";
} else {
    echo "  No pincodes found in address_json — skipping serviceable_areas\n";
    $pdo->exec('COMMIT');
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=1; SET autocommit=1');

// ── Verification ─────────────────────────────────────────────────────────────
echo "\n=== VERIFICATION ===\n";
echo "shops with delivery_radius_km : " . (int)$pdo->query('SELECT COUNT(*) FROM shops WHERE delivery_radius_km IS NOT NULL')->fetchColumn() . "\n";
echo "shops min/max radius_km       : " . $pdo->query('SELECT CONCAT(MIN(delivery_radius_km),"/",MAX(delivery_radius_km)) FROM shops')->fetchColumn() . "\n";
echo "product_shops rows            : " . (int)$pdo->query('SELECT COUNT(*) FROM product_shops')->fetchColumn() . "\n";
echo "serviceable_areas rows        : " . (int)$pdo->query('SELECT COUNT(*) FROM serviceable_areas')->fetchColumn() . "\n";

// Simulate home API: categories with product_shops
echo "\n=== Categories (as home API sees them) ===\n";
$cats = $pdo->query("
    SELECT c.name, COUNT(DISTINCT ps.product_id) cnt
    FROM categories c
    JOIN products p ON p.category_id = c.id
    JOIN product_shops ps ON ps.product_id = p.id
    WHERE c.parent_id IS NULL
    GROUP BY c.id HAVING cnt > 0
    ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cats as $r) echo "  {$r['name']}: {$r['cnt']} products in shops\n";

echo "\nTime: " . round(microtime(true)-$start) . "s\n";
