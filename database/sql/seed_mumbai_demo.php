<?php
/**
 * Mumbai Bazaar Demo Seed
 * Truncates vendor/shop/product tables and seeds 10,000+ realistic products
 * with variants and inventory for a Mumbai multi-shop vendor.
 *
 * Usage: php seed_mumbai_demo.php
 */
declare(strict_types=1);
ini_set('memory_limit', '512M');
set_time_limit(600);

$pdo = new PDO('mysql:host=127.0.0.1;dbname=test;charset=utf8mb4', 'root', 'root@123', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->exec("SET time_zone = '+05:30'");

// ── TRUNCATE ──────────────────────────────────────────────────────────────────
$truncateTables = [
    'pos_sale_items', 'pos_sale_payments', 'pos_sale_taxes', 'pos_sales',
    'pos_shifts', 'pos_terminals',
    'order_items', 'sub_orders', 'orders',
    'inventory', 'product_media', 'product_variants', 'products',
    'shops', 'vendor_staff', 'vendors',
];
foreach ($truncateTables as $t) {
    try { $pdo->exec("TRUNCATE TABLE `$t`"); say("✓ TRUNCATED $t"); }
    catch (Exception $e) { say("  SKIP $t ({$e->getMessage()})"); }
}
foreach (['product_shops', 'notifications'] as $t) {
    try { $pdo->exec("TRUNCATE TABLE `$t`"); } catch (Exception $e) {}
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// ── VENDOR ────────────────────────────────────────────────────────────────────
$pdo->exec("INSERT INTO vendors
    (uuid, owner_user_id, legal_name, display_name, slug, gstin, state_code, status, origin)
    VALUES (UUID(), 101, 'Mumbai Bazaar Retail Pvt Ltd', 'Mumbai Bazaar',
            'mumbai-bazaar', '27AABCM9999R1ZX', '27', 'active', 'server')");
$vendorId = (int)$pdo->lastInsertId();
say("Vendor ID: $vendorId");

// ── SHOPS ─────────────────────────────────────────────────────────────────────
$shopDefs = [
    ['Mumbai Bazaar — Andheri West',  'MB-AW', 'SVP Nagar, Andheri West',        'Andheri West', 'Mumbai', '400058', 19.1397, 72.8272],
    ['Mumbai Bazaar — Bandra',         'MB-BW', 'Linking Road, Bandra West',      'Bandra West',  'Mumbai', '400050', 19.0596, 72.8295],
    ['Mumbai Bazaar — Thane West',     'MB-TH', 'Viviana Mall Road, Thane West',  'Thane West',   'Thane',  '400601', 19.2183, 72.9781],
    ['Mumbai Bazaar — Powai',          'MB-PW', 'Hiranandani Gardens, Powai',     'Powai',        'Mumbai', '400076', 19.1176, 72.9060],
    ['Mumbai Bazaar — Borivali West',  'MB-BV', 'SV Road, Borivali West',         'Borivali West','Mumbai', '400092', 19.2339, 72.8553],
];
$shopIds = [];
$shopStmt = $pdo->prepare(
    "INSERT INTO shops (uuid, vendor_id, name, code, gstin, gstin_status,
     address_json, pincode, state_code, latitude, longitude,
     pickup_enabled, min_order_value, delivery_fee, status, origin)
     VALUES (UUID(), ?, ?, ?, '27AABCM9999R1ZX', 'unverified', ?, ?, '27', ?, ?,
             1, 0.00, 0.00, 'active', 'server')"
);
foreach ($shopDefs as [$name, $code, $line1, $area, $city, $pin, $lat, $lng]) {
    $addr = json_encode(['line1' => $line1, 'area' => $area, 'city' => $city]);
    $shopStmt->execute([$vendorId, $name, $code, $addr, $pin, $lat, $lng]);
    $shopIds[] = (int)$pdo->lastInsertId();
}
say("Shops: " . implode(', ', $shopIds));

// ── CATEGORY / TAX / UNIT LOOKUPS ─────────────────────────────────────────────
// category_id => [tax_class_id, unit_id, price_min, price_max, mrp_mult]
$catConfig = [
    7   => [4, 1, 999,   129999, 1.15],   // Electronics → 18%
    118 => [3, 1, 299,   5999,   1.20],   // Fashion & Apparel → 12%
    1   => [4, 1, 499,   12999,  1.15],   // Footwear → 18%
    139 => [2, 1, 25,    999,    1.10],   // Grocery & Food → 5%
    156 => [3, 1, 199,   9999,   1.20],   // Home & Kitchen → 12%
    169 => [3, 5, 99,    1999,   1.15],   // Health & Beauty → 12%, ml unit
    184 => [4, 1, 299,   14999,  1.15],   // Sports & Fitness → 18%
];
$unitPiece = 1; $unitKg = 2; $unitGram = 3; $unitMl = 5;

// ── PRODUCT DEFINITIONS ───────────────────────────────────────────────────────
// Format: [category_id, product_type, [[variantLabel, skuSuffix, priceMultiplier], ...]]
// product_type: 'simple' | 'variant'

// ─── ELECTRONICS (cat=7, 1500 products) ──────────────────────────────────────
$elecBrands = ['Samsung','Apple','OnePlus','Realme','Xiaomi','Vivo','OPPO','Sony','Motorola','Nothing',
                'iQOO','Infinix','Tecno','Lava','Nokia','Asus','HP','Dell','Lenovo','Acer'];
$elecModels = [
    // Smartphones
    'Galaxy S24 Ultra','Galaxy A54','iPhone 15 Pro','iPhone 14','Nord 3','Nord CE 3','12 Pro+',
    '13 Ultra','Redmi Note 13','POCO X6','Y100','Reno 11','K70 Pro','Find N3','Moto G84',
    'Nothing Phone 2','V30 Pro','G85','C65','GT 6T','12 Turbo','Pixel 8',
    // Laptops
    'Galaxy Book3 Pro','MacBook Air M2','MacBook Pro 14','Pavilion 15','Vostro 15','Ideapad Slim 5',
    'IdeaPad Gaming 3','TUF Gaming A15','ROG Strix G15','Predator Helios Neo','Envy 15','Spectre x360',
    'ThinkPad E15','Yoga 7i','XPS 15','Inspiron 16',
    // TVs
    'Crystal 4K 43"','Crystal 4K 55"','QLED 65"','Bravia XR 55"','Bravia XR 65"',
    'WebOS 43" Smart','55" 4K UHD','65" OLED','43" Smart FHD','32" Smart HD',
    // Accessories
    'Galaxy Buds2 Pro','AirPods Pro 2','OnePlus Buds Pro 2','Sony WH-1000XM5',
    'JBL Tune 770','Boat Rockerz 450','Sennheiser HD 450','Realme Buds Air 5',
    'Samsung 65W Charger','Anker 67W GaN','Belkin 20000mAh','Mi 10000mAh',
];
shuffle($elecBrands); shuffle($elecModels);

$elecColors = ['Phantom Black', 'Cream White', 'Forest Green', 'Lavender', 'Midnight Blue', 'Titanium'];
$elecStorage = ['64GB', '128GB', '256GB', '512GB', '1TB'];

// ─── FASHION & APPAREL (cat=118, 2500 products) ───────────────────────────────
$fashBrands = ['Levi\'s','Wrangler','Allen Solly','Van Heusen','Arrow','Peter England',
                'FabIndia','W for Women','Biba','Mango','H&M','Zara','UCB','Puma','Nike',
                'Roadster','Here&Now','HRX','Adidas','Reebok','Jack&Jones','Selected Homme',
                'Raymond','Park Avenue','Blackberrys'];
$fashTypes = [
    'Regular Fit T-Shirt', 'Slim Fit T-Shirt', 'Oversized T-Shirt', 'Polo T-Shirt',
    'Formal Shirt', 'Casual Shirt', 'Chambray Shirt', 'Oxford Shirt', 'Linen Shirt',
    'Slim Fit Jeans', 'Regular Jeans', 'Skinny Jeans', 'Wide Leg Jeans',
    'Chinos', 'Formal Trousers', 'Cargo Pants', 'Jogger Pants', 'Track Pants',
    'Kurta', 'Straight Kurta', 'A-Line Kurta', 'Palazzo Pants Set',
    'Saree', 'Lehenga', 'Anarkali Dress', 'Indo-Western Set',
    'Sweatshirt', 'Hoodie', 'Bomber Jacket', 'Denim Jacket', 'Blazer',
    'Maxi Dress', 'Midi Dress', 'Wrap Dress', 'Shift Dress', 'Bodycon Dress',
    'Kurti', 'Palazzo Kurti Set', 'Ethnic Jacket', 'Nehru Jacket',
    'Sports Shorts', 'Running Shorts', 'Bicycle Shorts', 'Board Shorts',
];
$fashColors = ['Black', 'White', 'Navy Blue', 'Grey', 'Red', 'Green', 'Blue', 'Maroon',
               'Mustard Yellow', 'Olive Green', 'Teal', 'Coral', 'Lavender', 'Beige'];
$fashSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];

// ─── FOOTWEAR (cat=1, 1000 products) ─────────────────────────────────────────
$footBrands = ['Nike','Adidas','Puma','Reebok','New Balance','Skechers','Bata','Woodland',
                'Red Chief','Sparx','Campus','Liberty','Lee Cooper','Asics','Under Armour'];
$footTypes = [
    'Running Shoes', 'Sports Shoes', 'Training Shoes', 'Trail Running Shoes',
    'Casual Sneakers', 'Canvas Shoes', 'Slip-Ons', 'Loafers',
    'Formal Derby Shoes', 'Oxford Shoes', 'Monks', 'Brogues',
    'Sandals', 'Flip Flops', 'Sports Sandals', 'Slides',
    'High Ankle Boots', 'Chelsea Boots', 'Trekking Boots',
];
$footColors = ['Black', 'White', 'Navy', 'Grey', 'Brown', 'Red', 'Blue'];
$footSizes = ['UK 6', 'UK 7', 'UK 8', 'UK 9', 'UK 10', 'UK 11'];

// ─── GROCERY (cat=139, 3000 products) ────────────────────────────────────────
$grocBrands = ['Tata','Patanjali','Nestlé','Amul','Britannia','ITC','Haldiram\'s','MTR',
               'Fortune','Saffola','Dabur','Marico','HUL','P&G','Godrej','Parle','Sunfeast',
               'Cadbury','Kit Kat','Maggi','Knorr','Kissan','Dr Oetker','Quaker','Kellogg\'s'];
$grocTypes = [
    // Staples
    'Aashirvaad Atta 5kg', 'Whole Wheat Atta 10kg', 'Basmati Rice Premium', 'Sona Masoori Rice',
    'Kolam Rice 25kg', 'Toor Dal', 'Chana Dal', 'Moong Dal', 'Masoor Dal', 'Urad Dal',
    'Yellow Moong Dal', 'Rajma', 'Kabuli Chana', 'Black Chana',
    // Oils & Spices
    'Refined Sunflower Oil', 'Extra Virgin Olive Oil', 'Groundnut Oil', 'Rice Bran Oil',
    'Mustard Oil', 'Coconut Oil', 'Ghee', 'Turmeric Powder', 'Red Chilli Powder',
    'Coriander Powder', 'Cumin Seeds', 'Garam Masala', 'Kitchen King Masala',
    'Chicken Masala', 'Pav Bhaji Masala', 'Biryani Masala', 'Sambar Masala',
    // Beverages
    'Masala Chai', 'Green Tea', 'Darjeeling Tea', 'Cold Coffee', 'Instant Coffee',
    'Filter Coffee', 'Ovaltine', 'Horlicks', 'Bournvita', 'Complan', 'Mango Juice',
    'Mixed Fruit Juice', 'Orange Juice', 'Coconut Water', 'Sparkling Water',
    // Snacks
    'Aloo Bhujia', 'Mix Namkeen', 'Kurkure', 'Bingo', 'Lay\'s Chips', 'Pringles',
    'Digestive Biscuits', 'Good Day Biscuits', 'Monaco Crackers', 'Parle-G',
    'Hide & Seek', 'Bourbon', 'Marie Light', 'Thums Up 2L', 'Pepsi 1.5L',
    // Dairy & Frozen
    'Full Cream Milk', 'Toned Milk', 'Skimmed Milk', 'Paneer', 'Curd Set',
    'Greek Yogurt', 'Buttermilk', 'Mozzarella Cheese', 'Cheddar Cheese',
    'Amul Butter', 'Low Fat Butter', 'Cream Cheese', 'Malai Kulfi', 'Vanilla Ice Cream',
    // Breakfast & Ready to Eat
    'Oats Instant', 'Rolled Oats', 'Corn Flakes', 'Muesli', 'Granola',
    'Upma Mix', 'Poha', 'Idli Rice', 'Dosa Batter Ready', 'Sambar Powder',
    'Instant Poha', 'Dal Makhani Ready', 'Rajma Ready', 'Chole Ready',
];
$grocPackSizes = ['500g', '1kg', '2kg', '5kg', '200ml', '500ml', '1L', '2L', '250g', '100g'];

// ─── HOME & KITCHEN (cat=156, 1500 products) ──────────────────────────────────
$homeBrands = ['Prestige','Hawkins','Pigeon','Cello','Milton','Tupperware','Borosil',
               'Morphy Richards','Philips','Bajaj','Crompton','Havells','Usha','Orient',
               'Butterfly','Wipro Lighting','Anchor','Finolex','Polycab'];
$homeTypes = [
    // Cookware
    '3L Pressure Cooker', '5L Pressure Cooker', '7.5L Pressure Cooker',
    'Non-Stick Tawa', 'Non-Stick Kadai 28cm', 'Deep Kadai with Lid',
    'Induction Friendly Set', 'Casserole Set', 'Roti Tawa', 'Dosa Tawa',
    // Appliances
    'Mixer Grinder 750W', 'Mixer Grinder 500W', 'Hand Blender', 'Juicer',
    'Electric Kettle 1.5L', 'Pop-up Toaster', 'Sandwich Maker', 'Induction Cooktop',
    'OTG 28L', 'Air Fryer 4L', 'Rice Cooker 1.8L', 'Microwave 20L',
    // Storage & Containers
    'Stainless Steel Tiffin 3-tier', 'Glass Casserole 1L', 'Plastic Container Set',
    'Airtight Steel Containers', 'Water Bottle 1L', 'Insulated Bottle 750ml',
    'Fridge Container Set', 'Bread Box', 'Spice Rack', 'Masala Dabba',
    // Small Appliances
    'Table Fan 400mm', 'Pedestal Fan', 'Ceiling Fan', 'LED Bulb 9W',
    'LED Batten 20W', 'Smart LED Bulb', 'Extension Board 6A', 'UPS 600VA',
    // Cleaning
    'Broom', 'Mop with Bucket', 'Spin Mop', 'Vacuum Cleaner Dry',
    'Washing Machine Cover', 'Dish Rack', 'Scrubber Set', 'Dustbin 10L',
];

// ─── HEALTH & BEAUTY (cat=169, 1500 products) ─────────────────────────────────
$healthBrands = ['Dove','Himalaya','Patanjali','Nivea','Lakmé','L\'Oréal','Garnier',
                 'Head & Shoulders','Pantene','Mamaearth','WOW','Biotique','Khadi Natural',
                 'Forest Essentials','Kama Ayurveda','Cetaphil','Neutrogena','Plum','Minimalist'];
$healthTypes = [
    // Skincare
    'Face Wash 100ml', 'Face Wash 200ml', 'Daily Moisturizer SPF 15',
    'Sunscreen SPF 50+ 75ml', 'Night Cream 50g', 'Eye Cream 15ml',
    'Vitamin C Serum 30ml', 'Hyaluronic Acid Serum', 'Niacinamide 10% Serum',
    'Face Scrub 100g', 'Clay Mask', 'Sheet Mask', 'Toner 200ml',
    // Haircare
    'Shampoo Anti-Dandruff 400ml', 'Shampoo Damage Repair 200ml',
    'Conditioner 180ml', 'Hair Oil 200ml', 'Coconut Hair Oil 300ml',
    'Almond Hair Oil', 'Hair Serum 50ml', 'Heat Protection Spray',
    'Hair Mask 200g', 'Dry Shampoo', 'Hair Color Medium Brown',
    // Personal Care
    'Body Lotion 400ml', 'Body Wash 250ml', 'Deodorant Roll-On 50ml',
    'Deodorant Spray 150ml', 'Talcum Powder 200g', 'Lip Balm SPF',
    'Hand Cream 50ml', 'Foot Cream 75g', 'Whitening Toothpaste 150g',
    'Charcoal Toothpaste', 'Mouthwash 500ml', 'Beard Oil 30ml',
    // Makeup
    'Foundation 30ml', 'Compact Powder', 'Blush On', 'Eyeshadow Palette',
    'Kajal', 'Eyeliner', 'Mascara', 'Lipstick Matte', 'Lip Gloss',
    'BB Cream', 'Primer 30ml', 'Setting Spray', 'Makeup Remover',
];
$healthVolumes = ['50ml', '100ml', '200ml', '50g', '100g', '200g'];

// ─── SPORTS & FITNESS (cat=184, 500 products) ─────────────────────────────────
$sportsBrands = ['Yonex','Cosco','Nivia','SG','MRF','Reebok','Nike','Adidas',
                 'Puma','Decathlon','Strauss','Vector X','DSC','BDM'];
$sportsTypes = [
    // Cricket
    'English Willow Bat Grade A', 'Kashmir Willow Bat', 'Leather Cricket Ball',
    'Tennis Cricket Ball', 'Cricket Gloves', 'Thigh Guard', 'Elbow Guard',
    'Wicket Keeping Gloves', 'Cricket Kit Bag',
    // Football & Other
    'Football Size 5', 'Football Size 4', 'Volleyball', 'Basketball Size 7',
    'Badminton Racket UT', 'Badminton Feather Shuttle', 'Badminton Nylon Shuttle',
    'Table Tennis Bat', 'TT Table', 'Carrom Board',
    // Gym & Fitness
    'Dumbbell Set 10kg', 'Dumbbell 5kg Pair', 'Barbell Rod', 'Weight Plates Set',
    'Resistance Bands Set', 'Pull Up Bar', 'Ab Roller', 'Jump Rope',
    'Yoga Mat 6mm', 'Yoga Mat 8mm', 'Foam Roller', 'Gym Gloves',
    'Knee Support', 'Ankle Support', 'Wrist Band', 'Gym Bag 35L',
    'Protein Shaker 700ml', 'Gym Belt', 'Treadmill', 'Elliptical Machine',
    // Swimming
    'Swimming Goggles', 'Swim Cap Silicone', 'Kickboard', 'Pull Buoy',
];

// ── GENERATE PRODUCTS ─────────────────────────────────────────────────────────
$productStmt = $pdo->prepare(
    "INSERT INTO products (uuid, vendor_id, category_id, tax_class_id, base_unit_id,
     title, slug, product_type, status, is_pos_enabled, is_online_enabled, origin)
     VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, 'published', 1, 1, 'server')"
);
$variantStmt = $pdo->prepare(
    "INSERT INTO product_variants (uuid, product_id, vendor_id, sku, mrp, base_price,
     unit_id, is_default, status, origin)
     VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, 'active', 'server')"
);
$inventoryStmt = $pdo->prepare(
    "INSERT INTO inventory (uuid, shop_id, variant_id, on_hand, reserved, reorder_level, origin)
     VALUES (UUID(), ?, ?, ?, 0, ?, 'server')"
);

