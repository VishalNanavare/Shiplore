<?php
/**
 * database/seed_big.php — Big Demo Data Seeder
 * --------------------------------------------------------------------------
 * Populates the platform with realistic scale for UI / layout / behaviour
 * testing. Additive to the existing 99_demo_seed.sql (3-vendor) demo.
 *
 *   130 vendors  | ~235 shops across 15 Mumbai neighbourhoods
 *   ~159 categories (Amazon-depth tree) | 85 attributes / 500+ values
 *   2,150 products across ALL 10 product types + Hotels + Medical
 *   variants, bundles, combos, subscriptions, gift-cards, rentals, downloads
 *
 * Idempotent: re-running first deletes its own ID ranges, then re-inserts.
 * Images are seeded separately by database/seed_images.php.
 *
 * Run:  php database/seed_big.php
 */

declare(strict_types=1);

// CLI only. This script issues mass DELETE statements against the live database;
// served over HTTP it is an unauthenticated data-destruction endpoint. The
// database/.htaccess deny is the primary control — this is defence in depth for
// the case where that file is lost or the DocumentRoot changes.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

mt_srand(20260614); // deterministic "randomness"

/* ----------------------------------------------------------------------- *
 * 0. DB connection (same .env parsing as apply_sql.php)
 * ----------------------------------------------------------------------- */
$root = dirname(__DIR__);
$env  = [];
foreach (file($root . '/.env') as $line) {
    if (preg_match('/^\s*(database\.default\.([a-zA-Z]+))\s*=\s*(.*)$/', $line, $m)) {
        $env[$m[2]] = trim($m[3]);
    }
}
$db = mysqli_connect(
    $env['hostname'] ?? 'localhost',
    $env['username'] ?? 'root',
    $env['password'] ?? '',
    $env['database'] ?? 'test',
    (int) ($env['port'] ?? 3306),
);
if (!$db) {
    fwrite(STDERR, "DB connect failed: " . mysqli_connect_error() . "\n");
    exit(1);
}
mysqli_set_charset($db, 'utf8mb4');

/* ----------------------------------------------------------------------- *
 * Helpers
 * ----------------------------------------------------------------------- */
function esc($v): string {
    global $db;
    if ($v === null)             return 'NULL';
    if (is_int($v) || is_float($v)) return (string) $v;
    return "'" . mysqli_real_escape_string($db, (string) $v) . "'";
}
function q(string $sql) {
    global $db;
    $r = mysqli_query($db, $sql);
    if ($r === false) {
        fwrite(STDERR, "SQL ERROR: " . mysqli_error($db) . "\n  >> " . substr($sql, 0, 400) . "\n");
        exit(1);
    }
    return $r;
}
/** Bulk insert: $rows is array of arrays of already-esc()'d strings. */
function bulk(string $table, array $cols, array $rows, string $verb = 'INSERT'): void {
    if (!$rows) return;
    $chunks = array_chunk($rows, 200);
    foreach ($chunks as $chunk) {
        $vals = [];
        foreach ($chunk as $r) $vals[] = '(' . implode(',', $r) . ')';
        q("$verb INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES " . implode(',', $vals));
    }
}
function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}
$USED_SLUGS = [];
function uslug(string $name): string {
    global $USED_SLUGS;
    $base = slugify($name); $s = $base; $i = 1;
    while (isset($USED_SLUGS[$s])) { $i++; $s = "$base-$i"; }
    $USED_SLUGS[$s] = true;
    return $s;
}
function pick(array $a, int $i) { return $a[$i % count($a)]; }

$PW = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // = "password"

echo "==> Big demo seed starting\n";
q("SET FOREIGN_KEY_CHECKS = 0");

/* ----------------------------------------------------------------------- *
 * 1. Cleanup (reverse FK order) — makes the script re-runnable
 * ----------------------------------------------------------------------- */
echo "--> cleanup previous run\n";
$cleanup = [
    "DELETE FROM product_media        WHERE product_id BETWEEN 101 AND 2250",
    "DELETE FROM media_assets         WHERE object_key LIKE 'demo-media/%'",
    "DELETE FROM inventory            WHERE shop_id BETWEEN 101 AND 400",
    "DELETE FROM product_shops        WHERE product_id BETWEEN 101 AND 2250",
    "DELETE vav FROM variant_attribute_values vav JOIN product_variants pv ON vav.variant_id=pv.id WHERE pv.product_id BETWEEN 101 AND 2250",
    "DELETE FROM product_attribute_values WHERE product_id BETWEEN 101 AND 2250",
    "DELETE FROM product_bundle_items WHERE bundle_product_id BETWEEN 101 AND 2250",
    "DELETE FROM product_downloads    WHERE product_id BETWEEN 101 AND 2250",
    "DELETE FROM product_subscription_plans WHERE product_id BETWEEN 101 AND 2250",
    "DELETE FROM gift_card_products    WHERE product_id BETWEEN 101 AND 2250",
    "DELETE FROM product_rental_terms  WHERE product_id BETWEEN 101 AND 2250",
    "DELETE FROM product_content       WHERE product_id BETWEEN 101 AND 2250",
    "DELETE FROM product_faqs          WHERE product_id BETWEEN 101 AND 2250",
    "DELETE FROM product_tag_map       WHERE product_id BETWEEN 101 AND 2250",
    "DELETE FROM product_label_map     WHERE product_id BETWEEN 101 AND 2250",
    "DELETE FROM product_variants      WHERE product_id BETWEEN 101 AND 2250",
    "DELETE FROM products              WHERE id BETWEEN 101 AND 2250",
    "DELETE FROM product_tags          WHERE id BETWEEN 1001 AND 1040",
    "DELETE FROM shop_hours            WHERE shop_id BETWEEN 101 AND 400",
    "DELETE FROM shops                 WHERE id BETWEEN 101 AND 400",
    "DELETE FROM user_roles            WHERE user_id BETWEEN 1001 AND 1130",
    "DELETE FROM vendors               WHERE id BETWEEN 101 AND 230",
    "DELETE FROM users                 WHERE id BETWEEN 1001 AND 1130",
    "DELETE FROM category_attributes   WHERE category_id BETWEEN 101 AND 260",
    "DELETE FROM attribute_set_items   WHERE attribute_set_id BETWEEN 1 AND 12",
    "DELETE FROM attribute_sets        WHERE id BETWEEN 1 AND 12",
    "DELETE FROM attribute_values      WHERE attribute_id BETWEEN 6 AND 85",
    "DELETE FROM attributes            WHERE id BETWEEN 6 AND 85",
    "DELETE FROM business_type_category_map WHERE business_type_id BETWEEN 6 AND 12 OR category_id BETWEEN 101 AND 260",
    "DELETE FROM categories            WHERE id BETWEEN 101 AND 260",
    "DELETE FROM brands                WHERE id BETWEEN 5 AND 40",
    "DELETE FROM hsn_sac_codes         WHERE id BETWEEN 6 AND 35",
    "DELETE FROM business_types        WHERE id BETWEEN 6 AND 12",
];
foreach ($cleanup as $c) q($c);

// Preload slugs that already exist in the DB so uslug() never collides
foreach ([
    'categories', 'brands', 'business_types', 'vendors', 'product_tags', 'products',
] as $t) {
    $r = q("SELECT slug FROM `$t`");
    while ($row = mysqli_fetch_assoc($r)) {
        if (!empty($row['slug'])) $USED_SLUGS[$row['slug']] = true;
    }
}

/* ----------------------------------------------------------------------- *
 * 2. Business types (IDs 6-12)
 * ----------------------------------------------------------------------- */
echo "--> business types\n";
$btypes = [
    [6,  'home_kitchen',  'Home & Kitchen',        8.00],
    [7,  'health_beauty', 'Health & Beauty',      10.00],
    [8,  'sports_fitness','Sports & Fitness',      7.00],
    [9,  'books_digital', 'Books & Digital Goods', 6.00],
    [10, 'services',      'Services',             12.00],
    [11, 'hotels',        'Hotels & Hospitality',  8.00],
    [12, 'medical',       'Medical & Pharmacy',    6.00],
];
$rows = [];
foreach ($btypes as $b) {
    $rows[] = [$b[0], 'UUID()', esc($b[1]), esc($b[2]), esc(uslug($b[2])), esc($b[3]), esc('active')];
}
bulk('business_types', ['id','uuid','code','name','slug','default_commission_rate','status'], $rows);

/* ----------------------------------------------------------------------- *
 * 3. HSN / SAC codes (IDs 6-35)
 * ----------------------------------------------------------------------- */
echo "--> hsn/sac codes\n";
$hsnDefs = [ // id, code, type, description, default_tax_class_id, themeKey
    [6,'851712','HSN','Mobile phones',4,'mobiles'],
    [7,'847130','HSN','Laptops & computers',4,'laptops'],
    [8,'851830','HSN','Headphones & audio',4,'audio'],
    [9,'852580','HSN','Cameras',4,'cameras'],
    [10,'610910','HSN','Apparel — men',3,'mens_apparel'],
    [11,'620442','HSN','Apparel — women',3,'womens_apparel'],
    [12,'500720','HSN','Silk sarees',3,'sarees'],
    [13,'640399','HSN','Footwear',3,'footwear'],
    [14,'910211','HSN','Watches',4,'watches'],
    [15,'420221','HSN','Bags & wallets',4,'bags'],
    [16,'100630','HSN','Rice & staples',2,'rice'],
    [17,'110100','HSN','Flour & cereals',2,'flour'],
    [18,'151211','HSN','Edible oils & ghee',2,'oils'],
    [19,'040120','HSN','Milk & cream',1,'milk'],
    [20,'040610','HSN','Paneer & cheese',2,'paneer'],
    [21,'220210','HSN','Beverages',3,'beverages'],
    [22,'210690','HSN','Snacks & namkeen',4,'snacks'],
    [23,'850960','HSN','Kitchen appliances',4,'kitchen_appliances'],
    [24,'732393','HSN','Cookware & bakeware',3,'cookware'],
    [25,'940540','HSN','Home decor & lighting',4,'home_decor'],
    [26,'330499','HSN','Skincare & cosmetics',4,'skincare'],
    [27,'330510','HSN','Haircare',4,'haircare'],
    [28,'293690','HSN','Vitamins & supplements',2,'supplements'],
    [29,'950699','HSN','Sports equipment',3,'sports'],
    [30,'490199','HSN','Books',1,'books'],
    [31,'998434','SAC','Software & digital content',4,'software'],
    [32,'998533','SAC','Home services',4,'services'],
    [33,'300490','HSN','Medicines',3,'medicines'],
    [34,'901890','HSN','Medical devices',4,'medical_devices'],
    [35,'996311','SAC','Hotel & accommodation',4,'hotel'],
];
$rows = []; $HSN = [];
foreach ($hsnDefs as $h) {
    $rows[] = [$h[0], esc($h[1]), esc($h[2]), esc($h[3]), esc($h[4]), esc('active')];
    $HSN[$h[5]] = $h[0];
}
bulk('hsn_sac_codes', ['id','code','type','description','default_tax_class_id','status'], $rows);

/* ----------------------------------------------------------------------- *
 * 4. Brands (IDs 5-40)
 * ----------------------------------------------------------------------- */
echo "--> brands\n";
$brandNames = ['Zenix','Voltedge','Auralis','PixelPro','UrbanThread','Metier','StyleCraft','SoleArc',
    'FreshHarvest','PurePlate','CasaNova','ChefMate','GlowVida','HerbalRoots','FitForge','PrimePerform',
    'PageTurner','ByteWorks','CarefulHands','StayEase','MediTrust','VitalCare','AquaPure','SnackHouse',
    'BrewBuddy','DenimCo','LuxeLeather','ActiveWear','NestDecor','KitchenKing','BeautyBloom','TechNova',
    'GadgetHub','SportZone','BookBarn','HomeEssence'];
