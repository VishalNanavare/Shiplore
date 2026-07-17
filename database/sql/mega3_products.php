<?php
/**
 * Seeds 1 000 000 products + ~1 800 000 variants
 * Category split:
 *   Grocery (139):   100 brands × 30 types × 10 qual × 10 size = 300 000
 *   Electronics (7): 80 × 25 × 10 × 10 = 200 000
 *   Fashion (118):   80 × 25 × 10 × 10 = 200 000
 *   Home (156):      50 × 20 × 10 × 10 = 100 000
 *   Health (169):    50 × 20 × 10 × 10 = 100 000
 *   Sports (184):    25 × 20 × 10 × 10 =  50 000
 *   Footwear (1):    25 × 20 × 10 × 10 =  50 000
 *
 * Variants: cnt%10<4 → 1 var, cnt%10<8 → 2 vars, else 3 vars  (avg 1.8)
 */
declare(strict_types=1);
set_time_limit(0);
ini_set('memory_limit','512M');

$pdo = new PDO('mysql:host=127.0.0.1;dbname=test;charset=utf8mb4','root','root@123',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0; SET autocommit=0');

$vendorId = (int)$pdo->query("SELECT id FROM vendors LIMIT 1")->fetchColumn();
if (!$vendorId) die("Run mega2 first.\n");

// ── helpers ──────────────────────────────────────────────────────────────────
function expand(array $base, int $target, array $suffixes=['Plus','Gold','Premium','Select','Special','Lite','Pro','Max','Ultra','Natural']): array {
    $out = $base;
    while (count($out) < $target) {
        $idx = count($out) - count($base);
        $out[] = $base[$idx % count($base)] . ' ' . $suffixes[$idx % count($suffixes)];
    }
    return array_slice($out, 0, $target);
}
function slug(string $title, int $n): string {
    return strtolower(preg_replace('/[^a-z0-9]+/','-',strtolower($title))) . '-' . $n;
}
function varCount(int $n): int { $r=$n%10; if($r<4)return 1; if($r<8)return 2; return 3; }

// ── category definitions ──────────────────────────────────────────────────────
// [prefix, cat_id, tax_class_id, unit_id, [min_price, max_price], brands(30+), types(20+), qual(10), size(10)]

$GRC_BRANDS = expand(['Amul','Nestle','Britannia','Parle','ITC Aashirvaad','Dabur','Marico',
    'Godrej','Patanjali','Haldiram','MTR','Everest Spices','MDH','Tata Consumer',
    'Fortune','Saffola','Dhara','Kohinoor','Daawat','India Gate','Pillsbury',
    'Shakti Bhog','Annapurna','Kissan','Maggi','Knorr','Del Monte','Veeba',
    'Paper Boat','Tropicana'],100);
$GRC_TYPES = expand(['Rice','Dal','Atta','Oil','Ghee','Butter','Milk Powder','Cheese','Sugar','Salt',
    'Tea','Coffee','Biscuits','Noodles','Masala Blend','Pulses','Snack Mix','Juice','Sauce','Rava'],30);
$GRC_QUAL  = ['Regular','Premium','Classic','Special','Select','Gold','Pure','Natural','Organic','Economy'];
$GRC_SIZES = ['100g','200g','250g','500g','1kg','2kg','5kg','10kg','Pkt-6','Combo'];

$ELC_BRANDS = expand(['Samsung','Apple','OnePlus','Xiaomi','Realme','boAt','Sony','LG',
    'Philips','Panasonic','Havells','Bajaj Electricals','Crompton','Orient','Usha',
    'Zebronics','Logitech','Dell','HP','Lenovo','Asus','Acer','Canon','Nikon','Bose'],80);
$ELC_TYPES = expand(['Mobile Phone','Laptop','Tablet','Headphone','Earbuds','Smart Watch','LED TV',
    'Air Conditioner','Refrigerator','Washing Machine','Mixer Grinder','Induction Cooktop',
    'Power Bank','Charger','Router','Webcam','Monitor','Keyboard','Mouse','Speaker'],25);
$ELC_MDLS  = ['X10','X20','X30','Pro','Pro Max','Lite','Ultra','Neo','Edge','Apex'];
$ELC_SPECS = ['2GB','4GB','6GB','8GB','12GB','16GB','32GB','64GB','128GB','256GB'];

$FSH_BRANDS = expand(['Nike','Adidas','Puma','Levis','Arrow','Van Heusen','Allen Solly',
    'Louis Philippe','Park Avenue','Peter England','Woodland','Bata','Red Chief',
    'Mochi','Myntra Select','H&M','Zara','UCB','Spykar','Killer'],80);
$FSH_TYPES = expand(['T-Shirt','Jeans','Shirt','Trousers','Saree','Kurta','Salwar Suit',
    'Dress','Jacket','Sweatshirt','Shorts','Skirt','Leggings','Blazer','Track Suit',
    'Polo Shirt','Chinos','Cargo Pants','Kurti','Ethnic Wear'],25);
$FSH_STYLE = ['Slim Fit','Regular Fit','Relaxed Fit','Classic Fit','Sport Fit',
              'Tapered','Straight Cut','Flared','Cropped','Oversized'];
$FSH_COLORS= ['Black','White','Blue','Red','Green','Grey','Navy','Brown','Beige','Maroon'];

$HMK_BRANDS = expand(['Prestige','Hawkins','Pigeon','Morphy Richards','Bajaj Electricals',
    'Havells','Crompton','Usha','Butterfly','Wonderchef','Borosil','Tupperware',
    'Cello','Milton','Nayasa','Signoraware','La Opala','Treo','Vinod','Paderno'],50);
$HMK_TYPES = expand(['Pressure Cooker','Non-Stick Pan','Kadai','Mixer Grinder','OTG Oven',
    'Water Bottle','Lunch Box','Dinner Set','Casserole','Storage Container',
    'Rice Cooker','Air Fryer','Induction Base','Cutting Board','Kettle',
    'Hand Blender','Toaster','Coffee Maker','Sandwich Maker','Microwave Oven'],20);
$HMK_MATL  = ['Stainless Steel','Aluminium','Hard Anodised','Granite Coat','Triply',
               'Borosilicate','Melamine','Plastic','Copper Bottom','Cast Iron'];
$HMK_SZHS  = ['0.5L','1L','1.5L','2L','3L','5L','6L','10L','16pc Set','6pc Set'];

$HLT_BRANDS = expand(['Himalaya','Dabur','Patanjali','Mamaearth','WOW','Minimalist',
    'Plum','mCaffeine','Derma Co','Cetaphil','Neutrogena','Lakmé','Garnier',
    'L Oreal','Dove','Nivea','Head Shoulders','Pantene','Sunsilk','TRESemme'],50);
$HLT_TYPES = expand(['Face Wash','Moisturizer','Sunscreen','Serum','Shampoo','Conditioner',
    'Body Lotion','Hair Oil','Face Mask','Lip Balm','Eye Cream','Toner',
    'Face Scrub','Hair Conditioner','Body Wash','Kajal','Lipstick','Foundation',
    'Concealer','Blush'],20);
$HLT_FORM  = ['Gel','Cream','Lotion','Foam','Oil','Serum','Spray','Paste','Balm','Powder'];
$HLT_SIZE  = ['10ml','15ml','20ml','30ml','50ml','75ml','100ml','150ml','200ml','300ml'];

$SPT_BRANDS = expand(['Nivia','Yonex','Victor','Cosco','SG','SS Cricket','DSC','Puma Sport',
    'Adidas Sport','Asics','Reebok','New Balance','Boldfit','Strauss','Lifelong',
    'Kore','Burnlab','Joerex','Cosco','Spartan'],25);
$SPT_TYPES = expand(['Football','Cricket Bat','Badminton Racket','Volleyball','Basketball',
    'Running Shoes','Gym Gloves','Dumbbell','Resistance Band','Yoga Mat',
    'Jump Rope','Gym Bag','Batting Gloves','Batting Pads','Sports Jersey',
    'Track Suit','Water Bottle Sport','Protein Shaker','Foam Roller','Ab Roller'],20);
$SPT_MDLS  = ['Pro','Lite','Elite','Champion','Titan','Ace','Force','Strike','Power','Speed'];
$SPT_SZS   = ['XS','S','M','L','XL','XXL','UK6','UK7','UK8','UK9'];

$FTW_BRANDS = expand(['Bata','Woodland','Red Chief','Mochi','Liberty','Relaxo','Action','Metro',
    'Paragon','Lakhani','Khadim','VKC','Lehar','Chappal','Campus','Skechers',
    'Sparx','Power','Wellman','Foot In'],25);
$FTW_TYPES = expand(['Sandals','Slippers','Sports Shoes','Formal Shoes','Casual Shoes',
    'Boots','Loafers','Oxfords','Mules','Wedges','Pumps','Flip Flops',
    'Running Shoes','Walking Shoes','Derby Shoes','Monk Strap','Chelsea Boots',
    'Ankle Boots','Kolhapuri','Ethnic Footwear'],20);
$FTW_STYLE = ['Classic','Comfort','Trendy','Sporty','Ethnic','Contemporary','Premium',
              'Casual','Formal','Fashion'];
$FTW_SIZES = ['UK5','UK6','UK7','UK8','UK9','UK10','UK11','EU38','EU40','EU42'];

// [prefix, cat_id, tax_cls, unit_id, min_p, max_p, b,  t,  q,  s,  nb, nt, nq, ns]
$catDefs = [
    ['GRC',139,2,1,  30, 900, $GRC_BRANDS,$GRC_TYPES,$GRC_QUAL,$GRC_SIZES, 100,30,10,10],
    ['ELC',  7,4,1,1000,80000,$ELC_BRANDS,$ELC_TYPES,$ELC_MDLS,$ELC_SPECS,  80,25,10,10],
    ['FSH',118,3,1, 299,5999, $FSH_BRANDS,$FSH_TYPES,$FSH_STYLE,$FSH_COLORS,80,25,10,10],
    ['HMK',156,3,1, 299,9999, $HMK_BRANDS,$HMK_TYPES,$HMK_MATL,$HMK_SZHS, 50,20,10,10],
    ['HLT',169,3,5,  99,1999, $HLT_BRANDS,$HLT_TYPES,$HLT_FORM,$HLT_SIZE,  50,20,10,10],
    ['SPT',184,4,1, 299,15000,$SPT_BRANDS,$SPT_TYPES,$SPT_MDLS,$SPT_SZS,   25,20,10,10],
    ['FTW',  1,4,1, 499,9999, $FTW_BRANDS,$FTW_TYPES,$FTW_STYLE,$FTW_SIZES,25,20,10,10],
];

// ── variant price multipliers ─────────────────────────────────────────────────
$varMult = [1.0, 1.8, 3.5];
$varSuffix = ['A','B','C'];

// ── insert buffers ────────────────────────────────────────────────────────────
const PBATCH = 500; // products per INSERT
$pBuf = []; $vBuf = []; $pCnt = 0; $vCnt = 0;
$startTime = microtime(true);

function flush_products(PDO $pdo, array &$pBuf, array &$vBuf, int &$pCnt, int &$vCnt): void {
    if (!$pBuf) return;
    $pdo->exec("INSERT INTO products (uuid,vendor_id,category_id,tax_class_id,base_unit_id,title,slug,product_type,status,is_pos_enabled,is_online_enabled,gst_type,origin) VALUES " . implode(',', $pBuf));
    $firstId = (int)$pdo->lastInsertId();
    $pCnt += count($pBuf);

    if ($vBuf) {
        // Patch variant rows with correct product_ids (stored as placeholders)
        $fixed = [];
        $vi = 0;
        foreach ($vBuf as $vrow) {
            // vrow = [product_offset, variantSuffix, sku, mrp, price, unit_id, isDefault]
            [$poff, $suf, $sku, $mrp, $price, $uid, $isDef] = $vrow;
            $pid = $firstId + $poff;
            $fixed[] = "(UUID(),$pid," . (int)$GLOBALS['vendorId'] . ",'$sku',$mrp,$price,$uid,$isDef,'active','server')";
        }
        $pdo->exec("INSERT INTO product_variants (uuid,product_id,vendor_id,sku,mrp,base_price,unit_id,is_default,status,origin) VALUES " . implode(',', $fixed));
        $vCnt += count($fixed);
    }

    if ($pCnt % 10000 < PBATCH) {
        $pdo->exec('COMMIT');
        $pdo->exec('SET autocommit=0');
        $elapsed = round(microtime(true) - $GLOBALS['startTime']);
        echo "  products=$pCnt variants=$vCnt [{$elapsed}s]\n";
    }

    $pBuf = [];
    $vBuf = [];
}

// ── main generation loop ──────────────────────────────────────────────────────
$globalProdCnt = 0;
foreach ($catDefs as [$prefix,$catId,$taxCls,$unitId,$minP,$maxP,$brands,$types,$quals,$sizes,$nb,$nt,$nq,$ns]) {
    $catProdCnt = 0;
    $totalForCat = $nb * $nt * $nq * $ns;
    echo "Category $prefix target=$totalForCat\n";

    for ($bi=0; $bi<$nb; $bi++) {
      $brand = $brands[$bi];
      for ($ti=0; $ti<$nt; $ti++) {
        $type = $types[$ti];
        for ($qi=0; $qi<$nq; $qi++) {
          $qual = $quals[$qi];
          for ($si=0; $si<$ns; $si++) {
            $size = $sizes[$si];
            $globalProdCnt++;
            $catProdCnt++;

            $brandQ = addslashes($brand);
            $typeQ  = addslashes($type);
            $qualQ  = addslashes($qual);
            $sizeQ  = addslashes($size);
            $title  = "$brandQ $typeQ $qualQ $sizeQ";
            $sl     = slug("$brand $type $qual $size", $globalProdCnt);
            $basePrice = rand($minP, $maxP);

            $pOffset = count($pBuf); // position in current batch
            $pBuf[] = "(UUID(),$vendorId,$catId,$taxCls,$unitId,'$title','$sl','simple','published',1,1,'inclusive','server')";

            $nv = varCount($globalProdCnt);
            for ($v=0; $v<$nv; $v++) {
                $mult  = $varMult[$v];
                $price = (int)($basePrice * $mult);
                $mrp   = (int)($price * 1.15);
                $sku   = "$prefix" . str_pad((string)$globalProdCnt,7,'0',STR_PAD_LEFT) . $varSuffix[$v];
                $isDef = $v===0 ? 1 : 0;
                $vBuf[] = [$pOffset, $varSuffix[$v], $sku, $mrp, $price, $unitId, $isDef];
            }

            if (count($pBuf) >= PBATCH) flush_products($pdo, $pBuf, $vBuf, $pCnt, $vCnt);
          }
        }
      }
    }
    flush_products($pdo, $pBuf, $vBuf, $pCnt, $vCnt);
    echo "  $prefix done: catProdCnt=$catProdCnt\n";
}
$pdo->exec('COMMIT');
$pdo->exec('SET FOREIGN_KEY_CHECKS=1; SET UNIQUE_CHECKS=1; SET autocommit=1');

$total  = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$vtotal = (int)$pdo->query('SELECT COUNT(*) FROM product_variants')->fetchColumn();
echo "\n=== DONE ===\n";
echo "Products : $total\n";
echo "Variants : $vtotal\n";
echo "Time     : " . round(microtime(true)-$startTime) . "s\n";