$totalProducts = 0;
$totalVariants = 0;
$slugSet = [];
$skuSet  = [];

// Helper: safe unique slug
function makeSlug(string $text, array &$used): string {
    $base = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)));
    $base = trim($base, '-');
    if (strlen($base) > 150) $base = substr($base, 0, 150);
    $s = $base; $c = 1;
    while (isset($used[$s])) { $s = $base . '-' . $c++; }
    $used[$s] = true;
    return $s;
}

// Helper: safe unique SKU
function makeSku(string $prefix, array &$used): string {
    static $cnt = 0;
    $cnt++;
    $s = strtoupper($prefix) . '-' . str_pad((string)$cnt, 7, '0', STR_PAD_LEFT);
    while (isset($used[$s])) { $cnt++; $s = strtoupper($prefix) . '-' . str_pad((string)$cnt, 7, '0', STR_PAD_LEFT); }
    $used[$s] = true;
    return $s;
}

function insertProduct(PDO $pdo, $pStmt, $vStmt, $iStmt,
    int $vendorId, array $shopIds,
    int $catId, int $taxClassId, int $unitId, int $unitIdVariant,
    string $title, string $slugBase, string $skuPrefix,
    array $variants, // [[label, priceMult], ...]
    float $basePrice, float $mrp,
    array &$slugSet, array &$skuSet,
    int &$totalProducts, int &$totalVariants): void
{
    $slug = makeSlug($slugBase, $slugSet);
    $productType = count($variants) > 1 ? 'variant' : 'simple';
    $pStmt->execute([$vendorId, $catId, $taxClassId, $unitId, $title, $slug, $productType]);
    $productId = (int)$pdo->lastInsertId();
    $totalProducts++;

    $isDefault = 1;
    foreach ($variants as [$label, $priceMult]) {
        $vPrice = round($basePrice * $priceMult);
        $vMrp   = round($mrp * $priceMult);
        $sku    = makeSku($skuPrefix, $skuSet);
        $vStmt->execute([$productId, $vendorId, $sku, $vMrp, $vPrice, $unitIdVariant, $isDefault]);
        $variantId = (int)$pdo->lastInsertId();
        $isDefault = 0;

        // Inventory per shop
        foreach ($shopIds as $shopId) {
            $onHand = rand(20, 500);
            $iStmt->execute([$shopId, $variantId, $onHand, rand(5, 20)]);
        }
        $totalVariants++;
    }
}