$rows = []; $brandIds = [];
foreach ($brandNames as $i => $n) {
    $id = 5 + $i;
    $brandIds[] = $id;
    $rows[] = [$id, 'UUID()', esc($n), esc(uslug($n)), esc('active')];
}
bulk('brands', ['id','uuid','name','slug','status'], $rows);

/* ----------------------------------------------------------------------- *
 * 5. Categories (IDs 101-259) — Amazon-depth tree
 *    [id, parent_id, name, tax_class_id, commission]
 * ----------------------------------------------------------------------- */
echo "--> categories\n";
$catDefs = [
    // Electronics
    [101,null,'Electronics',4,10],
      [102,101,'Mobiles & Tablets',4,8],
        [103,102,'Smartphones',4,8],[104,102,'Feature Phones',4,8],[105,102,'Tablets',4,8],
      [106,101,'Laptops & Computers',4,8],
        [107,106,'Laptops',4,8],[108,106,'Desktops',4,8],[109,106,'Monitors',4,8],
      [110,101,'Audio & Headphones',4,9],
        [111,110,'Earbuds & TWS',4,9],[112,110,'Over-Ear Headphones',4,9],[113,110,'Speakers & Soundbars',4,9],
      [114,101,'Cameras & Photography',4,9],
        [115,114,'DSLR & Mirrorless',4,9],[116,114,'Action Cameras',4,9],[117,114,'Camera Accessories',4,9],
    // Fashion
    [118,null,'Fashion & Apparel',3,12],
      [119,118,"Men's Clothing",3,12],
        [120,119,'T-Shirts & Polos',3,12],[121,119,'Shirts',3,12],[122,119,'Trousers & Chinos',3,12],[123,119,'Ethnic Wear',3,12],
      [124,118,"Women's Clothing",3,12],
        [125,124,'Tops & Blouses',3,12],[126,124,'Sarees',3,12],[127,124,'Kurtis & Suits',3,12],[128,124,'Western Dresses',3,12],
      [129,118,'Footwear',3,12],
        [130,129,"Men's Shoes",3,12],[131,129,"Women's Shoes",3,12],[132,129,'Sports Shoes',3,12],[133,129,'Sandals & Slippers',3,12],
      [134,118,'Accessories',4,12],
        [135,134,'Watches',4,12],[136,134,'Sunglasses',4,12],[137,134,'Bags & Wallets',4,12],[138,134,'Belts & Ties',4,12],
    // Grocery
    [139,null,'Grocery & Food',2,5],
      [140,139,'Staples & Grains',2,5],
        [141,140,'Rice & Dal',2,5],[142,140,'Flour & Cereals',2,5],[143,140,'Oils & Ghee',2,5],
      [144,139,'Dairy & Eggs',1,5],
        [145,144,'Milk & Cream',1,5],[146,144,'Paneer & Cheese',2,5],[147,144,'Yoghurt & Butter',2,5],
      [148,139,'Beverages',3,6],
        [149,148,'Cold Drinks & Soda',3,6],[150,148,'Juices & Shakes',3,6],[151,148,'Tea & Coffee',2,6],
      [152,139,'Snacks & Bakery',4,6],
        [153,152,'Chips & Namkeen',4,6],[154,152,'Biscuits & Cookies',2,6],[155,152,'Dry Fruits & Nuts',2,6],
    // Home & Kitchen
    [156,null,'Home & Kitchen',3,8],
      [157,156,'Kitchen Appliances',4,8],
        [158,157,'Mixer Grinders',4,8],[159,157,'Microwave Ovens',4,8],[160,157,'Air Fryers',4,8],
      [161,156,'Cookware & Bakeware',3,8],
        [162,161,'Pressure Cookers',3,8],[163,161,'Non-Stick Pans',3,8],[164,161,'Baking Trays',3,8],
      [165,156,'Home Decor',4,8],
        [166,165,'Candles & Diffusers',4,8],[167,165,'Photo Frames',4,8],[168,165,'Vases & Pots',4,8],
    // Health & Beauty
    [169,null,'Health & Beauty',4,10],
      [170,169,'Skincare',4,10],
        [171,170,'Moisturisers & Creams',4,10],[172,170,'Serums & Oils',4,10],[173,170,'Sunscreens',4,10],[174,170,'Face Wash',4,10],
      [175,169,'Haircare',4,10],
        [176,175,'Shampoos',4,10],[177,175,'Conditioners',4,10],[178,175,'Hair Oils & Masks',4,10],[179,175,'Styling Products',4,10],
      [180,169,'Supplements & Wellness',2,10],
        [181,180,'Protein Powders',2,10],[182,180,'Vitamins & Minerals',2,10],[183,180,'Herbal Supplements',2,10],
    // Sports & Fitness
    [184,null,'Sports & Fitness',3,7],
      [185,184,'Gym Equipment',3,7],
        [186,185,'Free Weights',3,7],[187,185,'Resistance Bands',3,7],[188,185,'Yoga Mats',3,7],
      [189,184,'Sports Apparel',3,7],
        [190,189,'Running Wear',3,7],[191,189,'Yoga Wear',3,7],[192,189,'Cricket & Football Kits',3,7],
    // Books & Digital
    [193,null,'Books & Digital Goods',1,6],
      [194,193,'Books',1,6],
        [195,194,'Fiction',1,6],[196,194,'Non-Fiction',1,6],[197,194,'Self-Help & Business',1,6],
      [198,193,'eBooks & Digital Content',4,6],
      [199,193,'Software & Licenses',4,6],
    // Services
    [200,null,'Services',4,12],
      [201,200,'Home Services',4,12],
        [202,201,'Cleaning Services',4,12],[203,201,'Plumbing & Repair',4,12],[204,201,'AC Service',4,12],
      [205,200,'Subscription Plans',4,12],
    // Gift Cards
    [206,null,'Gift Cards & Vouchers',1,3],
      [207,206,'Shopping Gift Cards',1,3],[208,206,'Food & Dining Vouchers',1,3],
    // Rental
    [209,null,'Rental Equipment',4,10],
      [210,209,'Event Equipment',4,10],[211,209,'Camera & AV Equipment',4,10],[212,209,'Furniture Rental',4,10],
    // Hotels
    [213,null,'Hotels & Hospitality',4,8],
      [214,213,'Budget Hotels',3,8],
        [215,214,'Non-AC Rooms',3,8],[216,214,'AC Rooms',3,8],
      [217,213,'Luxury Hotels',4,8],
        [218,217,'Deluxe Rooms',4,8],[219,217,'Suite & Premium Rooms',4,8],
      [220,213,'Service Apartments',4,8],
        [221,220,'Studio Apartments',4,8],[222,220,'1BHK Apartments',4,8],[223,220,'2BHK Apartments',4,8],
      [224,213,'Hostels & Dormitories',3,8],
        [225,224,'Mixed Dormitory',3,8],[226,224,'Private Hostel Room',3,8],
      [227,213,'Resorts & Weekend Getaways',4,8],
    // Medical
    [228,null,'Medical & Pharmacy',3,6],
      [229,228,'OTC Medicines',3,6],
        [230,229,'Cold, Cough & Flu',3,6],[231,229,'Pain Relief & Fever',3,6],[232,229,'Antacids & Digestive',3,6],
      [233,228,'Prescription Drugs',3,6],
        [234,233,'Antibiotics',3,6],[235,233,'Diabetes Care',3,6],[236,233,'BP & Cardiac',3,6],
      [237,228,'Medical Devices',4,6],
        [238,237,'BP Monitors',4,6],[239,237,'Glucometers',4,6],[240,237,'Pulse Oximeters',4,6],[241,237,'Thermometers',4,6],
      [242,228,'Surgical & First Aid',3,6],
        [243,242,'Bandages & Dressings',3,6],[244,242,'Gloves & Masks',3,6],[245,242,'Antiseptics',3,6],
      [246,228,'Ayurveda & Herbal',2,6],
        [247,246,'Churna & Powders',2,6],[248,246,'Oils & Kadha',2,6],[249,246,'Tablets & Vati',2,6],
      [250,228,'Dental Care',3,6],
        [251,250,'Toothpaste',3,6],[252,250,'Mouthwash',3,6],[253,250,'Dental Tools',4,6],
      [254,228,'Eye Care',3,6],
        [255,254,'Eye Drops',3,6],[256,254,'Contact Lens Solutions',3,6],
      [257,228,'Baby Health',3,6],
        [258,257,'Baby Supplements',2,6],[259,257,'Baby Skincare',4,6],
];
// Build path + level
$catById = [];
foreach ($catDefs as $c) $catById[$c[0]] = $c;
function catPath(int $id, array $catById): array { // returns [path, level]
    $names = []; $cur = $id;
    while ($cur !== null) {
        $names[] = $catById[$cur][2];
        $cur = $catById[$cur][1];
    }
    $names = array_reverse($names);
    $slugParts = array_map('slugify', $names);
    return [implode('/', $slugParts), count($names) - 1];
}
$rows = [];
$leavesByRoot = []; $childrenOf = [];
foreach ($catDefs as $c) { if ($c[1] !== null) $childrenOf[$c[1]][] = $c[0]; }
foreach ($catDefs as $c) {
    [$path, $level] = catPath($c[0], $catById);
    $rows[] = [$c[0], 'UUID()', $c[1] === null ? 'NULL' : $c[1], esc($c[2]), esc(uslug($c[2])),
               esc($path), $level, esc($c[3]), esc($c[4]), esc('active')];
}
bulk('categories', ['id','uuid','parent_id','name','slug','path','level','default_tax_class_id','default_commission_rate','status'], $rows);

// Compute leaves under each root
function rootOf(int $id, array $catById): int { $cur=$id; while($catById[$cur][1]!==null)$cur=$catById[$cur][1]; return $cur; }
function leavesUnder(int $rootId, array $catDefs, array $childrenOf): array {
    $out = [];
    $stack = [$rootId];
    while ($stack) {
        $n = array_pop($stack);
        if (empty($childrenOf[$n])) { $out[] = $n; }
        else foreach ($childrenOf[$n] as $ch) $stack[] = $ch;
    }
    sort($out);
    return $out;
}

/* ----------------------------------------------------------------------- *
 * 6. business_type_category_map  (root subtree -> business type)
 * ----------------------------------------------------------------------- */
echo "--> business_type_category_map\n";
$btRoots = [
    3  => [101],            // electronics
    4  => [118],            // fashion (incl footwear subtree)
    1  => [129],            // footwear bt -> footwear subtree
    2  => [139],            // grocery
    5  => [148, 152],       // fnb -> beverages + snacks
    6  => [156, 209],       // home_kitchen -> home & rental
    7  => [169],            // health_beauty
    8  => [184],            // sports
    9  => [193, 206],       // books_digital -> books + giftcards
    10 => [200],            // services
    11 => [213],            // hotels
    12 => [228],            // medical
];
$mapRows = []; $seenMap = [];
foreach ($btRoots as $bt => $roots) {
    foreach ($roots as $r) {
        // map every category in the subtree
        $stack = [$r];
        while ($stack) {
            $n = array_pop($stack);
            $k = "$bt-$n";
            if (!isset($seenMap[$k])) { $seenMap[$k] = true; $mapRows[] = [$bt, $n]; }
            if (!empty($childrenOf[$n])) foreach ($childrenOf[$n] as $ch) $stack[] = $ch;
        }
    }
}
$rows = array_map(fn($r) => [$r[0], $r[1]], $mapRows);
bulk('business_type_category_map', ['business_type_id','category_id'], $rows, 'INSERT IGNORE');

