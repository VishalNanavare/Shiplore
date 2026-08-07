<?php
/**
 * Converts the single-vendor seed into a 900-vendor Mumbai marketplace.
 *
 * Vendor types map to shop/product ranges set up by mega2–mega4:
 *   Grocery   (cat 139): 400 vendors, shops   1– 4 000, products      1–300 000
 *   Elec      (cat   7): 150 vendors, shops 4001– 5 500, products 300 001–500 000
 *   Fashion   (cat 118): 150 vendors, shops 5501– 7 000, products 500 001–700 000
 *   Home      (cat 156):  60 vendors, shops 7001– 8 000, products 700 001–800 000
 *   Health    (cat 169):  60 vendors, shops 8001– 9 000, products 800 001–900 000
 *   Sports    (cat 184):  40 vendors, shops 9001– 9 500, products 900 001–950 000
 *   Footwear  (cat   1):  40 vendors, shops 9501–10 000, products 950 001–1 000 000
 *   Total                900 vendors, 10 000 shops, 1 000 000 products
 *
 * Edge-cases handled:
 *   - Existing vendor (id=1) kept as grocery vendor #1
 *   - Last vendor in each type absorbs remainder shops/products (no gaps)
 *   - pos_sales.vendor_id re-mapped from shops.vendor_id
 *   - product_variants.vendor_id synced from products.vendor_id
 *   - Every vendor gets ≥1 shop and ≥1 product
 *   - GSTIN unique per vendor (27MBZDxxxxxxR1ZX format)
 *   - Slug unique: kebab-case name + seq
 */
declare(strict_types=1);

require __DIR__ . '/_db.php';
set_time_limit(0);
ini_set('memory_limit','256M');

$pdo = shiplore_pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0; SET autocommit=0');

$start = microtime(true);

// ── area name pool for vendor naming ────────────────────────────────────────
$areaNames = [
    'Colaba','Fort','Churchgate','Nariman Point','Cuffe Parade','Byculla','Grant Road',
    'Marine Lines','Dadar','Lower Parel','Worli','Mahim','Dharavi','Sion','Kurla',
    'Chembur','Ghatkopar','Vikhroli','Bhandup','Mulund','Bandra','Khar','Santacruz',
    'Kalina','Juhu','Vile Parle','Andheri','Jogeshwari','Powai','Goregaon','Malad',
    'Kandivali','Borivali','Dahisar','Mira Road','Thane','Wagle Estate','Majiwada',
    'Vashi','Turbhe','Nerul','Belapur','Kharghar','Panvel','Airoli','Kopar Khairane',
    'Dombivali','Kalyan','Ulhasnagar','Nalasopara','Vasai','Virar',
];
$an = count($areaNames);

// ── vendor type definitions ──────────────────────────────────────────────────
// [type, count, shopFrom, shopTo, prodFrom, prodTo, display_suffix, avg_shops_hint]
$types = [
    ['Grocery',   400, 1,    4000,  1,      300000,  'Foods & Kirana',       10],
    ['Electronics',150, 4001, 5500,  300001, 500000, 'Electronics',          10],
    ['Fashion',   150, 5501, 7000,  500001, 700000,  'Fashion',              10],
    ['Home',       60, 7001, 8000,  700001, 800000,  'Home & Kitchen',       16],
    ['Health',     60, 8001, 9000,  800001, 900000,  'Health & Pharma',      16],
    ['Sports',     40, 9001, 9500,  900001, 950000,  'Sports',               12],
    ['Footwear',   40, 9501, 10000, 950001, 1000000, 'Footwear',             12],
];