$pdo->beginTransaction();

// ─── ELECTRONICS ─────────────────────────────────────────────────────────────
say("\nGenerating Electronics...");
$catId = 7; $taxClassId = 4; $unitId = $unitPiece;
$counter = 0;
foreach ($elecBrands as $brand) {
    foreach ($elecModels as $model) {
        $counter++;
        // Determine variant type
        if (str_contains($model, 'GB') || str_contains($model, 'TB')) {
            // Storage variants
            $storages = ['128GB', '256GB', '512GB'];
            $color = $elecColors[array_rand($elecColors)];
            $basePrice = rand(8000, 90000);
            $mrp = (int)($basePrice * 1.15);
            $variants = [];
            $mult = 1.0;
            foreach ($storages as $s) {
                $variants[] = [$s . ' ' . $color, $mult];
                $mult += 0.35;
            }
            $title = "$brand $model";
        } elseif (str_contains($model, '"')) {
            // TV - no size variants, just 1
            $basePrice = rand(15000, 80000);
            $mrp = (int)($basePrice * 1.12);
            $variants = [['Standard', 1.0]];
            $title = "$brand $model Smart TV";
        } else {
            // Color variants
            $colors = array_slice($elecColors, 0, rand(2, 3));
            $basePrice = rand(1500, 50000);
            $mrp = (int)($basePrice * 1.15);
            $variants = array_map(fn($c) => [$c, 1.0], $colors);
            $title = "$brand $model";
        }
        insertProduct($pdo, $productStmt, $variantStmt, $inventoryStmt,
            $vendorId, $shopIds, $catId, $taxClassId, $unitId, $unitId,
            $title, $title, 'ELC',
            $variants, $basePrice, $mrp,
            $slugSet, $skuSet, $totalProducts, $totalVariants);

        if ($counter % 500 === 0) {
            $pdo->commit(); $pdo->beginTransaction();
            say("  Electronics: $counter products ($totalVariants variants so far)");
        }
        if ($totalProducts >= 1500) break;
    }
    if ($totalProducts >= 1500) break;
}
say("  Electronics done: $totalProducts products");