/* ----------------------------------------------------------------------- *
 * 7. Attributes (IDs 6-85) + extra values for existing size/color
 * ----------------------------------------------------------------------- */
echo "--> attributes\n";
$attrDefs = [ // id, code, name, type, is_variant_defining
    [6,'ram','RAM','select',1],[7,'display_size','Display Size','select',0],[8,'battery_mah','Battery Capacity','select',0],
    [9,'camera_mp','Rear Camera','select',0],[10,'network','Network','select',0],[11,'os','Operating System','select',0],
    [12,'processor_brand','Processor Brand','select',0],[13,'processor_model','Processor Model','text',0],
    [14,'storage_type','Storage Type','select',1],[15,'display_type','Display Type','select',0],
    [16,'refresh_rate','Refresh Rate','select',0],[17,'connectivity','Connectivity','multiselect',0],
    [18,'graphics','Graphics Card','select',0],[19,'battery_life','Battery Life','select',0],[20,'sim_type','SIM Type','select',0],
    [21,'pattern','Pattern','select',1],[22,'fabric','Fabric','select',0],[23,'fit','Fit Type','select',0],
    [24,'sleeve','Sleeve Length','select',1],[25,'neck_type','Neck Type','select',0],[26,'occasion_wear','Occasion','multiselect',0],
    [27,'season','Season','select',0],[28,'closure_type','Closure Type','select',0],[29,'toe_shape','Toe Shape','select',0],
    [30,'heel_type','Heel Type','select',1],[31,'heel_height','Heel Height','select',0],[32,'sole_material','Sole Material','select',0],
    [33,'upper_material','Upper Material','select',0],[34,'shoe_width','Shoe Width','select',0],[35,'care_instructions','Care Instructions','select',0],
    [36,'pack_size','Pack Size / Weight','select',1],[37,'flavour','Flavour','select',1],[38,'food_type','Food Type','select',0],
    [39,'grain_type','Grain / Rice Type','select',0],[40,'spice_level','Spice Level','select',0],[41,'shelf_life','Shelf Life','select',0],
    [42,'fat_content','Fat Content','select',0],[43,'sugar_content','Sugar Content','select',0],[44,'caffeine','Caffeine','select',0],
    [45,'skin_type','Skin Type','multiselect',0],[46,'spf','SPF Rating','select',0],[47,'product_form','Product Form','select',1],
    [48,'key_ingredient','Key Ingredient','multiselect',0],[49,'benefits','Benefits','multiselect',0],[50,'fragrance','Fragrance','select',1],
    [51,'quantity_ml','Quantity (ml/g)','select',1],[52,'hair_type','Hair Type','multiselect',0],
    [53,'sport_type','Sport','multiselect',0],[54,'resistance_level','Resistance Level','select',1],[55,'target_muscle','Target Muscle','multiselect',0],
    [56,'sport_surface','Playing Surface','select',0],[57,'equipment_material','Equipment Material','select',0],
    [58,'genre','Genre','select',0],[59,'language','Language','select',1],[60,'book_format','Format','select',1],
    [61,'reading_level','Reading Level','select',0],[62,'pages_count','Page Count','select',0],[63,'publisher','Publisher','select',0],
    [64,'room_type','Room Type','select',1],[65,'bed_type','Bed Type','select',0],[66,'room_view','Room View','select',0],
    [67,'meal_plan','Meal Plan','select',1],[68,'hotel_occupancy','Occupancy','select',1],[69,'hotel_amenities','Amenities','multiselect',0],
    [70,'star_rating','Star Rating','select',0],[71,'cancellation_policy','Cancellation Policy','select',0],
    [72,'floor_level','Floor Level','select',0],[73,'check_in_time','Check-in Time','select',0],
    [74,'dosage_form','Dosage Form','select',1],[75,'strength','Strength / Potency','select',1],[76,'prescription_type','Prescription Type','select',0],
    [77,'storage_condition','Storage Condition','select',0],[78,'pack_type','Pack Type','select',1],[79,'age_group','Age Group','select',0],
    [80,'active_ingredient','Active Ingredient','text',0],[81,'manufacturer_med','Manufacturer','select',0],
    [82,'ayurveda_type','Ayurveda Category','select',0],[83,'device_type','Device Type','select',0],
    [84,'subscription_cycle','Billing Cycle','select',1],[85,'rental_duration','Rental Duration','select',1],
];
$rows = [];
foreach ($attrDefs as $a) {
    $rows[] = [$a[0], esc($a[1]), esc($a[2]), esc($a[3]), $a[4], esc('active')];
}
bulk('attributes', ['id','code','name','type','is_variant_defining','status'], $rows, 'INSERT IGNORE');

// attribute_id by code (incl existing 1-5)
$ATTR = [];
$r = q("SELECT id, code FROM attributes");
while ($row = mysqli_fetch_assoc($r)) $ATTR[$row['code']] = (int) $row['id'];

/* ----------------------------------------------------------------------- *
 * 7b. Attribute values (500+)
 * ----------------------------------------------------------------------- */
echo "--> attribute values\n";
$attrValues = [
    // extra values for existing variant attrs
    'color' => ['Red','Black','Blue','White','Green','Brown','Grey','Pink','Yellow','Navy Blue','Maroon','Beige','Gold','Silver','Olive'],
    'size'  => ['XS','S','M','L','XL','XXL','3XL','UK6','UK7','UK8','UK9','UK10','UK11'],
    'material' => ['Cotton','Polyester','Leather','Steel','Plastic','Glass','Wood','Silicone'],
    'storage'  => ['32GB','64GB','128GB','256GB','512GB','1TB'],
    // electronics
    'ram' => ['2GB','3GB','4GB','6GB','8GB','12GB','16GB','32GB'],
    'display_size' => ['4.7"','5.4"','6.1"','6.4"','6.7"','13.3"','14"','15.6"','16"','17.3"'],
    'battery_mah' => ['2000mAh','3000mAh','4000mAh','5000mAh','6000mAh'],
    'camera_mp' => ['8MP','12MP','48MP','64MP','108MP','200MP'],
    'network' => ['2G','3G','4G LTE','5G','WiFi Only'],
    'os' => ['Android 13','Android 14','iOS 17','iOS 18','Windows 11','macOS Ventura','macOS Sonoma','Chrome OS'],
    'processor_brand' => ['Qualcomm Snapdragon','MediaTek Dimensity','Apple A-series','Intel Core i3','Intel Core i5','Intel Core i7','Intel Core i9','AMD Ryzen 5','AMD Ryzen 7','Samsung Exynos'],
    'storage_type' => ['HDD','SSD','Hybrid HDD+SSD','eMMC','UFS'],
    'display_type' => ['LCD','IPS LCD','AMOLED','Super AMOLED','OLED','Mini-LED','Retina'],
    'refresh_rate' => ['60Hz','90Hz','120Hz','144Hz','165Hz','240Hz'],
    'connectivity' => ['WiFi','Bluetooth 5.0','Bluetooth 5.3','NFC','USB-C','Thunderbolt','3.5mm Jack'],
    'graphics' => ['Integrated','NVIDIA GeForce MX550','NVIDIA RTX 3050','NVIDIA RTX 4060','AMD Radeon'],
    'battery_life' => ['Up to 6 hrs','Up to 8 hrs','Up to 10 hrs','Up to 12 hrs','Up to 15 hrs'],
    'sim_type' => ['Single SIM','Dual SIM','eSIM','Dual SIM + eSIM'],
    // fashion
    'pattern' => ['Solid','Striped','Checked','Floral','Printed','Embroidered','Abstract','Geometric','Animal Print','Camouflage'],
    'fabric' => ['100% Cotton','Polyester','Cotton Blend','Silk','Linen','Rayon','Viscose','Wool','Bamboo','Denim','Lycra','Net','Georgette'],
    'fit' => ['Regular Fit','Slim Fit','Oversized','Relaxed Fit','Tailored Fit','Comfort Fit'],
    'sleeve' => ['Sleeveless','Short Sleeve','3/4 Sleeve','Full Sleeve','Rolled-Up Sleeve'],
    'neck_type' => ['Round Neck','V-Neck','Polo Collar','Crew Neck','Henley','Turtleneck','Square Neck','Boat Neck'],
    'occasion_wear' => ['Casual','Formal','Party & Festive','Sports & Gym','Ethnic','Beach','Office','Wedding'],
    'season' => ['All Season','Summer','Winter','Monsoon','Spring'],
    'closure_type' => ['Lace-Up','Slip-On','Velcro','Buckle','Zipper','Hook & Eye','Button'],
    'toe_shape' => ['Round Toe','Pointed Toe','Square Toe','Open Toe','Almond Toe','Peep Toe'],
    'heel_type' => ['Flat','Block Heel','Stiletto','Wedge','Kitten Heel','Platform','Cone Heel'],
    'heel_height' => ['Flat (0-1cm)','Low Heel (1-3cm)','Mid Heel (3-7cm)','High Heel (7-10cm)','Very High (10cm+)'],
    'sole_material' => ['Rubber','EVA Foam','TPR','PU','Leather','Cork'],
    'upper_material' => ['Genuine Leather','Synthetic Leather','Canvas','Mesh','Suede','Patent Leather','Knit','Velvet'],
    'shoe_width' => ['Narrow','Regular','Wide','Extra Wide'],
    'care_instructions' => ['Machine Wash','Hand Wash Only','Dry Clean Only','Spot Clean','Do Not Bleach'],
    // grocery
    'pack_size' => ['50g','100g','200g','250g','500g','1kg','2kg','5kg','10kg','25kg','100ml','200ml','500ml','1L','2L','5L'],
    'flavour' => ['Original','Chocolate','Vanilla','Strawberry','Mango','Masala','Peri Peri','Sour Cream','Barbecue','Mint','Lemon','Butter','Chilli Lime'],
    'food_type' => ['Vegetarian','Non-Vegetarian','Vegan','Jain','Gluten-Free','Organic','Keto-Friendly'],
    'grain_type' => ['Basmati','Sona Masoori','Jasmine','Brown Rice','Red Rice','Black Rice','Parboiled'],
    'spice_level' => ['No Spice','Mild','Medium','Hot','Extra Hot'],
    'shelf_life' => ['3 Months','6 Months','1 Year','2 Years','5 Years'],
    'fat_content' => ['Full Fat','Low Fat (2%)','Skimmed (0%)','Double Cream','Light'],
    'sugar_content' => ['Regular','Low Sugar','Sugar-Free','No Added Sugar','Natural Sugar'],
    'caffeine' => ['Caffeinated','Decaffeinated','Low Caffeine','No Caffeine'],
    // beauty
    'skin_type' => ['Oily','Dry','Normal','Combination','Sensitive','Acne-Prone','Mature','All Skin Types'],
    'spf' => ['No SPF','SPF 15','SPF 30','SPF 50','SPF 50+','SPF 100'],
    'product_form' => ['Cream','Lotion','Gel','Serum','Oil','Toner','Mist','Foam','Balm','Stick','Powder','Sheet Mask','Ampoule','Paste'],
    'key_ingredient' => ['Hyaluronic Acid','Vitamin C','Retinol','Niacinamide','Salicylic Acid','Glycolic AHA','Ceramide','Collagen','Peptides','Aloe Vera','Tea Tree Oil','Kojic Acid','Bakuchiol'],
    'benefits' => ['Anti-Aging','Brightening','Deep Moisturizing','Acne Control','Pore Minimizing','Dark Circle Reduction','Firming & Lifting','UV Protection','Soothing & Calming','Oil Control'],
    'fragrance' => ['Fragrance-Free','Rose','Jasmine','Lavender','Sandalwood','Citrus','Vanilla','Oud','Floral','Fresh'],
    'quantity_ml' => ['5ml','10ml','15ml','20ml','30ml','50ml','75ml','100ml','150ml','200ml','250ml','300ml','500ml','15g','30g','50g','100g','200g'],
    'hair_type' => ['Normal','Oily','Dry & Damaged','Curly','Wavy','Straight','Fine','Thick','Color-Treated','Anti-Dandruff'],
    // sports
    'sport_type' => ['Yoga','Running','Football','Cricket','Badminton','Tennis','Swimming','Cycling','Boxing','Gym & Weightlifting','Basketball','Kabaddi','Table Tennis'],
    'resistance_level' => ['Extra Light (2-5kg)','Light (5-10kg)','Medium (10-20kg)','Heavy (20-35kg)','Extra Heavy (35kg+)'],
    'target_muscle' => ['Full Body','Arms & Shoulders','Chest','Back & Lats','Core & Abs','Legs & Glutes','Cardio'],
    'sport_surface' => ['Indoor','Outdoor','Hard Court','Clay','Grass','Synthetic Turf','Water'],
    'equipment_material' => ['Steel','Rubber','Neoprene','PVC','Nylon','Polyester','Foam','Cork'],
    // books
    'genre' => ['Fiction','Non-Fiction','Self-Help','Biography','History','Science','Technology','Romance','Thriller','Fantasy','Horror','Children','Academic','Business & Finance','Spirituality','Travel','Cooking'],
    'language' => ['English','Hindi','Marathi','Tamil','Telugu','Kannada','Bengali','Gujarati','Punjabi','Malayalam'],
    'book_format' => ['Paperback','Hardcover','Ebook (PDF)','Ebook (ePub)','Audiobook','Large Print Edition','Illustrated'],
    'reading_level' => ['Beginner','Intermediate','Advanced','Children (3-5 yrs)','Children (6-10 yrs)','Young Adult (13-18)','All Ages'],
    'pages_count' => ['Under 100','100-200','200-350','350-500','500-750','750+'],
    'publisher' => ['Penguin Random House','Harper Collins','Simon & Schuster','Rupa Publications','Westland','Hachette India','Bloomsbury','Orient BlackSwan'],
    // hotels
    'room_type' => ['Standard Room','Deluxe Room','Super Deluxe','Suite','Presidential Suite','Studio','1BHK Apartment','2BHK Apartment','Dormitory Bed','Private Pod'],
    'bed_type' => ['Single Bed','Double Bed','Twin Beds','King Size','Queen Size','Triple Bed','Bunk Bed','Sofa Bed'],
    'room_view' => ['City View','Sea View','Pool View','Garden View','Mountain View','Courtyard View','No Specific View'],
    'meal_plan' => ['EP - Room Only','CP - Breakfast Included','MAP - Breakfast & Dinner','AP - All Meals','AI - All Inclusive'],
    'hotel_occupancy' => ['1 Adult','2 Adults','2 Adults + 1 Child','3 Adults','4 Adults','Family (2A+2C)'],
    'hotel_amenities' => ['AC','Non-AC','Free WiFi','Smart TV','Mini Bar','Room Service 24x7','Safe Locker','Balcony','Kitchenette','Swimming Pool','Gym','Spa & Wellness','Free Parking','Restaurant','Bar & Lounge','Elevator','Concierge Service'],
    'star_rating' => ['1 Star','2 Star','3 Star','4 Star','5 Star','Unrated / Budget'],
    'cancellation_policy' => ['Free Cancellation (24 hrs)','Free Cancellation (48 hrs)','Free Cancellation (72 hrs)','Non-Refundable','Partially Refundable'],
    'floor_level' => ['Ground Floor','Low Floor (1-5)','Mid Floor (6-10)','High Floor (11-20)','Top Floor (21+)'],
    'check_in_time' => ['11:00 AM','12:00 PM','1:00 PM','2:00 PM','3:00 PM','Flexible / Early Check-in'],
    // medical
    'dosage_form' => ['Tablet','Capsule','Syrup','Oral Suspension','Drops','Injection','Cream','Gel','Ointment','Lotion','Transdermal Patch','Inhaler / Spray','Suppository','Powder','Sachet'],
    'strength' => ['50mg','100mg','125mg','250mg','500mg','625mg','875mg','1000mg','5ml','10ml','15ml','30ml','60ml','100ml','200ml','0.5%','1%','2%','5%','10%'],
    'prescription_type' => ['OTC - No Prescription','Schedule H - Rx Required','Schedule H1 - Strict Rx','Schedule X - Narcotic'],
    'storage_condition' => ['Below 25 deg C','Below 30 deg C','Refrigerate (2-8 deg C)','Do Not Freeze','Protect from Light','Keep Dry'],
    'pack_type' => ['Strip of 4','Strip of 10','Strip of 15','Strip of 30','Bottle 30ml','Bottle 60ml','Bottle 100ml','Bottle 200ml','Tube 5g','Tube 10g','Tube 15g','Tube 30g','Box of 1','Box of 5','Single Unit'],
    'age_group' => ['Newborn (0-3 months)','Infant (3-12 months)','Toddler (1-3 yrs)','Child (4-12 yrs)','Teen (13-17)','Adult (18+)','Senior (60+)','All Ages'],
    'manufacturer_med' => ["Sun Pharma","Cipla","Dr. Reddy's","Lupin","Zydus Cadila","Abbott India","Pfizer","GlaxoSmithKline","Mankind Pharma","Himalaya","Dabur","Emami","Patanjali","Alembic"],
    'ayurveda_type' => ['Churna (Powder)','Kadha (Decoction)','Vati / Tablet','Avaleha (Jam)','Taila (Oil)','Arka (Distillate)','Asava-Arishta (Fermented)','Lepa (Paste)'],
    'device_type' => ['Digital Display','Analog Dial','Semi-Automatic','Fully Automatic','Smart (Bluetooth/App)','Disposable','Reusable','Wearable'],
    // subscriptions & rentals
    'subscription_cycle' => ['Weekly','Monthly','Quarterly','Half-Yearly','Annual','2-Year'],
    'rental_duration' => ['Per Day','3 Days','1 Week','2 Weeks','1 Month','3 Months'],
];
$rows = [];
foreach ($attrValues as $code => $vals) {
    if (!isset($ATTR[$code])) continue;
    $aid = $ATTR[$code];
    foreach ($vals as $so => $v) {
        $rows[] = [$aid, esc($v), $so + 1];
    }
}
bulk('attribute_values', ['attribute_id','value','sort_order'], $rows, 'INSERT IGNORE');