// ── helper: split $total items among $n groups with realistic distribution ──
function splitRealistic(int $total, int $n): array {
    if ($n <= 0) return [];
    if ($n === 1) return [$total];
    // Give first ~10% of vendors a 3–8× bigger slice (chain effect)
    $raw = [];
    $bigN = max(1, (int)ceil($n * 0.08));
    for ($i=0;$i<$n;$i++) {
        if ($i < $bigN) {
            $raw[] = rand(5, 12);          // large share weight
        } elseif ($i < $n * 0.35) {
            $raw[] = rand(2, 5);           // medium share weight
        } else {
            $raw[] = rand(1, 2);           // small / micro
        }
    }
    $sum = array_sum($raw);
    $out = []; $assigned = 0;
    foreach ($raw as $i => $r) {
        if ($i === $n - 1) {
            $out[] = max(1, $total - $assigned);
        } else {
            $v = max(1, (int)round($r / $sum * $total));
            $out[] = $v;
            $assigned += $v;
        }
    }
    return $out;
}

// ── build all 899 new vendors (existing vendor id=1 is #0 in grocery) ───────
echo "Building vendor list...\n";

// First type (Grocery): 400 vendors including the EXISTING vendor (id=1)
// So we create only 399 new grocery vendors + 150+150+60+60+40+40 = 500 more = 899 new total
$allNewVendors = []; // [type, display_suffix, area_name, seq_within_type]
$typeSeq = 0; // global sequence for GSTIN uniqueness
foreach ($types as $typeIdx => [$tName, $tCount, $shFrom, $shTo, $prFrom, $prTo, $dispSufx, $avgSh]) {
    $newCount = ($typeIdx === 0) ? $tCount - 1 : $tCount; // first type: 1 already exists
    for ($i=0; $i<$newCount; $i++) {
        $area = $areaNames[$typeSeq % $an];
        $allNewVendors[] = [$tName, $dispSufx, $area, $typeSeq + 2]; // +2 because vendor id=1 exists
        $typeSeq++;
    }
}
// $typeSeq should now be 899 (399 grocery + 150 elec + 150 fsh + 60 home + 60 hlth + 40 spt + 40 ftw)
echo "New vendors to create: " . count($allNewVendors) . "\n";

// ── 1. Bulk-create owner users for all 899 new vendors ───────────────────────
echo "Creating owner users...\n";
$maxUidBefore = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM users')->fetchColumn();
$uBuf = [];
foreach ($allNewVendors as $idx => [$tName, $dispSufx, $area, $seq]) {
    $name  = addslashes("$area $tName Owner");
    $email = "v{$seq}@" . strtolower(preg_replace('/[^a-z]/','',$tName)) . ".demo";
    $phone = '+91' . (8800000000 + $seq);
    $uBuf[] = "(UUID(),'vendor','$name','$email','$phone','active','server')";
    if (count($uBuf) >= 500) {
        $pdo->exec("INSERT INTO users (uuid,principal_type,name,email,phone,status,origin) VALUES " . implode(',', $uBuf));
        $uBuf = [];
    }
}
if ($uBuf) $pdo->exec("INSERT INTO users (uuid,principal_type,name,email,phone,status,origin) VALUES " . implode(',', $uBuf));
$pdo->exec('COMMIT; SET autocommit=0');

// Capture the new user IDs exactly (no ambiguous pattern match)
$ownerUserIds = $pdo->query(
    "SELECT id FROM users WHERE id > $maxUidBefore ORDER BY id"
)->fetchAll(PDO::FETCH_COLUMN);
echo "Owner users: " . count($ownerUserIds) . "\n";

// ── 2. Bulk-create 899 vendors ───────────────────────────────────────────────
echo "Creating vendors...\n";
$vBuf = [];
foreach ($allNewVendors as $idx => [$tName, $dispSufx, $area, $seq]) {
    $ownerId  = $ownerUserIds[$idx] ?? $ownerUserIds[0];
    $legal    = addslashes("$area $dispSufx Pvt Ltd");
    $display  = addslashes("$area $dispSufx");
    $slug     = strtolower(preg_replace('/[^a-z0-9]+/','-',"$area $dispSufx $seq"));
    $gstin    = '27MBZ' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT) . 'R1ZX'; // 2+3+6+4=15 chars
    $pan      = 'MBZD' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT);           // 4+6=10 chars
    $vBuf[] = "(UUID(),$ownerId,'independent','$legal','$display','$slug','$gstin','$pan','27',0,'weekly','active','server')";
    if (count($vBuf) >= 500) {
        $pdo->exec("INSERT INTO vendors (uuid,owner_user_id,vendor_kind,legal_name,display_name,slug,gstin,pan,state_code,is_composition,payout_cycle,status,origin) VALUES " . implode(',', $vBuf));
        $vBuf = [];
    }
}
if ($vBuf) $pdo->exec("INSERT INTO vendors (uuid,owner_user_id,vendor_kind,legal_name,display_name,slug,gstin,pan,state_code,is_composition,payout_cycle,status,origin) VALUES " . implode(',', $vBuf));
$pdo->exec('COMMIT; SET autocommit=0');