// ─── FASHION & APPAREL ────────────────────────────────────────────────────────
say("\nGenerating Fashion & Apparel...");
$catId = 118; $taxClassId = 3; $unitId = $unitPiece;
$fashStart = $totalProducts;
foreach ($fashBrands as $brand) {
    foreach ($fashTypes as $type) {
        foreach ($fashColors as $color) {
            $basePrice = rand(299, 5999);
            $mrp = (int)($basePrice * 1.20);
            $sizeVariants = [];
            $sizes = in_array($type, ['Saree','Lehenga','Anarkali Dress','Indo-Western Set'])
                ? [['Free Size', 1.0]]
                : array_map(fn($s) => [$s, 1.0], ['S','M','L','XL','XXL']);
            $title = "$brand $color $type";
            insertProduct($pdo, $productStmt, $variantStmt, $inventoryStmt,
                $vendorId, $shopIds, $catId, $taxClassId, $unitId, $unitId,
                $title, $title, 'FSH',
                $sizes, $basePrice, $mrp,
                $slugSet, $skuSet, $totalProducts, $totalVariants);

            if (($totalProducts - $fashStart) % 500 === 0 && ($totalProducts - $fashStart) > 0) {
                $pdo->commit(); $pdo->beginTransaction();
                say("  Fashion: " . ($totalProducts - $fashStart) . " products ($totalVariants variants total)");
            }
            if ($totalProducts - $fashStart >= 2500) break 3;
        }
        if ($totalProducts - $fashStart >= 2500) break 2;
    }
    if ($totalProducts - $fashStart >= 2500) break;
}
say("  Fashion done: " . ($totalProducts - $fashStart) . " products");