// attribute_value id map keyed "code|value"
$AV = [];
$r = q("SELECT av.id, a.code, av.value FROM attribute_values av JOIN attributes a ON av.attribute_id=a.id");
while ($row = mysqli_fetch_assoc($r)) $AV[$row['code'] . '|' . $row['value']] = (int) $row['id'];

/* ----------------------------------------------------------------------- *
 * 8. Attribute sets (IDs 1-12) + items
 * ----------------------------------------------------------------------- */
echo "--> attribute sets\n";
$setDefs = [
    [1,'smartphones','Smartphones',['ram','storage','color','display_size','battery_mah','camera_mp','network','os','processor_brand','sim_type']],
    [2,'laptops','Laptops & Computers',['ram','storage_type','storage','processor_brand','processor_model','display_size','display_type','os','graphics','battery_life','refresh_rate','warranty']],
    [3,'audio_devices','Audio Devices',['color','connectivity','battery_life','warranty']],
    [4,'fashion_apparel','Fashion & Apparel',['size','color','pattern','fabric','fit','sleeve','neck_type','occasion_wear','season','care_instructions']],
    [5,'footwear','Footwear',['size','color','upper_material','sole_material','closure_type','toe_shape','heel_type','heel_height','shoe_width','occasion_wear']],
    [6,'grocery_food','Grocery & Food',['pack_size','flavour','food_type','grain_type','shelf_life','spice_level','sugar_content']],
    [7,'dairy_beverages','Dairy & Beverages',['pack_size','food_type','fat_content','caffeine','shelf_life']],
    [8,'skincare_beauty','Skincare & Beauty',['skin_type','spf','product_form','key_ingredient','benefits','fragrance','quantity_ml','hair_type']],
    [9,'sports_equipment','Sports Equipment',['color','sport_type','resistance_level','target_muscle','sport_surface','equipment_material']],
    [10,'books','Books',['genre','language','book_format','reading_level','pages_count','publisher']],
    [11,'hotel_rooms','Hotel Rooms',['room_type','bed_type','room_view','meal_plan','hotel_occupancy','hotel_amenities','star_rating','cancellation_policy','floor_level','check_in_time']],
    [12,'pharmacy','Pharmacy',['dosage_form','strength','prescription_type','storage_condition','pack_type','age_group','manufacturer_med']],
];
$setRows = []; $itemRows = [];
foreach ($setDefs as $s) {
    $setRows[] = [$s[0], esc($s[1]), esc($s[2]), esc('active')];
    foreach ($s[3] as $so => $code) {
        if (!isset($ATTR[$code])) continue;
        $itemRows[] = [$s[0], $ATTR[$code], $so < 3 ? 1 : 0, $so + 1];
    }
}
bulk('attribute_sets', ['id','code','name','status'], $setRows);
bulk('attribute_set_items', ['attribute_set_id','attribute_id','is_required','sort_order'], $itemRows, 'INSERT IGNORE');

/* ----------------------------------------------------------------------- *
 * 9. category_attributes (filterable attrs per leaf, via the set)
 * ----------------------------------------------------------------------- */
echo "--> category_attributes\n";
// map each root -> attribute set
$rootSet = [101=>1,118=>4,139=>6,156=>9,169=>8,184=>9,193=>10,200=>10,206=>10,209=>9,213=>11,228=>12];
$catAttrRows = []; $seenCA = [];
foreach ($catDefs as $c) {
    $root = rootOf($c[0], $catById);
    if (!isset($rootSet[$root])) continue;
    $setId = $rootSet[$root];
    // attach the set's attributes to this category (filterable)
    foreach ($setDefs[$setId - 1][3] as $code) {
        if (!isset($ATTR[$code])) continue;
        $k = $c[0] . '-' . $ATTR[$code];
        if (isset($seenCA[$k])) continue;
        $seenCA[$k] = true;
        $catAttrRows[] = [$c[0], $ATTR[$code], 1];
    }
}
bulk('category_attributes', ['category_id','attribute_id','is_filterable'], $catAttrRows, 'INSERT IGNORE');

/* ----------------------------------------------------------------------- *
 * 10. Vendors (IDs 101-230) + owner users (1001-1130) + roles
 * ----------------------------------------------------------------------- */
echo "--> vendors, users, roles\n";
$firstNames = ['Amit','Priya','Rahul','Sunita','Vikram','Ananya','Nikhil','Pooja','Rajan','Deepa',
    'Suresh','Kavita','Manoj','Swati','Arjun','Neha','Rohit','Divya','Sanjay','Meera'];
$lastNames  = ['Sharma','Patel','Singh','Mehta','Gupta','Joshi','Verma','Shah','Rao','Kumar',
    'Nair','Iyer','Reddy','Desai','Chopra','Bose'];
