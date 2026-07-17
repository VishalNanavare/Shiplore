<?php
/**
 * Seeds inventory: each product_variant gets assigned to ~3 shops
 * of the matching category type (shop ID ranges are deterministic
 * because mega2 inserts shops in exact type order).
 *
 * Shop ID ranges (after fresh truncate):
 *   Grocery 139:   shops 1–4 000
 *   Electronics 7: shops 4 001–5 500
 *   Fashion 118:   shops 5 501–7 000
 *   Home 156:      shops 7 001–8 000
 *   Health 169:    shops 8 001–9 000
 *   Sports 184:    shops 9 001–9 500
 *   Footwear 1:    shops 9 501–10 000
 *
 * Total inventory rows ≈ 1 800 000 variants × 3 shops = 5 400 000
 */
declare(strict_types=1);
set_time_limit(0);
ini_set('memory_limit','256M');

$pdo = new PDO('mysql:host=127.0.0.1;dbname=test;charset=utf8mb4','root','root@123',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0; SET autocommit=0');

$catShopRange = [
    139 => [1,    4000],
    7   => [4001, 5500],
    118 => [5501, 7000],
    156 => [7001, 8000],
    169 => [8001, 9000],
    184 => [9001, 9500],
    1   => [9501, 10000],
];

const CHUNK  = 10000; // variants per SELECT
const IBATCH = 900;   // inventory rows per INSERT (3 per variant × 300 variants)

$totalInv   = 0;
$startTime  = microtime(true);

foreach ($catShopRange as $catId => [$shopFrom, $shopTo]) {
    // Load shop IDs for this category into a PHP array
    $shopIds = $pdo->query("SELECT id FROM shops WHERE id BETWEEN $shopFrom AND $shopTo ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $numShops = count($shopIds);
    if (!$numShops) { echo "No shops for cat=$catId\n"; continue; }

    echo "cat=$catId shops=$numShops\n";

    $lastVid    = 0;
    $globalIdx  = 0;  // running count within this category
    $iBuf       = [];
    $committed  = 0;

    $stmt = $pdo->prepare(
        "SELECT pv.id FROM product_variants pv
         JOIN products p ON p.id = pv.product_id
         WHERE p.category_id = ? AND pv.id > ?
         ORDER BY pv.id LIMIT " . CHUNK
    );

    while (true) {
        $stmt->execute([$catId, $lastVid]);
        $vids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$vids) break;

        foreach ($vids as $vid) {
            // Assign 3 shops spread across range using coprime multipliers
            $s1 = $shopIds[($globalIdx)              % $numShops];
            $s2 = $shopIds[($globalIdx * 7  + 1)     % $numShops];
            $s3 = $shopIds[($globalIdx * 13 + 2)     % $numShops];

            $uniqueShops = array_unique([$s1, $s2, $s3]);
            foreach ($uniqueShops as $shopId) {
                $onHand  = rand(5, 200);
                $reorder = rand(5, 25);
                $status  = $onHand <= $reorder ? 'low' : 'in_stock';
                $iBuf[]  = "(UUID(),$vid,$shopId,$onHand,0,$reorder,'$status','server')";
            }
            $globalIdx++;

            if (count($iBuf) >= IBATCH) {
                $pdo->exec("INSERT IGNORE INTO inventory (uuid,variant_id,shop_id,on_hand,reserved,reorder_level,status,origin) VALUES " . implode(',', $iBuf));
                $totalInv += count($iBuf);
                $iBuf = [];
                $committed += IBATCH;
                if ($committed % 90000 === 0) {
                    $pdo->exec('COMMIT; SET autocommit=0');
                    $elapsed = round(microtime(true) - $startTime);
                    echo "  cat=$catId variants=$globalIdx inv=$totalInv [{$elapsed}s]\n";
                }
            }
        }
        $lastVid = end($vids);
    }

    // Flush remaining
    if ($iBuf) {
        $pdo->exec("INSERT IGNORE INTO inventory (uuid,variant_id,shop_id,on_hand,reserved,reorder_level,status,origin) VALUES " . implode(',', $iBuf));
        $totalInv += count($iBuf);
        $iBuf = [];
    }
    $pdo->exec('COMMIT; SET autocommit=0');
    echo "  cat=$catId DONE variants=$globalIdx inv_so_far=$totalInv\n";
}

$pdo->exec('COMMIT; SET FOREIGN_KEY_CHECKS=1; SET autocommit=1');
$finalInv = (int)$pdo->query('SELECT COUNT(*) FROM inventory')->fetchColumn();
echo "\n=== DONE ===\n";
echo "Inventory rows: $finalInv\n";
echo "Time: " . round(microtime(true)-$startTime) . "s\n";