// ─── FOOTWEAR ─────────────────────────────────────────────────────────────────
say("\nGenerating Footwear...");
$catId = 1; $taxClassId = 4; $unitId = $unitPiece;
$footStart = $totalProducts;
foreach ($footBrands as $brand) {
    foreach ($footTypes as $type) {
        foreach ($footColors as $color) {
            $basePrice = rand(499, 12999);
            $mrp = (int)($basePrice * 1.15);
            $sizeVariants = array_map(fn($s) => [$s, 1.0], ['UK 6','UK 7','UK 8','UK 9','UK 10']);
            $title = "$brand $color $type";
            insertProduct($pdo, $productStmt, $variantStmt, $inventoryStmt,
                $vendorId, $shopIds, $catId, $taxClassId, $unitId, $unitId,
                $title, $title, 'FTW',
                $sizeVariants, $basePrice, $mrp,
                $slugSet, $skuSet, $totalProducts, $totalVariants);

            if (($totalProducts - $footStart) % 250 === 0 && ($totalProducts - $footStart) > 0) {
                $pdo->commit(); $pdo->beginTransaction();
                say("  Footwear: " . ($totalProducts - $footStart) . " products");
            }
            if ($totalProducts - $footStart >= 1000) break 3;
        }
        if ($totalProducts - $footStart >= 1000) break 2;
    }
    if ($totalProducts - $footStart >= 1000) break;
}
say("  Footwear done: " . ($totalProducts - $footStart) . " products");