// business type -> [vendor id range], display suffix
$vendorPlan = [ // bt_id => [startVendorId, count, brandSuffix]
    3  => [101, 18, 'Electronics'],
    4  => [119, 18, 'Fashion'],
    2  => [137, 15, 'Grocery'],
    1  => [152, 12, 'Footwear'],
    5  => [164, 10, 'Foods'],
    6  => [174, 10, 'Home'],
    7  => [184, 10, 'Beauty'],
    8  => [194, 7,  'Sports'],
    9  => [201, 5,  'Books'],
    10 => [206, 5,  'Services'],
    11 => [211, 10, 'Hotels'],
    12 => [221, 10, 'Pharmacy'],
];
$vendorRows = []; $userRows = []; $roleRows = [];
$vendorBType = []; $btVendors = []; $vendorName = [];
$vi = 0;
foreach ($vendorPlan as $bt => $plan) {
    [$start, $count, $suffix] = $plan;
    for ($k = 0; $k < $count; $k++) {
        $vid = $start + $k;
        $uid = 1000 + (++$vi);
        $fn = pick($firstNames, $vid + $k);
        $ln = pick($lastNames, $vid * 3 + $k);
        $person = "$fn $ln";
        $biz = "$ln $suffix";
        $legal = "$biz " . pick(['Pvt Ltd','LLP','Enterprises','Traders','& Sons'], $vid);
        $slug = uslug($biz . '-' . $vid);
        $gstin = sprintf('27AABCV%04dF1Z5', $vid);
        $gstStatus = ($vid % 5 === 0) ? 'pending' : 'verified';
        $status = ($vid % 11 === 0) ? 'submitted' : 'active';
        $email = strtolower($fn) . '.' . strtolower($ln) . $vid . '@demo-vendor.in';
        $phone = sprintf('+9198%09d', 10000000 + $vid);

        $userRows[] = [$uid, 'UUID()', esc('vendor'), esc($person), esc($email), esc($phone), esc('active'), esc($PW)];
        $vendorRows[] = [$vid, 'UUID()', $uid, $bt, esc($legal), esc($biz), esc($slug), esc($gstin), esc($gstStatus), esc($status)];
        $roleRows[] = [$uid, 10, esc('vendor'), $vid];

        $vendorBType[$vid] = $bt;
        $btVendors[$bt][] = $vid;
        $vendorName[$vid] = $biz;
    }
}
bulk('users', ['id','uuid','principal_type','name','email','phone','status','password_hash'], $userRows);
bulk('vendors', ['id','uuid','owner_user_id','business_type_id','legal_name','display_name','slug','gstin','gstin_status','status'], $vendorRows);
bulk('user_roles', ['user_id','role_id','scope_type','scope_id'], $roleRows, 'INSERT IGNORE');

/* ----------------------------------------------------------------------- *
 * 11. Shops (IDs 101-400) across 15 Mumbai neighbourhoods + shop_hours
 * ----------------------------------------------------------------------- */
echo "--> shops + hours\n";
$hoods = [
    ['Andheri West',19.1364,72.8296,'400058'],['DN Nagar',19.1171,72.8311,'400053'],
    ['Lower Oshiwara',19.1407,72.8381,'400058'],['Oshiwara',19.1430,72.8356,'400058'],
    ['Goregaon West',19.1663,72.8494,'400104'],['Bandra West',19.0596,72.8295,'400050'],
    ['Juhu',19.0942,72.8266,'400049'],['Powai',19.1163,72.9060,'400076'],
    ['Malad West',19.1864,72.8484,'400064'],['Borivali West',19.2314,72.8546,'400092'],
    ['Kandivali West',19.2043,72.8373,'400067'],['Santacruz West',19.0814,72.8382,'400054'],
    ['Vile Parle West',19.0990,72.8401,'400056'],['Jogeshwari West',19.1320,72.8427,'400102'],
    ['Dahisar West',19.2587,72.8587,'400068'],
];
$shopRows = []; $shopId = 101; $hoodIdx = 0;
$vendorShops = []; // vid => [shop ids]
foreach ($vendorBType as $vid => $bt) {
    if ($bt === 11) { $nShops = 1; }                       // hotels: 1 property
    else { $r = $vid % 10; $nShops = $r < 6 ? 1 : ($r < 9 ? 2 : 3); }
    for ($s = 0; $s < $nShops; $s++) {
        if ($shopId > 400) break;
        $h = $hoods[$hoodIdx % 15]; $hoodIdx++;
        $name = $vendorName[$vid] . ' — ' . $h[0];
        $code = sprintf('S%03d-%d', $vid, $s + 1);
        $addr = json_encode(['line1' => 'Shop ' . (10 + $shopId % 80) . ', ' . pick(['Link Road','Hill Road','SV Road','Market Lane','Station Road'], $shopId),
            'area' => $h[0], 'city' => 'Mumbai', 'state' => 'MH']);
        $lat = $h[1] + (mt_rand(-40, 40) / 10000);
        $lng = $h[2] + (mt_rand(-40, 40) / 10000);
        $radius = $bt === 11 ? 12.0 : (5 + $shopId % 10); // hotels: serviceable radius so they appear in location-scoped browse
        $prep = $bt === 11 ? 0 : (15 + ($shopId % 4) * 5);
        $pickup = ($bt === 11 || $bt === 10) ? 1 : ($shopId % 4 === 0 ? 0 : 1);
        $gstin = sprintf('27AABCV%04dF1Z5', $vid);
        $sStatus = ($shopId % 13 === 0) ? 'inactive' : 'active';
        $shopRows[] = [$shopId, 'UUID()', $vid, esc($name), esc($code), esc($addr), esc($h[3]), esc('27'),
            $lat, $lng, $radius, $prep, $pickup, esc($gstin), esc('verified'), esc($sStatus)];
        $vendorShops[$vid][] = $shopId;
        $shopId++;
    }
}
bulk('shops', ['id','uuid','vendor_id','name','code','address_json','pincode','state_code','latitude','longitude','delivery_radius_km','prep_time_min','pickup_enabled','gstin','gstin_status','status'], $shopRows);
$lastShopId = $shopId - 1;
echo "    shops created: 101..$lastShopId\n";

