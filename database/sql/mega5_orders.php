<?php
/**
 * Seeds POS sales: 10–30 per shop, 1–5 items each.
 * Prices are GST-inclusive; 18% GST used uniformly for demo simplicity.
 *
 * Uses every-100th variant as the item pool (~18 000 variants) to
 * avoid loading 1.8 M rows into memory.
 */
declare(strict_types=1);
set_time_limit(0);
ini_set('memory_limit','256M');

$pdo = new PDO('mysql:host=127.0.0.1;dbname=test;charset=utf8mb4','root','root@123',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET autocommit=0');

$vendorId = (int)$pdo->query("SELECT id FROM vendors LIMIT 1")->fetchColumn();
if (!$vendorId) die("Run mega2 first.\n");

// Staff user IDs for cashier assignments
$cashiers = $pdo->query("SELECT id FROM users WHERE email LIKE 'staff%@mumbaibazaar.demo'")->fetchAll(PDO::FETCH_COLUMN);
if (!$cashiers) die("No staff found – run mega2 first.\n");

// Variant pool: sample every 100th variant (≈18 000 rows)
echo "Loading variant pool...\n";
$pool = $pdo->query(
    "SELECT pv.id AS vid, GREATEST(pv.base_price,1) AS price,
            LEFT(p.title,191) AS title, pv.sku AS sku
     FROM product_variants pv
     JOIN products p ON p.id = pv.product_id
     WHERE pv.id % 100 = 0
     ORDER BY pv.id"
)->fetchAll(PDO::FETCH_ASSOC);
$psz = count($pool);
echo "Pool size: $psz\n";
if (!$psz) die("Run mega3 first.\n");

$shopIds = $pdo->query("SELECT id FROM shops ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
echo "Shops: " . count($shopIds) . "\n";

$payMethods  = ['cash','cash','cash','upi','upi','card'];
$cnames      = ['Rahul Sharma','Priya Singh','Amit Kumar','Sunita Patel','Ravi Nair',
                'Deepa Menon','Suresh Yadav','Kavita Joshi','Manoj Tiwari','Anjali Desai',
                'Vijay Pillai','Sonal Shah','Rohan Mehta','Pooja Gupta','Nitin Chavan',
                'Meena Reddy','Arun Iyer','Lakshmi Bose','Kishore Verma','Swati Thakur'];

const SBATCH = 200;   // sales per INSERT
$sBuf = []; $iBuf = []; $sOff = 0;
$totalSales = 0; $totalItems = 0;
$start = microtime(true);

function flushSales(PDO $pdo, array &$sBuf, array &$iBuf, int &$totalSales, int &$totalItems): void {
    if (!$sBuf) return;

    $pdo->exec("INSERT INTO pos_sales
        (uuid,shop_id,vendor_id,cashier_user_id,
         subtotal,discount_total,taxable_value,cgst,sgst,igst,round_off,grand_total,
         payment_method,customer_name,customer_phone,server_invoice_no,sold_at,origin,status)
        VALUES " . implode(',', $sBuf));
    $firstId = (int)$pdo->lastInsertId();
    $totalSales += count($sBuf);

    $iRows = [];
    foreach ($iBuf as $li) {
        $sid = $firstId + $li['so'];
        $iRows[] = "(UUID(),$sid,{$li['vid']},'{$li['title']}','{$li['sku']}',
                     {$li['qty']},{$li['price']},{$li['mrp']},0,
                     {$li['taxbl']},18.00,{$li['cgst']},{$li['sgst']},0,0,1,
                     {$li['ltot']},'server')";
    }
    if ($iRows) {
        $pdo->exec("INSERT INTO pos_sale_items
            (uuid,pos_sale_id,variant_id,product_title_snapshot,sku_snapshot,
             qty,unit_price,mrp_snapshot,discount_amount,taxable_value,tax_rate,
             cgst,sgst,igst,cess,is_gst_inclusive,line_total,origin)
            VALUES " . implode(',', $iRows));
        $totalItems += count($iRows);
    }

    $pdo->exec('COMMIT; SET autocommit=0');
    $sBuf = []; $iBuf = [];
}

foreach ($shopIds as $si => $shopId) {
    $cashier = $cashiers[$si % count($cashiers)];
    $nSales  = rand(10, 30);
    $pfx     = 'POS' . str_pad((string)$shopId, 5, '0', STR_PAD_LEFT);

    for ($s = 0; $s < $nSales; $s++) {
        $soldAt   = date('Y-m-d H:i:s', time() - rand(0, 180 * 86400));
        $invNo    = $pfx . '-' . str_pad((string)$s, 4, '0', STR_PAD_LEFT);
        $pay      = $payMethods[rand(0, count($payMethods)-1)];
        $cname    = addslashes($cnames[rand(0, count($cnames)-1)]);
        $cphone   = '+91' . (9700000000 + rand(0, 99999999));

        $subtotal = 0.0; $taxable = 0.0; $cgst = 0.0; $sgst = 0.0;
        $lines = [];

        for ($i = 0; $i < rand(1, 5); $i++) {
            $v     = $pool[rand(0, $psz-1)];
            $qty   = rand(1, 4);
            $price = (float)$v['price'];
            $ltot  = round($qty * $price, 2);
            $taxbl = round($ltot / 1.18, 2);
            $tax   = round($ltot - $taxbl, 2);
            $lc    = round($tax / 2, 2);
            $ls    = round($tax - $lc, 2);

            $subtotal += $ltot; $taxable += $taxbl; $cgst += $lc; $sgst += $ls;
            $lines[]   = [
                'vid'   => $v['vid'],
                'title' => addslashes(mb_substr($v['title'], 0, 191)),
                'sku'   => addslashes($v['sku']),
                'qty'   => $qty,
                'price' => $price,
                'mrp'   => round($price * 1.15, 2),
                'taxbl' => $taxbl,
                'cgst'  => $lc,
                'sgst'  => $ls,
                'ltot'  => $ltot,
            ];
        }

        $subtotal = round($subtotal, 2);
        $taxable  = round($taxable, 2);
        $cgst     = round($cgst, 2);
        $sgst     = round($sgst, 2);
        $grand    = $subtotal;

        $so = count($sBuf); // 0-based offset in current batch
        $sBuf[] = "(UUID(),$shopId,$vendorId,$cashier,
                    $subtotal,0,$taxable,$cgst,$sgst,0,0,$grand,
                    '$pay','$cname','$cphone','$invNo','$soldAt','server','completed')";
        foreach ($lines as $li) { $li['so'] = $so; $iBuf[] = $li; }

        if (count($sBuf) >= SBATCH) flushSales($pdo, $sBuf, $iBuf, $totalSales, $totalItems);
    }

    if ($si % 1000 === 0) echo "shops=$si sales=$totalSales items=$totalItems ["
        . round(microtime(true)-$start) . "s]\n";
}
flushSales($pdo, $sBuf, $iBuf, $totalSales, $totalItems);
$pdo->exec('SET autocommit=1');

echo "\n=== DONE ===\n";
echo "POS Sales     : " . (int)$pdo->query('SELECT COUNT(*) FROM pos_sales')->fetchColumn() . "\n";
echo "POS Items     : " . (int)$pdo->query('SELECT COUNT(*) FROM pos_sale_items')->fetchColumn() . "\n";
echo "Time          : " . round(microtime(true)-$start) . "s\n";