// ─── GROCERY & FOOD ───────────────────────────────────────────────────────────
say("\nGenerating Grocery & Food...");
$catId = 139; $taxClassId = 2; $unitId = $unitKg;
$grocStart = $totalProducts;
$packVariantMap = [
    '5kg' => 1.0, '1kg' => 0.22, '500g' => 0.12, '2kg' => 0.42, '250g' => 0.06,
    '1L'  => 1.0, '500ml' => 0.55, '2L' => 1.8, '200ml' => 0.25,
    '100g' => 0.10, '200g' => 0.20,
];
foreach ($grocBrands as $brand) {
    foreach ($grocTypes as $type) {
        $basePrice = rand(25, 999);
        $mrp = (int)($basePrice * 1.10);
        // Pick 2-3 pack size variants
        $packKeys = array_keys($packVariantMap);
        shuffle($packKeys);
        $packs = array_slice($packKeys, 0, rand(2, 3));
        $variants = array_map(fn($p) => [$p, $packVariantMap[$p]], $packs);
        // Unit based on pack
        $vUnitId = str_contains($packs[0], 'ml') || str_contains($packs[0], 'L')
            ? $unitMl : (str_contains($packs[0], 'g') || str_contains($packs[0], 'kg') ? $unitGram : $unitPiece);
        $title = "$brand $type";
        insertProduct($pdo, $productStmt, $variantStmt, $inventoryStmt,
            $vendorId, $shopIds, $catId, $taxClassId, $unitId, $vUnitId,
            $title, $title, 'GRC',
            $variants, $basePrice, $mrp,
            $slugSet, $skuSet, $totalProducts, $totalVariants);

        if (($totalProducts - $grocStart) % 500 === 0 && ($totalProducts - $grocStart) > 0) {
            $pdo->commit(); $pdo->beginTransaction();
            say("  Grocery: " . ($totalProducts - $grocStart) . " products ($totalVariants variants total)");
        }
        if ($totalProducts - $grocStart >= 3000) break 2;
    }
    if ($totalProducts - $grocStart >= 3000) break;
}
say("  Grocery done: " . ($totalProducts - $grocStart) . " products");