$allVendorIds = $pdo->query("SELECT id FROM vendors ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
echo "Total vendors: " . count($allVendorIds) . "\n";

// ── 3. Distribute shops → UPDATE shops.vendor_id ─────────────────────────────
echo "Distributing shops...\n";
$vidIdx = 0; // index into $allVendorIds
foreach ($types as [$tName, $tCount, $shFrom, $shTo, $prFrom, $prTo, $dispSufx, $avgSh]) {
    $shopsInRange = $shTo - $shFrom + 1;
    $shopCounts   = splitRealistic($shopsInRange, $tCount);

    $curShop = $shFrom;
    foreach ($shopCounts as $cnt) {
        $vid   = $allVendorIds[$vidIdx];
        $endSh = min($curShop + $cnt - 1, $shTo);
        $pdo->exec("UPDATE shops SET vendor_id=$vid WHERE id BETWEEN $curShop AND $endSh");
        $curShop = $endSh + 1;
        $vidIdx++;
        if ($curShop > $shTo) break;
    }
    // Assign any remaining shops (rounding residue) to the last vendor of this type
    if ($curShop <= $shTo) {
        $lastVid = $allVendorIds[$vidIdx - 1];
        $pdo->exec("UPDATE shops SET vendor_id=$lastVid WHERE id BETWEEN $curShop AND $shTo");
    }

    $pdo->exec('COMMIT; SET autocommit=0');
    echo "  $tName: shops $shFrom–$shTo → $tCount vendors\n";
}

// ── 4. Distribute products → UPDATE products.vendor_id ───────────────────────
echo "Distributing products...\n";
$vidIdx = 0;
foreach ($types as [$tName, $tCount, $shFrom, $shTo, $prFrom, $prTo, $dispSufx, $avgSh]) {
    $prodsInRange = $prTo - $prFrom + 1;
    $prodCounts   = splitRealistic($prodsInRange, $tCount);

    $curProd = $prFrom;
    foreach ($prodCounts as $cnt) {
        $vid    = $allVendorIds[$vidIdx];
        $endPr  = min($curProd + $cnt - 1, $prTo);
        $pdo->exec("UPDATE products SET vendor_id=$vid WHERE id BETWEEN $curProd AND $endPr");
        $curProd = $endPr + 1;
        $vidIdx++;
        if ($curProd > $prTo) break;
    }
    if ($curProd <= $prTo) {
        $lastVid = $allVendorIds[$vidIdx - 1];
        $pdo->exec("UPDATE products SET vendor_id=$lastVid WHERE id BETWEEN $curProd AND $prTo");
    }

    // Commit every type (each type updates 50K–300K rows; keep transaction manageable)
    $pdo->exec('COMMIT; SET autocommit=0');
    echo "  $tName: products $prFrom–$prTo → $tCount vendors\n";
}

// ── 5. Sync product_variants.vendor_id from products ────────────────────────
echo "Syncing variant vendor_ids (this may take a moment)...\n";
// Do in product ID chunks to avoid giant single UPDATE
$chunkSz = 100000;
for ($from=1; $from<=1000000; $from+=$chunkSz) {
    $to = $from + $chunkSz - 1;
    $pdo->exec("UPDATE product_variants pv
                JOIN products p ON p.id = pv.product_id
                SET pv.vendor_id = p.vendor_id
                WHERE p.id BETWEEN $from AND $to");
    $pdo->exec('COMMIT; SET autocommit=0');
    echo "  variants synced for products $from–$to\n";
}

// ── 6. Fix pos_sales.vendor_id to match each sale's shop ────────────────────
echo "Fixing pos_sales.vendor_id...\n";
$pdo->exec("UPDATE pos_sales ps
            JOIN shops s ON s.id = ps.shop_id
            SET ps.vendor_id = s.vendor_id");
$pdo->exec('COMMIT; SET autocommit=0');
echo "  pos_sales updated\n";

// ── 7. Add 1 branch_manager record per new vendor (owner as manager) ─────────
echo "Adding vendor_staff for new vendors...\n";
$staffBuf = [];
foreach ($allNewVendors as $idx => [$tName, $dispSufx, $area, $seq]) {
    $vid     = $allVendorIds[$idx + 1]; // +1 because index 0 is vendor id=1
    $ownerId = $ownerUserIds[$idx];
    $ecode   = 'OWN' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    $staffBuf[] = "(UUID(),$ownerId,$vid,'manager','$ecode','active','server')";
    if (count($staffBuf) >= 500) {
        $pdo->exec("INSERT INTO vendor_staff (uuid,user_id,vendor_id,staff_type,employee_code,status,origin) VALUES " . implode(',', $staffBuf));
        $staffBuf = [];
        $pdo->exec('COMMIT; SET autocommit=0');
    }
}
if ($staffBuf) {
    $pdo->exec("INSERT INTO vendor_staff (uuid,user_id,vendor_id,staff_type,employee_code,status,origin) VALUES " . implode(',', $staffBuf));
    $pdo->exec('COMMIT');
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=1; SET autocommit=1');

// ── Verification ─────────────────────────────────────────────────────────────
echo "\n=== VERIFICATION ===\n";
echo "Total vendors  : " . (int)$pdo->query('SELECT COUNT(*) FROM vendors')->fetchColumn() . "\n";
echo "Total shops    : " . (int)$pdo->query('SELECT COUNT(*) FROM shops')->fetchColumn() . "\n";
echo "Shops no vendor: " . (int)$pdo->query('SELECT COUNT(*) FROM shops WHERE vendor_id IS NULL')->fetchColumn() . "\n";
echo "Min shops/vendor: " . (int)$pdo->query('SELECT MIN(c) FROM (SELECT COUNT(*) c FROM shops GROUP BY vendor_id) t')->fetchColumn() . "\n";
echo "Max shops/vendor: " . (int)$pdo->query('SELECT MAX(c) FROM (SELECT COUNT(*) c FROM shops GROUP BY vendor_id) t')->fetchColumn() . "\n";
echo "Avg shops/vendor: " . round((float)$pdo->query('SELECT AVG(c) FROM (SELECT COUNT(*) c FROM shops GROUP BY vendor_id) t')->fetchColumn(), 1) . "\n";
echo "Products vendor mismatch: " . (int)$pdo->query('SELECT COUNT(*) FROM product_variants pv JOIN products p ON p.id=pv.product_id WHERE pv.vendor_id != p.vendor_id')->fetchColumn() . "\n";
echo "Vendor staff   : " . (int)$pdo->query('SELECT COUNT(*) FROM vendor_staff')->fetchColumn() . "\n";
echo "POS sales       : " . (int)$pdo->query('SELECT COUNT(*) FROM pos_sales')->fetchColumn() . "\n";
echo "Time            : " . round(microtime(true)-$start) . "s\n";

echo "\n=== Vendor shop distribution (by type) ===\n";
foreach ($pdo->query("
    SELECT v.display_name, COUNT(s.id) AS shop_count
    FROM vendors v
    JOIN shops s ON s.vendor_id = v.id
    GROUP BY v.id ORDER BY shop_count DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC) as $r)
    echo "  {$r['display_name']}: {$r['shop_count']} shops\n";