// shop_hours: Mon-Sun 09:00-21:00 (Sunday=0 closed for ~10%)
q("INSERT INTO shop_hours (shop_id, day_of_week, open_time, close_time, is_closed)
   SELECT s.id, d.dow,
     CASE WHEN d.dow=0 AND MOD(s.id,10)=0 THEN NULL ELSE '09:00:00' END,
     CASE WHEN d.dow=0 AND MOD(s.id,10)=0 THEN NULL ELSE '21:00:00' END,
     CASE WHEN d.dow=0 AND MOD(s.id,10)=0 THEN 1 ELSE 0 END
   FROM shops s CROSS JOIN (SELECT 0 dow UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
     UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) d
   WHERE s.id BETWEEN 101 AND $lastShopId");

// per-shop delivery economics (storefront shows "Min ₹X · Free over ₹Y")
q("UPDATE shops SET min_order_value=99, delivery_fee=25, free_delivery_above=299
   WHERE id BETWEEN 101 AND $lastShopId AND delivery_fee=0 AND min_order_value=0");

/* ----------------------------------------------------------------------- *
 * 12. Global product tags (IDs 1001-1040)
 * ----------------------------------------------------------------------- */
echo "--> product tags\n";
$tagNames = ['Bestseller','New Launch','Trending','Limited Edition','Eco-Friendly','Premium','Budget Pick',
    'Festive Special','Combo Deal','Imported','Made in India','Handcrafted','Organic','Vegan','Gluten-Free',
    'Top Rated','Fast Delivery','Exclusive','Value Pack','Gift Worthy','Bestseller 2026','Staff Pick',
    'Clearance','Seasonal','Bulk Available','Doctor Recommended','Dermatologically Tested','BIS Certified',
    'Energy Efficient','Travel Friendly'];
$rows = [];
foreach ($tagNames as $i => $t) {
    $rows[] = [1001 + $i, 'UUID()', 'NULL', esc($t), esc(uslug('tag-' . $t))];
}
bulk('product_tags', ['id','uuid','vendor_id','name','slug'], $rows, 'INSERT IGNORE');

/* ----------------------------------------------------------------------- *
 * 13. PRODUCTS + variants + variant attribute values + satellites
 * ----------------------------------------------------------------------- */
echo "--> products (this is the big one)\n";

// resolve leaves per themed group
$leaves = [
    'electronics' => leavesUnder(101, $catDefs, $childrenOf),
    'mobiles'     => leavesUnder(102, $catDefs, $childrenOf),
    'laptops'     => leavesUnder(106, $catDefs, $childrenOf),
    'audio'       => leavesUnder(110, $catDefs, $childrenOf),
    'fashion'     => array_values(array_diff(leavesUnder(118, $catDefs, $childrenOf), leavesUnder(129, $catDefs, $childrenOf))),
    'footwear'    => leavesUnder(129, $catDefs, $childrenOf),
    'grocery'     => leavesUnder(139, $catDefs, $childrenOf),
    'home'        => leavesUnder(156, $catDefs, $childrenOf),
    'beauty'      => leavesUnder(169, $catDefs, $childrenOf),
    'sports'      => leavesUnder(184, $catDefs, $childrenOf),
    'booksdigital'=> [195,196,197,198,199],
    'ebooks'      => [198,199],
    'services'    => [202,203,204],
    'subscription'=> [205],
    'giftcard'    => [207,208],
    'rental'      => [210,211,212],
    'hotel'       => leavesUnder(213, $catDefs, $childrenOf),
    'medical'     => leavesUnder(228, $catDefs, $childrenOf),
];

// state accumulators
$prodRows = [];      // batched product inserts
$pendingVariants = []; // [pid, vendor_id, unit_id, sku, barcode, mrp, base, is_default, weight, attrs=>[[attr_id,value_id]]]
$simpleVariantPool = []; // collected later (need variant ids)
$contentRows = [];
$pavRows = [];       // product_attribute_values (need product_id, attr_id, value_id|text)
$downloadRows = [];
$subRows = [];
$giftRows = [];
$rentalRows = [];

// medical drug name pools
$drugs = ['Paracetamol','Amoxicillin','Azithromycin','Cetirizine','Metformin','Amlodipine','Pantoprazole',
    'Ibuprofen','Aspirin','Atorvastatin','Omeprazole','Domperidone','Montelukast','Levocetirizine',
    'Diclofenac','Ranitidine','Vitamin D3','Vitamin C','Calcium','Iron Folic Acid'];
$ayur = ['Ashwagandha Churna','Triphala Powder','Giloy Kadha','Chyawanprash','Brahmi Vati','Tulsi Drops',
    'Neem Tablets','Amla Juice','Shatavari Powder','Karela Jamun Juice'];
$devices = ['Digital BP Monitor','Glucometer Kit','Pulse Oximeter','Digital Thermometer','Nebulizer',
    'Weighing Scale','Infrared Thermometer'];

// helper to register a product + its variants
$pid = 101;
function priceFor(string $theme, int $pid): array {
    // returns [mrp, base]
    switch ($theme) {
        case 'mobiles':   $mrp = mt_rand(8000, 120000); break;
        case 'laptops':   $mrp = mt_rand(28000, 200000); break;
        case 'audio':     $mrp = mt_rand(799, 35000); break;
        case 'electronics':$mrp = mt_rand(999, 60000); break;
        case 'fashion':   $mrp = mt_rand(399, 6000); break;
        case 'footwear':  $mrp = mt_rand(599, 9000); break;
        case 'grocery':   $mrp = mt_rand(40, 1500); break;
        case 'home':      $mrp = mt_rand(299, 18000); break;
        case 'beauty':    $mrp = mt_rand(149, 4500); break;
        case 'sports':    $mrp = mt_rand(299, 15000); break;
        case 'booksdigital': $mrp = mt_rand(99, 1500); break;
        case 'services':  $mrp = mt_rand(299, 5000); break;
        case 'subscription': $mrp = mt_rand(199, 3000); break;
        case 'giftcard':  $mrp = pick([500,1000,2000,5000,10000], $pid); break;
        case 'rental':    $mrp = mt_rand(500, 20000); break;
        case 'hotel':     $mrp = mt_rand(800, 18000); break;
        case 'medical':   $mrp = mt_rand(25, 1200); break;
        default:          $mrp = mt_rand(199, 5000);
    }
    $base = (int) round($mrp * (mt_rand(78, 95) / 100));
    return [$mrp, $base];
}

/**
 * registerProduct: appends to $prodRows + $pendingVariants + content/satellite arrays.
 * $opts: theme, type, vendorId, catId, setId, hsnKey, taxClass, unitId, brandId,
 *        variantSpec (null|['attr'=>code,'values'=>[...]] or ['multi'=>[[code,vals],...]]),
 *        title
 */
function registerProduct(array $o) {
    global $prodRows, $pendingVariants, $contentRows, $pavRows, $ATTR, $AV, $HSN, $catById;
    $pid = $o['pid'];
    $title = $o['title'];
    $slug = uslug($o['slug'] ?? ($o['type'] . '-product-' . $pid));
    $desc = $o['desc'];
    $brand = $o['brandId'];
    $hsn = $o['hsnId'];
    $inv = in_array($o['type'], ['digital','giftcard','subscription','downloadable']) ? 'unlimited' : 'managed';
    $ship = 'paid';
    if (in_array($o['type'], ['digital','downloadable'])) $ship = 'free';
    elseif (in_array($o['type'], ['service','subscription'])) $ship = 'pickup';
    elseif ($o['theme'] === 'hotel') $ship = 'pickup';
    $boost = $pid % 9 === 0 ? 'featured' : ($pid % 5 === 0 ? 'high' : 'normal');
    $m = $pid % 10;
    $status = $m < 7 ? 'published' : ($m < 9 ? 'submitted' : 'draft');

    $prodRows[] = [
        $pid, 'UUID()', $o['vendorId'], $o['catId'],
        $brand === null ? 'NULL' : $brand,
        $o['setId'] === null ? 'NULL' : $o['setId'],
        $hsn === null ? 'NULL' : $hsn,
        $o['taxClass'], $o['unitId'],
        esc($title), esc($slug), esc($desc),
        esc($o['type']), esc($status),
        esc($inv), esc($ship), esc($boost),
        esc('India'), esc($o['manufacturer'] ?? ''),
    ];

    // variants
    [$mrp, $base] = $o['price'];
    $vseq = 0;
    $variants = [];
    if (!empty($o['variantSpec'])) {
        $combos = $o['variantSpec']; // array of ['label'=>..,'attrs'=>[[attr_id,value_id],...],'delta'=>0]
        foreach ($combos as $i => $cb) {
            $vseq++;
            $vmrp = $mrp + ($cb['delta'] ?? 0);
            $vbase = (int) round($vmrp * ($base / max($mrp, 1)));
            $variants[] = [
                'pid' => $pid, 'vendorId' => $o['vendorId'], 'unitId' => $o['unitId'],
                'sku' => sprintf('V%d-P%d-%03d', $o['vendorId'], $pid, $vseq),
                'mrp' => $vmrp, 'base' => $vbase, 'is_default' => $i === 0 ? 1 : 0,
                'weight' => $o['weight'] ?? 500, 'attrs' => $cb['attrs'],
            ];
        }
    } else {
        $vseq++;
        $variants[] = [
            'pid' => $pid, 'vendorId' => $o['vendorId'], 'unitId' => $o['unitId'],
            'sku' => sprintf('V%d-P%d-%03d', $o['vendorId'], $pid, $vseq),
            'mrp' => $mrp, 'base' => $base, 'is_default' => 1,
            'weight' => $o['weight'] ?? 500, 'attrs' => [],
        ];
    }
    foreach ($variants as $v) $pendingVariants[] = $v;

    // 1:1 content
    $cat = $catById[$o['catId']][2];
    $contentRows[] = [
        $pid,
        esc("Premium $cat from a trusted Mumbai seller. Quality checked, GST-inclusive pricing."),
        esc("$title — $desc Sourced from verified suppliers with fast delivery across Mumbai. " .
            "7-day hassle-free returns. Genuine product with full warranty support where applicable."),
    ];

    // a couple of descriptive (non-variant) spec values for depth
    if (!empty($o['specs'])) {
        foreach ($o['specs'] as $code => $val) {
            if (!isset($ATTR[$code])) continue;
            $vid = $AV[$code . '|' . $val] ?? null;
            $pavRows[] = [$pid, $ATTR[$code], $vid === null ? 'NULL' : $vid, $vid === null ? esc($val) : 'NULL'];
        }
    }
}

// ---- helper builders for variant specs ----
function vc_sizeColor($sizes, $colors) {
    global $ATTR, $AV;
    $out = [];
    foreach ($sizes as $si => $sz) {
        foreach ($colors as $ci => $col) {
            $svid = $AV['size|' . $sz] ?? null; $cvid = $AV['color|' . $col] ?? null;
            if (!$svid || !$cvid) continue;
            $out[] = ['label' => "$sz/$col", 'delta' => $si * 50,
                'attrs' => [[$ATTR['size'], $svid], [$ATTR['color'], $cvid]]];
        }
    }
    return $out;
}
function vc_single($code, $vals, $deltaStep = 0) {
    global $ATTR, $AV;
    $out = [];
    foreach ($vals as $i => $val) {
        $vid = $AV[$code . '|' . $val] ?? null;
        if (!$vid) continue;
        $out[] = ['label' => $val, 'delta' => $i * $deltaStep, 'attrs' => [[$ATTR[$code], $vid]]];
    }
    return $out;
}

$brandFor = fn($i) => $brandIds[$i % count($brandIds)];

/* ---- Segment runner ----
 * Each segment: count, type, theme, vendorBt (or explicit pool), leavesKey, setId, hsnKey, unit, variantMaker
 */
$titleAdj = ['Premium','Classic','Pro','Ultra','Smart','Elite','Essential','Deluxe','Signature','Urban','Eco','Max'];

function nextLeaf(string $key, int $i, array $leaves): int { $a = $leaves[$key]; return $a[$i % count($a)]; }

// vendor pools
$pool = fn($bt) => $btVendors[$bt];

// ===== SIMPLE + light variants (101-400) =====
// grocery / home / beauty carry pack-size / colour / volume variants so EVERY
// shop type (incl. grocery shops) shows multi-variant products, not just fashion.
$simpleSegs = [
    ['n'=>60,'theme'=>'electronics','bt'=>3,'lk'=>'electronics','set'=>1,'hsn'=>'mobiles','unit'=>1],
    ['n'=>60,'theme'=>'fashion','bt'=>4,'lk'=>'fashion','set'=>4,'hsn'=>'mens_apparel','unit'=>1],
    ['n'=>50,'theme'=>'grocery','bt'=>2,'lk'=>'grocery','set'=>6,'hsn'=>'rice','unit'=>2,'mk'=>'pack'],
    ['n'=>40,'theme'=>'footwear','bt'=>1,'lk'=>'footwear','set'=>5,'hsn'=>'footwear','unit'=>1],
    ['n'=>40,'theme'=>'home','bt'=>6,'lk'=>'home','set'=>9,'hsn'=>'kitchen_appliances','unit'=>1,'mk'=>'color'],
    ['n'=>50,'theme'=>'beauty','bt'=>7,'lk'=>'beauty','set'=>8,'hsn'=>'skincare','unit'=>1,'mk'=>'ml'],
];
$ci = 0;
foreach ($simpleSegs as $seg) {
    $vp = $pool($seg['bt']);
    for ($k = 0; $k < $seg['n']; $k++) {
        $vid = $vp[$k % count($vp)];
        $cat = nextLeaf($seg['lk'], $ci, $leaves);
        $bid = $brandFor($pid);
        $name = pick($titleAdj, $pid) . ' ' . $catById[$cat][2] . ' ' . pick(['100','200','Plus','X','Max','Lite','Neo','One'], $pid);
        $ptype = 'simple'; $spec = null;
        if (!empty($seg['mk'])) {
            $ptype = 'variant';
            if ($seg['mk'] === 'pack')       $spec = vc_single('pack_size',    pick([['250g','500g','1kg'],['500g','1kg','2kg'],['1kg','2kg','5kg']], $pid), 30);
            elseif ($seg['mk'] === 'ml')     $spec = vc_single('quantity_ml',  pick([['30ml','50ml','100ml'],['50ml','100ml','200ml']], $pid), 40);
            elseif ($seg['mk'] === 'color')  $spec = vc_single('color',        pick([['Black','White','Grey'],['Beige','Brown','Black']], $pid), 60);
            if (!$spec) { $ptype = 'simple'; }
        }
        registerProduct([
            'pid'=>$pid,'theme'=>$seg['theme'],'type'=>$ptype,'vendorId'=>$vid,'catId'=>$cat,
            'setId'=>$seg['set'],'hsnId'=>$HSN[$seg['hsn']],'taxClass'=>$catById[$cat][3],'unitId'=>$seg['unit'],
            'brandId'=>$bid,'manufacturer'=>$brandNames[$bid-5],
            'title'=>$name,'desc'=>'A dependable everyday choice with great value.',
            'price'=>priceFor($seg['theme'],$pid),'weight'=>mt_rand(200,3000),
            'variantSpec'=>$spec,
            'specs'=>['material'=>pick($attrValues['material'],$pid)],
        ]);
        $pid++; $ci++;
    }
}

// ===== VARIANT (401-680) =====
$variantSegs = [
    ['n'=>140,'theme'=>'fashion','bt'=>4,'lk'=>'fashion','set'=>4,'hsn'=>'mens_apparel','unit'=>1,'mk'=>'sc'],
    ['n'=>80,'theme'=>'electronics','bt'=>3,'lk'=>'mobiles','set'=>1,'hsn'=>'mobiles','unit'=>1,'mk'=>'storage'],
    ['n'=>60,'theme'=>'footwear','bt'=>1,'lk'=>'footwear','set'=>5,'hsn'=>'footwear','unit'=>1,'mk'=>'scShoe'],
];
foreach ($variantSegs as $seg) {
    $vp = $pool($seg['bt']);
    for ($k = 0; $k < $seg['n']; $k++) {
        $vid = $vp[$k % count($vp)];
        $cat = nextLeaf($seg['lk'], $ci, $leaves);
        $bid = $brandFor($pid);
        if ($seg['mk'] === 'sc') {
            $spec = vc_sizeColor(pick([['S','M','L'],['M','L','XL'],['S','M','L','XL']], $pid), pick([['Black','White'],['Blue','Black'],['Red','Black','Navy Blue']], $pid));
            $nm = pick($titleAdj,$pid).' '.$catById[$cat][2];
        } elseif ($seg['mk'] === 'scShoe') {
            $spec = vc_sizeColor(pick([['UK7','UK8','UK9'],['UK8','UK9','UK10'],['UK6','UK7','UK8','UK9']], $pid), pick([['Black','Brown'],['White','Black'],['Brown','Beige']], $pid));
            $nm = pick($titleAdj,$pid).' '.$catById[$cat][2];
        } else { // storage
            $spec = vc_single('storage', pick([['64GB','128GB'],['128GB','256GB'],['128GB','256GB','512GB']], $pid), 4000);
            $nm = pick(['Galaxy','Pixel','Note','Edge','Prime','Aura'],$pid).' '.pick(['S','M','X','Z','Pro'],$pid).pick(['10','12','20','24','7'],$pid);
        }
        if (!$spec) $spec = null;
        registerProduct([
            'pid'=>$pid,'theme'=>$seg['theme'],'type'=>'variant','vendorId'=>$vid,'catId'=>$cat,
            'setId'=>$seg['set'],'hsnId'=>$HSN[$seg['hsn']],'taxClass'=>$catById[$cat][3],'unitId'=>$seg['unit'],
            'brandId'=>$bid,'manufacturer'=>$brandNames[$bid-5],
            'title'=>$nm,'desc'=>'Available in multiple options to suit your needs.',
            'price'=>priceFor($seg['theme'],$pid),'weight'=>mt_rand(200,2000),
            'variantSpec'=>$spec,
            'specs'=>['fabric'=>pick($attrValues['fabric'],$pid)],
        ]);
        $pid++; $ci++;
    }
}

// record simple-product id range for bundle components
$SIMPLE_RANGE = [101, 400];

// ===== BUNDLE (681-760) & COMBO (761-830) =====
foreach ([['bundle',80],['combo',70]] as $bc) {
    [$btype,$bn] = $bc;
    $retailBts = [3,4,2,1,6,7,5]; // incl fnb(5) so F&B vendors also get products
    for ($k = 0; $k < $bn; $k++) {
        $bt = $retailBts[$k % count($retailBts)];
        $vp = $pool($bt); $vid = $vp[$k % count($vp)];
        $cat = nextLeaf(pick(['electronics','fashion','grocery','home','beauty'], $pid), $ci, $leaves);
        $bid = $brandFor($pid);
        $nm = ($btype==='combo'?'Combo Offer: ':'Value Bundle: ').pick($titleAdj,$pid).' '.$catById[$cat][2].' Pack';
        registerProduct([
            'pid'=>$pid,'theme'=>'electronics','type'=>$btype,'vendorId'=>$vid,'catId'=>$cat,
            'setId'=>null,'hsnId'=>$HSN['snacks'],'taxClass'=>$catById[$cat][3],'unitId'=>7,
            'brandId'=>$bid,'manufacturer'=>$brandNames[$bid-5],
            'title'=>$nm,'desc'=>'Specially priced '.$btype.' — buy together and save more!',
            'price'=>priceFor('home',$pid),'weight'=>mt_rand(500,5000),
        ]);
        $pid++; $ci++;
    }
}

// ===== DIGITAL (831-900) =====
for ($k=0;$k<70;$k++){
    $vp=$pool(9);$vid=$vp[$k%count($vp)];$cat=198;$bid=$brandFor($pid);
    $nm=pick(['Masterclass','Course','Template Pack','Wallpaper Set','Preset Bundle','Stock Pack'],$pid).' — '.pick(['Photography','Marketing','Coding','Design','Finance','Yoga'],$pid);
    registerProduct(['pid'=>$pid,'theme'=>'booksdigital','type'=>'digital','vendorId'=>$vid,'catId'=>$cat,
        'setId'=>10,'hsnId'=>$HSN['software'],'taxClass'=>4,'unitId'=>1,'brandId'=>$bid,'manufacturer'=>$brandNames[$bid-5],
        'title'=>$nm,'desc'=>'Instant digital delivery. Lifetime access.','price'=>priceFor('booksdigital',$pid),'weight'=>0]);
    $downloadRows[]=[$pid,'Main download file',null,5,365];
    $pid++;$ci++;
}

// ===== SERVICE (901-960) =====
for ($k=0;$k<60;$k++){
    $vp=$pool(10);$vid=$vp[$k%count($vp)];$cat=nextLeaf('services',$ci,$leaves);$bid=$brandFor($pid);
    $nm=$catById[$cat][2].' — '.pick(['Basic','Standard','Premium','Express'],$pid).' Plan';
    registerProduct(['pid'=>$pid,'theme'=>'services','type'=>'service','vendorId'=>$vid,'catId'=>$cat,
        'setId'=>null,'hsnId'=>$HSN['services'],'taxClass'=>4,'unitId'=>1,'brandId'=>$bid,'manufacturer'=>$brandNames[$bid-5],
        'title'=>$nm,'desc'=>'Professional service delivered at your doorstep in Mumbai.','price'=>priceFor('services',$pid),'weight'=>0]);
    $pid++;$ci++;
}

// ===== SUBSCRIPTION (961-1020) =====
for ($k=0;$k<60;$k++){
    $vp=$pool(10);$vid=$vp[$k%count($vp)];$cat=205;$bid=$brandFor($pid);
    $nm=pick(['Fresh Box','Care Plan','Pro Membership','Wellness Club','Snack Subscription'],$pid).' '.pick(['Lite','Plus','Max'],$pid);
    [$mrp,$base]=priceFor('subscription',$pid);
    registerProduct(['pid'=>$pid,'theme'=>'subscription','type'=>'subscription','vendorId'=>$vid,'catId'=>$cat,
        'setId'=>null,'hsnId'=>$HSN['services'],'taxClass'=>4,'unitId'=>1,'brandId'=>$bid,'manufacturer'=>$brandNames[$bid-5],
        'title'=>$nm,'desc'=>'Recurring subscription billed automatically. Cancel anytime.','price'=>[$mrp,$base],'weight'=>0]);
    $subRows[]=[$pid,esc('Monthly Plan'),'monthly',$base,'NULL',7,1];
    $subRows[]=[$pid,esc('Annual Plan'),'yearly',(int)round($base*10),'NULL',14,1];
    $pid++;$ci++;
}

// ===== GIFTCARD (1021-1070) =====
for ($k=0;$k<50;$k++){
    $vp=$pool(9);$vid=$vp[$k%count($vp)];$cat=nextLeaf('giftcard',$ci,$leaves);$bid=$brandFor($pid);
    $nm=pick(['Shopping','Dining','Festive','Birthday','Anniversary'],$pid).' Gift Card';
    registerProduct(['pid'=>$pid,'theme'=>'giftcard','type'=>'giftcard','vendorId'=>$vid,'catId'=>$cat,
        'setId'=>null,'hsnId'=>$HSN['services'],'taxClass'=>1,'unitId'=>1,'brandId'=>$bid,'manufacturer'=>$brandNames[$bid-5],
        'title'=>$nm,'desc'=>'Digital gift card redeemable across the marketplace.','price'=>priceFor('giftcard',$pid),'weight'=>0]);
    $giftRows[]=[$pid,esc(json_encode([500,1000,2000,5000,10000])),500,10000,365,0];
    $pid++;$ci++;
}

// ===== DOWNLOADABLE (1071-1160) =====
for ($k=0;$k<90;$k++){
    $vp=$pool(9);$vid=$vp[$k%count($vp)];$cat=199;$bid=$brandFor($pid);
    $nm=pick(['Antivirus','Office Suite','Photo Editor','VPN','Game','Utility Tool'],$pid).' '.pick(['2026','Pro','Home','Studio'],$pid).' License';
    registerProduct(['pid'=>$pid,'theme'=>'booksdigital','type'=>'downloadable','vendorId'=>$vid,'catId'=>$cat,
        'setId'=>10,'hsnId'=>$HSN['software'],'taxClass'=>4,'unitId'=>1,'brandId'=>$bid,'manufacturer'=>$brandNames[$bid-5],
        'title'=>$nm,'desc'=>'Downloadable software with a license key.','price'=>priceFor('booksdigital',$pid),'weight'=>0]);
    $downloadRows[]=[$pid,'Installer + license',null,5,365];
    $pid++;$ci++;
}

// ===== RENTAL (1161-1250) =====
for ($k=0;$k<90;$k++){
    $rentBts=[8,6];$bt=$rentBts[$k%2];$vp=$pool($bt);$vid=$vp[$k%count($vp)];
    $cat=nextLeaf('rental',$ci,$leaves);$bid=$brandFor($pid);
    $nm=pick($titleAdj,$pid).' '.$catById[$cat][2].' (Rental)';
    [$mrp,$base]=priceFor('rental',$pid);
    registerProduct(['pid'=>$pid,'theme'=>'rental','type'=>'rental','vendorId'=>$vid,'catId'=>$cat,
        'setId'=>null,'hsnId'=>$HSN['services'],'taxClass'=>4,'unitId'=>1,'brandId'=>$bid,'manufacturer'=>$brandNames[$bid-5],
        'title'=>$nm,'desc'=>'Rent for events & short-term use. Refundable deposit applies.','price'=>[$mrp,$base],'weight'=>mt_rand(1000,8000),
        'variantSpec'=>vc_single('rental_duration',['Per Day','1 Week','1 Month'],(int)round($base*0.5))]);
    $rentalRows[]=[$pid,(int)round($base/10),(int)round($base*0.5),1,30];
    $pid++;$ci++;
}

// ===== HOTEL (1251-1750) =====
$hotelVendors=$pool(11);
$roomThemes=['Standard Room','Deluxe Room','Super Deluxe','Suite','Studio','1BHK Apartment','2BHK Apartment','Dormitory Bed'];
for ($k=0;$k<500;$k++){
    $vid=$hotelVendors[intdiv($k,50)%count($hotelVendors)];
    $cat=nextLeaf('hotel',$ci,$leaves);$bid=null;
    $hood=$hoods[$k%15][0];
    $room=pick($roomThemes,$pid);
    $nm="$room — $hood";
    [$mrp,$base]=priceFor('hotel',$pid);
    registerProduct(['pid'=>$pid,'theme'=>'hotel','type'=>'rental','vendorId'=>$vid,'catId'=>$cat,
        'setId'=>11,'hsnId'=>$HSN['hotel'],'taxClass'=>$catById[$cat][3],'unitId'=>1,'brandId'=>$bid,'manufacturer'=>$vendorName[$vid],
        'title'=>$nm,'desc'=>"Comfortable $room in $hood, Mumbai. Per-night tariff, GST as applicable.",
        'price'=>[$mrp,$base],'weight'=>0,
        'variantSpec'=>vc_single('meal_plan',['EP - Room Only','CP - Breakfast Included','MAP - Breakfast & Dinner'],(int)round($base*0.15)),
        'specs'=>['room_type'=>$room,'star_rating'=>pick($attrValues['star_rating'],$pid)]]);
    $rentalRows[]=[$pid,$base,0,1,30];
    $pid++;$ci++;
}

// ===== MEDICAL (1751-2250) =====
$medVendors=$pool(12);
for ($k=0;$k<500;$k++){
    $vid=$medVendors[intdiv($k,50)%count($medVendors)];
    $cat=nextLeaf('medical',$ci,$leaves);$bid=$brandFor($pid);
    $root=$catById[$cat][2];
    // pick a sensible name + variant by sub-area
    if (in_array($cat,[238,239,240,241,253])) { // devices
        $nm=pick($devices,$pid);$type='simple';$spec=null;$hsn=$HSN['medical_devices'];$set=12;
        $specs=['device_type'=>pick($attrValues['device_type'],$pid)];
    } elseif (in_array($cat,[247,248,249])) { // ayurveda
        $nm=pick($ayur,$pid);$type='variant';$hsn=$HSN['medicines'];$set=12;
        $spec=vc_single('pack_type',['Box of 1','Box of 5'],40);
        $specs=['ayurveda_type'=>pick($attrValues['ayurveda_type'],$pid),'dosage_form'=>'Powder'];
    } else { // medicines
        $drug=pick($drugs,$pid);$str=pick(['250mg','500mg','100mg','5ml','60ml'],$pid);
        $nm="$drug $str";$type='variant';$hsn=$HSN['medicines'];$set=12;
        $spec=vc_single('pack_type',pick([['Strip of 10','Strip of 15'],['Strip of 10','Strip of 30'],['Bottle 60ml','Bottle 100ml']],$pid),15);
        $specs=['dosage_form'=>pick(['Tablet','Capsule','Syrup'],$pid),'strength'=>$str,
                'prescription_type'=>pick($attrValues['prescription_type'],$pid),
                'manufacturer_med'=>pick($attrValues['manufacturer_med'],$pid)];
    }
    registerProduct(['pid'=>$pid,'theme'=>'medical','type'=>$type,'vendorId'=>$vid,'catId'=>$cat,
        'setId'=>$set,'hsnId'=>$hsn,'taxClass'=>$catById[$cat][3],'unitId'=>1,'brandId'=>$bid,'manufacturer'=>$brandNames[$bid-5],
        'title'=>$nm,'desc'=>"$root product. Store as directed. Read the label before use.",
        'price'=>priceFor('medical',$pid),'weight'=>mt_rand(20,500),
        'variantSpec'=>$spec,'specs'=>$specs]);
    $pid++;$ci++;
}

$LAST_PID = $pid - 1;
echo "    products built in memory: 101..$LAST_PID (".count($prodRows)." rows, ".count($pendingVariants)." variants)\n";

// ---- flush products ----
echo "--> inserting products\n";
bulk('products', ['id','uuid','vendor_id','category_id','brand_id','attribute_set_id','hsn_sac_id',
    'tax_class_id','base_unit_id','title','slug','description','product_type','status',
    'inventory_mode','shipping_type','search_boost','country_of_origin','manufacturer'], $prodRows);

// ---- insert variants one-by-one to capture ids; collect VAV + simple pool ----
echo "--> inserting variants + variant attribute values\n";
$vavRows = [];
$simplePoolVar = []; // variant ids for products 101-400
$defaultVarOf = [];
foreach ($pendingVariants as $v) {
    $barcode = sprintf('890%010d', mt_rand(1, 9999999999));
    $sql = "INSERT INTO product_variants (uuid, product_id, vendor_id, sku, barcode, unit_id, mrp, base_price, weight_grams, is_default, status)
            VALUES (UUID()," . $v['pid'] . "," . $v['vendorId'] . "," . esc($v['sku']) . "," . esc($barcode) . "," .
            $v['unitId'] . "," . $v['mrp'] . "," . $v['base'] . "," . $v['weight'] . "," . $v['is_default'] . ",'active')";
    q($sql);
    $varId = (int) mysqli_insert_id($db);
    if ($v['pid'] >= 101 && $v['pid'] <= 400 && $v['is_default']) $simplePoolVar[] = $varId;
    foreach ($v['attrs'] as $pair) {
        $vavRows[] = [$varId, $pair[0], $pair[1]];
    }
}
bulk('variant_attribute_values', ['variant_id','attribute_id','attribute_value_id'], $vavRows, 'INSERT IGNORE');

// ---- product_shops + inventory: make products carryable & deliverable ----
// The storefront only lists a product if it has an active product_shops row in a
// shop that delivers to the customer; cart/stock needs an inventory row per variant.
echo "--> product_shops + inventory (storefront visibility + stock)\n";
q("INSERT IGNORE INTO product_shops (product_id, shop_id, status, listed_at)
   SELECT p.id, s.id, 'active', NOW()
   FROM products p JOIN shops s ON s.vendor_id = p.vendor_id AND s.deleted_at IS NULL
   WHERE p.id BETWEEN 101 AND $LAST_PID AND s.id BETWEEN 101 AND 400");
q("INSERT IGNORE INTO inventory (uuid, variant_id, shop_id, on_hand, reserved, status)
   SELECT UUID(), pv.id, s.id, (25 + MOD(pv.id,175)), 0, 'in_stock'
   FROM product_variants pv
   JOIN products p ON p.id = pv.product_id
   JOIN shops s ON s.vendor_id = p.vendor_id AND s.deleted_at IS NULL
   WHERE p.id BETWEEN 101 AND $LAST_PID AND s.id BETWEEN 101 AND 400");

// Purchase limits so min/max/step enforcement is real & demonstrable on the
// storefront (cart/checkout enforce these + managed stock).
q("UPDATE products SET min_purchase_qty=1, qty_step=1.000, max_purchase_qty=10
   WHERE id BETWEEN 101 AND $LAST_PID");

// ---- bundle items: link bundles/combos (681-830) to simple variants ----
echo "--> bundle/combo items\n";
$biRows = [];
$poolN = count($simplePoolVar);
for ($bp = 681; $bp <= 830; $bp++) {
    $n = 2 + ($bp % 3); // 2-4 components
    for ($j = 0; $j < $n; $j++) {
        $vidx = ($bp * 7 + $j * 13) % $poolN;
        $biRows[] = ['UUID()', $bp, $simplePoolVar[$vidx], 1, 0, 'NULL', $j + 1];
    }
}
bulk('product_bundle_items', ['uuid','bundle_product_id','item_variant_id','qty','is_optional','price_contribution','sort_order'], $biRows, 'INSERT IGNORE');

// ---- satellite tables ----
echo "--> satellite tables (content, downloads, subs, giftcards, rentals, PAV)\n";
bulk('product_content', ['product_id','short_description','full_description'], $contentRows, 'INSERT IGNORE');

$rows = [];
foreach ($downloadRows as $d) $rows[] = ['UUID()', $d[0], 'NULL', esc($d[1]), $d[3], $d[4]];
bulk('product_downloads', ['uuid','product_id','media_id','label','download_limit','expiry_days'], $rows, 'INSERT IGNORE');

$rows = [];
foreach ($subRows as $s) $rows[] = ['UUID()', $s[0], $s[1], esc($s[2]), $s[3], $s[4], $s[5], $s[6], "'active'"];
bulk('product_subscription_plans', ['uuid','product_id','name','interval_unit','price','billing_cycles','trial_days','auto_renew','status'], $rows, 'INSERT IGNORE');

$rows = [];
foreach ($giftRows as $g) $rows[] = [$g[0], $g[1], $g[2], $g[3], $g[4], $g[5]];
bulk('gift_card_products', ['product_id','denominations_json','min_amount','max_amount','validity_days','is_reloadable'], $rows, 'INSERT IGNORE');

$rows = [];
foreach ($rentalRows as $r2) $rows[] = [$r2[0], $r2[1], $r2[2], $r2[3], $r2[4]];
bulk('product_rental_terms', ['product_id','price_per_day','deposit','min_days','max_days'], $rows, 'INSERT IGNORE');

bulk('product_attribute_values', ['product_id','attribute_id','attribute_value_id','value_text'], $pavRows, 'INSERT IGNORE');

// ---- tags, labels, faqs (SELECT-based bulk) ----
echo "--> tag map, label map, faqs\n";
q("INSERT IGNORE INTO product_tag_map (product_id, tag_id)
   SELECT p.id, t.id FROM products p JOIN product_tags t ON t.vendor_id IS NULL
   WHERE p.id BETWEEN 101 AND $LAST_PID AND MOD(p.id + t.id, 9) IN (0,3)");

q("INSERT IGNORE INTO product_label_map (product_id, label_id)
   SELECT p.id, (MOD(p.id,7)+1) FROM products p WHERE p.id BETWEEN 101 AND $LAST_PID");

q("INSERT INTO product_faqs (uuid, product_id, question, answer, sort_order, status)
   SELECT UUID(), p.id, 'Is this available for delivery across Mumbai?',
     'Yes. We deliver across all serviceable Mumbai pincodes, usually within 2-4 days.', 1, 'active'
   FROM products p WHERE p.id BETWEEN 101 AND $LAST_PID
   UNION ALL
   SELECT UUID(), p.id, 'What is the return / cancellation policy?',
     'Eligible items can be returned within 7 days of delivery in original condition. Services & perishable goods may vary.', 2, 'active'
   FROM products p WHERE p.id BETWEEN 101 AND $LAST_PID");

q("SET FOREIGN_KEY_CHECKS = 1");

/* ----------------------------------------------------------------------- *
 * Verification summary
 * ----------------------------------------------------------------------- */
echo "\n==> VERIFICATION\n";
$checks = [
    'vendors'        => "SELECT COUNT(*) c FROM vendors WHERE id BETWEEN 101 AND 230",
    'shops'          => "SELECT COUNT(*) c FROM shops WHERE id BETWEEN 101 AND 400",
    'shop_hours'     => "SELECT COUNT(*) c FROM shop_hours WHERE shop_id BETWEEN 101 AND 400",
    'categories'     => "SELECT COUNT(*) c FROM categories WHERE id BETWEEN 101 AND 259",
    'attributes'     => "SELECT COUNT(*) c FROM attributes WHERE id BETWEEN 6 AND 85",
    'attr_values'    => "SELECT COUNT(*) c FROM attribute_values WHERE attribute_id >= 6",
    'attribute_sets' => "SELECT COUNT(*) c FROM attribute_sets WHERE id BETWEEN 1 AND 12",
    'products'       => "SELECT COUNT(*) c FROM products WHERE id BETWEEN 101 AND 2250",
    'variants'       => "SELECT COUNT(*) c FROM product_variants WHERE product_id BETWEEN 101 AND 2250",
    'variant_attrs'  => "SELECT COUNT(*) c FROM variant_attribute_values vav JOIN product_variants pv ON vav.variant_id=pv.id WHERE pv.product_id BETWEEN 101 AND 2250",
    'bundle_items'   => "SELECT COUNT(*) c FROM product_bundle_items WHERE bundle_product_id BETWEEN 101 AND 2250",
    'sub_plans'      => "SELECT COUNT(*) c FROM product_subscription_plans WHERE product_id BETWEEN 101 AND 2250",
    'gift_cards'     => "SELECT COUNT(*) c FROM gift_card_products WHERE product_id BETWEEN 101 AND 2250",
    'rentals'        => "SELECT COUNT(*) c FROM product_rental_terms WHERE product_id BETWEEN 101 AND 2250",
    'downloads'      => "SELECT COUNT(*) c FROM product_downloads WHERE product_id BETWEEN 101 AND 2250",
    'content'        => "SELECT COUNT(*) c FROM product_content WHERE product_id BETWEEN 101 AND 2250",
    'faqs'           => "SELECT COUNT(*) c FROM product_faqs WHERE product_id BETWEEN 101 AND 2250",
    'product_shops'  => "SELECT COUNT(*) c FROM product_shops WHERE product_id BETWEEN 101 AND 2250",
    'inventory'      => "SELECT COUNT(*) c FROM inventory WHERE shop_id BETWEEN 101 AND 400",
    'deliverable'    => "SELECT COUNT(DISTINCT ps.product_id) c FROM product_shops ps JOIN shops s ON s.id=ps.shop_id WHERE ps.status='active' AND s.status='active' AND ps.product_id BETWEEN 101 AND 2250",
];
foreach ($checks as $label => $sql) {
    $r = q($sql); $row = mysqli_fetch_assoc($r);
    printf("    %-16s %s\n", $label, $row['c']);
}
echo "\n    Products by type:\n";
$r = q("SELECT product_type, COUNT(*) c FROM products WHERE id BETWEEN 101 AND 2250 GROUP BY product_type ORDER BY c DESC");
while ($row = mysqli_fetch_assoc($r)) printf("      %-14s %s\n", $row['product_type'], $row['c']);

echo "\n    FK integrity (all should be 0):\n";
foreach ([
    'orphan vendor'   => "SELECT COUNT(*) c FROM products p LEFT JOIN vendors v ON p.vendor_id=v.id WHERE p.id>100 AND v.id IS NULL",
    'orphan category' => "SELECT COUNT(*) c FROM products p LEFT JOIN categories c ON p.category_id=c.id WHERE p.id>100 AND c.id IS NULL",
    'orphan bundle'   => "SELECT COUNT(*) c FROM product_bundle_items b LEFT JOIN product_variants v ON b.item_variant_id=v.id WHERE v.id IS NULL",
    'no-variant prods'=> "SELECT COUNT(*) c FROM products p LEFT JOIN product_variants v ON v.product_id=p.id WHERE p.id BETWEEN 101 AND 2250 AND v.id IS NULL",
] as $label => $sql) {
    $r = q($sql); $row = mysqli_fetch_assoc($r);
    printf("      %-18s %s\n", $label, $row['c']);
}

echo "\n==> DONE. Now run:  php database/seed_images.php\n";
mysqli_close($db);