// ─── HOME & KITCHEN ───────────────────────────────────────────────────────────
say("\nGenerating Home & Kitchen...");
$catId = 156; $taxClassId = 3; $unitId = $unitPiece;
$homeStart = $totalProducts;
foreach ($homeBrands as $brand) {
    foreach ($homeTypes as $type) {
        $basePrice = rand(199, 9999);
        $mrp = (int)($basePrice * 1.20);
        $variants = [['Standard', 1.0]];
        // Some products have color variants
        if (rand(0,2) > 0) {
            $cols = ['Black', 'White', 'Red'];
            $variants = array_map(fn($c) => [$c, 1.0], array_slice($cols, 0, rand(1, 3)));
        }
        $title = "$brand $type";
        insertProduct($pdo, $productStmt, $variantStmt, $inventoryStmt,
            $vendorId, $shopIds, $catId, $taxClassId, $unitId, $unitId,
            $title, $title, 'HMK',
            $variants, $basePrice, $mrp,
            $slugSet, $skuSet, $totalProducts, $totalVariants);

        if (($totalProducts - $homeStart) % 300 === 0 && ($totalProducts - $homeStart) > 0) {
            $pdo->commit(); $pdo->beginTransaction();
            say("  Home: " . ($totalProducts - $homeStart) . " products");
        }
        if ($totalProducts - $homeStart >= 1500) break 2;
    }
    if ($totalProducts - $homeStart >= 1500) break;
}
say("  Home & Kitchen done: " . ($totalProducts - $homeStart) . " products");

// ─── HEALTH & BEAUTY ──────────────────────────────────────────────────────────
say("\nGenerating Health & Beauty...");
$catId = 169; $taxClassId = 3; $unitId = $unitMl;
$healthStart = $totalProducts;
foreach ($healthBrands as $brand) {
    foreach ($healthTypes as $type) {
        $basePrice = rand(99, 1999);
        $mrp = (int)($basePrice * 1.15);
        $sizeVariants = [['Regular', 1.0], ['Large Pack', 1.6]];
        if (rand(0,1)) {
            $vols = ['50ml', '100ml', '200ml'];
            $sizeVariants = array_map(fn($v) => [$v, 1.0], array_slice($vols, 0, rand(2,3)));
        }
        $title = "$brand $type";
        insertProduct($pdo, $productStmt, $variantStmt, $inventoryStmt,
            $vendorId, $shopIds, $catId, $taxClassId, $unitId, $unitMl,
            $title, $title, 'HLT',
            $sizeVariants, $basePrice, $mrp,
            $slugSet, $skuSet, $totalProducts, $totalVariants);

        if (($totalProducts - $healthStart) % 300 === 0 && ($totalProducts - $healthStart) > 0) {
            $pdo->commit(); $pdo->beginTransaction();
            say("  Health: " . ($totalProducts - $healthStart) . " products");
        }
        if ($totalProducts - $healthStart >= 1500) break 2;
    }
    if ($totalProducts - $healthStart >= 1500) break;
}
say("  Health & Beauty done: " . ($totalProducts - $healthStart) . " products");

// ─── SPORTS & FITNESS ─────────────────────────────────────────────────────────
say("\nGenerating Sports & Fitness...");
$catId = 184; $taxClassId = 4; $unitId = $unitPiece;
$sportsStart = $totalProducts;
foreach ($sportsBrands as $brand) {
    foreach ($sportsTypes as $type) {
        $basePrice = rand(299, 14999);
        $mrp = (int)($basePrice * 1.15);
        $variants = [['Standard', 1.0]];
        if (rand(0,1)) { $variants = [['Standard', 1.0], ['Pro', 1.4]]; }
        $title = "$brand $type";
        insertProduct($pdo, $productStmt, $variantStmt, $inventoryStmt,
            $vendorId, $shopIds, $catId, $taxClassId, $unitId, $unitId,
            $title, $title, 'SPT',
            $variants, $basePrice, $mrp,
            $slugSet, $skuSet, $totalProducts, $totalVariants);

        if ($totalProducts - $sportsStart >= 500) break 2;
    }
    if ($totalProducts - $sportsStart >= 500) break;
}
say("  Sports done: " . ($totalProducts - $sportsStart) . " products");

// ── COMMIT & FINALIZE ─────────────────────────────────────────────────────────
$pdo->commit();
say("\n" . str_repeat('─', 60));
say("✅  DONE!");
say("   Vendor ID   : $vendorId");
say("   Shops       : " . count($shopIds) . "  → IDs " . implode(', ', $shopIds));
say("   Products    : $totalProducts");
say("   Variants    : $totalVariants");
say("   Inventory   : " . ($totalVariants * count($shopIds)) . " rows (" . count($shopIds) . " shops)");
say(str_repeat('─', 60));

// ── PRINT VENDOR LOGIN ────────────────────────────────────────────────────────
$user = $pdo->query("SELECT id, name, phone FROM users WHERE id = 101 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
say("\nVendor login:");
say("  User: {$user['name']} (ID {$user['id']})");
say("  Phone: {$user['phone']}");
say("\nShops:");
$shops = $pdo->query("SELECT id, name, code FROM shops ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($shops as $s) {
    say("  [{$s['id']}] {$s['name']}  (code: {$s['code']})");
}

function say(string $msg): void { echo $msg . "\n"; flush(); }
