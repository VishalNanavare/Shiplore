<?php
declare(strict_types=1);

require __DIR__ . '/_db.php';
$pdo = shiplore_pdo();

$vendorId = 1;
$shopIds  = [1, 2, 3, 4, 5];

$pStmt = $pdo->prepare(
    "INSERT INTO products (uuid,vendor_id,category_id,tax_class_id,base_unit_id,
     title,slug,product_type,status,is_pos_enabled,is_online_enabled,origin)
     VALUES (UUID(),?,?,?,?,?,?,'simple','published',1,1,'server')"
);
$vStmt = $pdo->prepare(
    "INSERT INTO product_variants (uuid,product_id,vendor_id,sku,mrp,base_price,
     unit_id,is_default,status,origin) VALUES (UUID(),?,?,?,?,?,?,1,'active','server')"
);
$iStmt = $pdo->prepare(
    "INSERT INTO inventory (uuid,shop_id,variant_id,on_hand,reserved,reorder_level,origin)
     VALUES (UUID(),?,?,?,0,?,'server')"
);

$cnt = (int)$pdo->query('SELECT MAX(id) FROM products')->fetchColumn();

function addProduct(PDO $pdo, $pStmt, $vStmt, $iStmt, int $vendorId, array $shopIds,
    int $catId, int $taxCls, int $unitId, string $title, string $prefix, float $price, int &$cnt): void
{
    $cnt++;
    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower($title))) . '-' . $cnt;
    $pStmt->execute([$vendorId, $catId, $taxCls, $unitId, $title, $slug]);
    $pid = (int)$pdo->lastInsertId();
    $sku = strtoupper($prefix) . '-' . str_pad((string)$cnt, 7, '0', STR_PAD_LEFT);
    $mrp = (int)($price * 1.15);
    $vStmt->execute([$pid, $vendorId, $sku, $mrp, $price, $unitId]);
    $vid = (int)$pdo->lastInsertId();
    foreach ($shopIds as $sid) {
        $oh = rand(15, 300);
        $iStmt->execute([$sid, $vid, $oh, rand(5, 20)]);
    }
}

// ── More Grocery (cat=139, tax=2, unit=1)
$grocExtra = [
    ['Annapurna','Sharbati Atta 10kg'],['Shakti Bhog','Premium Maida 5kg'],
    ['Pillsbury','Whole Wheat Dough Mix'],['Kohinoor','Premium Basmati 5kg'],
    ['India Gate','Classic Basmati 25kg'],['Dawat','Rozana Rice 10kg'],
    ['Ramdev','Rajwadi Pure Ghee'],['Gopaljee','Cow Ghee 500g'],
    ['Mother Dairy','Buffalo Ghee 1L'],['Lijjat','Papad Plain 400g'],
    ['Catch','Black Pepper Powder 50g'],['MDH','Deggi Mirch 500g'],
    ['Everest','Meat Masala 100g'],['Badshah','Pav Bhaji Masala 100g'],
    ['Shan','Biryani Mix 60g'],['Maggi','2-Minute Noodles 8pk'],
    ['Yippee','Magic Masala Noodles 4pk'],['Top Ramen','Curry Noodles'],
    ['Sunfeast','Dark Fantasy Choco Fills 300g'],['Tiger','Glucose Biscuits 1kg'],
    ['Treat','Fruit Biscuits 600g'],['Lays','American Cream Onion 26g'],
    ['Doritos','Nacho Cheese 40g'],['Kurkure','Masala Munch 90g'],
    ['Paper Boat','Aamras 200ml'],['Tropicana','Grape 1L'],
    ['Real','Pomegranate Juice 1L'],['Nescafe','Classic 100g'],
    ['Bru','Gold Instant Coffee 50g'],['Continental','Xtra Strong Coffee 200g'],
    ['Tetley','Masala Chai 100 Bags'],['Lipton','Yellow Label 250g'],
    ['Wagh Bakri','Premium Tea 500g'],['Epigamia','Greek Yogurt Strawberry'],
    ['Go','Cheese Slices 10pk'],['Amul','Processed Cheese 200g'],
    ['Britannia','Winkin Cow Milkshake'],['Amul Kool','Kesar Flavour 200ml'],
    ['Mother Dairy','Mishti Doi 400g'],['Himalaya','Mineral Water 2L'],
    ['Bisleri','Mineral Water 1L'],['Kinley','Club Soda 600ml'],
    ['Eno','Regular Antacid Sachet'],['Hajmola','Imli Digestive'],
    ['Dabur','Chyawanprash 1kg'],['Glucon D','Orange Energy Drink'],
    ['Rooh Afza','Rose Sharbat 750ml'],['Mapro','Strawberry Crush 750ml'],
    ['Borges','EVOO 500ml'],['Leonardo','Sunflower Oil 5L'],
];
foreach ($grocExtra as [$b, $t]) {
    addProduct($pdo, $pStmt, $vStmt, $iStmt, $vendorId, $shopIds,
        139, 2, 1, "$b $t", 'GRC', rand(50, 900), $cnt);
}

// ── More Electronics (cat=7, tax=4, unit=1)
$elecExtra = [
    ['Sony','PlayStation 5 Disc Edition'],['Sony','PS5 Digital Edition'],
    ['Apple','Watch Series 9 GPS 41mm'],['Apple','Watch Series 9 GPS 45mm'],
    ['Samsung','Galaxy Watch 6 Classic'],['Garmin','Forerunner 255 Sports Watch'],
    ['Amazfit','GTR 4 Smart Watch'],['Noise','ColorFit Pro 5'],
    ['boAt','Storm Call Smartwatch'],['Realme','Watch 3 Pro'],
    ['Canon','EOS 1500D DSLR 18-55 Kit'],['Nikon','D3500 DSLR Kit'],
    ['Sony','Alpha ZV-E10 Mirrorless'],['GoPro','HERO 12 Black'],
    ['DJI','Mini 3 Drone Fly More'],['Canon','Pixma G3010 Printer'],
    ['HP','DeskJet 2336 All-in-One'],['Epson','EcoTank L3252'],
    ['LG','27MP400 IPS Monitor 27'],['Samsung','27" CF390 Curved'],
    ['BenQ','GW2490 24" Eye-care'],['Dell','P2422H FHD Monitor'],
    ['Logitech','MX Master 3S Wireless'],['Logitech','K380 Multi-Device BT'],
    ['Corsair','K65 Mini Mechanical'],['Razer','DeathAdder V3 Pro'],
    ['Western Digital','My Passport 2TB Black'],['Seagate','Backup Plus 1TB'],
    ['Samsung','T7 Portable SSD 500GB'],['Kingston','NV2 1TB NVMe SSD'],
    ['OnePlus','Pad Go 10.1 WiFi'],['Samsung','Galaxy Tab A9+ 5G'],
    ['Realme','Pad 2 4G 8GB 128GB'],['Lenovo','Tab M11 LTE'],
    ['Xiaomi','Pad 6 Pro WiFi 8GB'],['Motorola','Moto Tab G70 LTE'],
    ['Boat','Nirvana 751 ANC BT'],['Boat','Rockerz 255 Pro+ Neckband'],
    ['JBL','Tune 760NC Wireless'],['Sennheiser','HD 350BT'],
    ['Philips','65W GaN Laptop Charger'],['Anker','Prime 240W GaN 4 Port'],
    ['Belkin','10000mAh MagSafe Bank'],['Portronics','Kronos Duo 67W'],
    ['Adata','XPG 750W Gold PSU'],['Corsair','16GB DDR5 RAM 5600MHz'],
    ['LG','Full HD IPS 22" Monitor'],['Acer','Aspire Lite Ryzen 5'],
    ['HP','Pavilion Gaming 15 i5'],['Dell','Inspiron 16 5000 i7'],
];
foreach ($elecExtra as [$b, $t]) {
    addProduct($pdo, $pStmt, $vStmt, $iStmt, $vendorId, $shopIds,
        7, 4, 1, "$b $t", 'ELC', rand(3000, 80000), $cnt);
}

// ── More Home & Kitchen (cat=156, tax=3, unit=1)
$homeExtra = [
    ['Prestige','Clip-on Induction 2000W'],['Havells','Instanio 1L Water Heater'],
    ['Bajaj','New Shakti 15L Storage'],['Crompton','Aura 25L Geyser'],
    ['Orient','i-Fresh Air Cooler 70L'],['Symphony','Diet 12T Personal Cooler'],
    ['Havells','Fresco 36 inch Ceiling Fan'],['Anchor','6A 3-Pin 4-Socket Board'],
    ['Pureit','Classic G2 Mineral 7L RO'],['Kent','Grand Plus RO 8L'],
    ['Aquaguard','Aura 7L NXT RO UV'],['Livpure','Pep Pro Plus 7L RO'],
    ['Tupperware','Modular 4-Piece Bowl Set'],['Vaya','Tyffyn 600ml Lunchbox'],
    ['Borosil','Vision Glass 350ml 6pc'],['Treo','Opalware Dinner Set 19pcs'],
    ['La Opala','Sovrana Dinner Set 27pcs'],['Bergner','Orion Cookware 5pcs'],
    ['Vinod','Platinum Triply 5pcs'],['Nirlon','Granite Kadai 28cm'],
    ['Sumeet','Classique Non-Stick 5pc'],['Hawkins','Futura L73 Kadai 22cm'],
    ['Pigeon','By Stovekraft Nonstick'],['Wonderchef','Ebony Frying Pan 28cm'],
    ['Prestige','Svachh Pressure Hob 5L'],['Hawkins','Contura 5L Pressure Cooker'],
    ['Butterfly','Wave Pressure Pan 3L'],['Paderno','Stainless Mixing Bowl Set'],
    ['Solimo','Casserole Set 3pcs'],['Milton','Thermosteel Flip Lid Flask'],
    ['Cello','H2O Vacuum Bottle 750ml'],['Signoraware','Slim Box 500ml'],
    ['Nayasa','Modular Airtight Container'],['Allo','Microwave-safe Container Set'],
    ['Wonderbag','Thermal Cooker 4.5L'],['Prestige','Clip-on Rice Cooker'],
    ['Pigeon','Healthifry Digital AF 5.5L'],['Lifelong','LLAF27 Air Fryer 4L'],
    ['Agaro','Regal OTG 40L'],['Usha','3716 Mixer Grinder 750W'],
];
foreach ($homeExtra as [$b, $t]) {
    addProduct($pdo, $pStmt, $vStmt, $iStmt, $vendorId, $shopIds,
        156, 3, 1, "$b $t", 'HMK', rand(500, 9000), $cnt);
}

// ── More Health & Beauty (cat=169, tax=3, unit=5)
$healthExtra = [
    ['Minimalist','Alpha Arbutin 2% Serum'],['Minimalist','Niacinamide 10% Face Serum'],
    ['Minimalist','Retinol 0.3% Serum'],['Minimalist','Vitamin C 10% Serum'],
    ['Plum','Green Tea Face Wash 100ml'],['Plum','E-Luminence Face Moisturizer'],
    ['WOW','Activated Charcoal Face Wash'],['WOW','Apple Cider Vinegar Shampoo'],
    ['Mamaearth','Onion Hair Oil 150ml'],['Mamaearth','Ubtan Face Wash 100ml'],
    ['Biotique','Bio Coconut Whitening Cream'],['Biotique','Papaya Scrub 75g'],
    ['Khadi Natural','Aloe Vera Gel 200g'],['Khadi Natural','Lavender Hair Oil'],
    ['Cetaphil','Gentle Skin Cleanser 250ml'],['Cetaphil','Daily Hydrating Lotion'],
    ['Neutrogena','Ultra Sheer Dry-Touch SPF50'],['Neutrogena','Hydro Boost Gel Cream'],
    ['Himalaya','Neem Face Wash 150ml'],['Himalaya','Purifying Neem Mask 75g'],
    ['Lakmé','9 to 5 Primer Matte'],['Lakmé','Absolute Matte Lipstick'],
    ['L\'Oreal','Revitalift Hyaluronic Serum'],['L\'Oreal','Extraordinary Oil Shampoo'],
    ['Garnier','Bright Complete Vitamin C Serum'],['Garnier','Fructis Long & Strong Oil'],
    ['Dove','Intense Repair Shampoo 340ml'],['Dove','Body Love Lotion 400ml'],
    ['Nivea','Soft Moisturising Cream 200ml'],['Nivea','Men Dark Spot Reduction'],
    ['Head Shoulders','Anti-Dandruff Shampoo'],['Pantene','Pro-V Miracles Serum'],
    ['Sunsilk','Thick & Long Shampoo'],['TRESemme','Keratin Smooth Shampoo'],
    ['Schwarzkopf','Gliss Total Repair'],['Streax','Hair Serum Argan 45ml'],
    ['Swiss Beauty','Pure Matte Lipstick'],['Blue Heaven','Diamond Kajal'],
    ['Colorbar','Take Me As I Am Foundation'],['Sugar','Smudge Me Not Liner'],
    ['Faces Canada','Ultime Pro HD Primer'],['Insight','Cosmetics Kajal Black'],
];
foreach ($healthExtra as [$b, $t]) {
    addProduct($pdo, $pStmt, $vStmt, $iStmt, $vendorId, $shopIds,
        169, 3, 5, "$b $t", 'HLT', rand(150, 1800), $cnt);
}

// ── More Sports (cat=184, tax=4, unit=1)
$sportsExtra = [
    ['Yonex','Voltric 1 DG Badminton Racket'],['Yonex','Mavis 350 Nylon Shuttle'],
    ['Victor','JS-09 Badminton Racket'],['Li-Ning','Windstorm 72 Racket'],
    ['Cosco','Champion Volleyball'],['Nivia','Glider Football Size 5'],
    ['SG','Scorer Leather Cricket Ball'],['SG','VS 319 Extreme Bat'],
    ['SS','Ton Maximus English Willow'],['BDM','Diamond Edition Bat'],
    ['DSC','Condor Batting Gloves'],['Kookaburra','Pro 600 Batting Pads'],
    ['Puma','evoSPEED Spike Shoes'],['Asics','Gel-Nimbus 25 Running'],
    ['Reebok','Nano X3 Cross-Training'],['Skechers','GO Walk 7 Slip-On'],
    ['Strauss','7-in-1 Resistance Band Set'],['Boldfit','Adjustable Dumbbells 20kg'],
    ['Lifelong','LLDB25 Adjustable Dumbbell'],['Kore','K-PVC-2KG Dumbbell Pair'],
    ['Burnlab','BodyBoss Gym Bag 35L'],['Adidas','Stadium 3 Gym Bag'],
    ['Nivia','Super Gym Gloves'],['Strauss','Arm Blaster Bicep Curl'],
    ['Boldfit','Foam Roller 60cm'],['Strauss','Ab Wheel Roller Foldable'],
    ['Joerex','Skipping Rope Pro PVC'],['Nivia','Carbonite-8 Volleyball'],
    ['Cosco','Torino Basketball'],['Stag','Table Tennis Table'],
    ['Cornilleau','100 Outdoor TT Table'],['Butterfly','Nakama G8 TT Set'],
];
foreach ($sportsExtra as [$b, $t]) {
    addProduct($pdo, $pStmt, $vStmt, $iStmt, $vendorId, $shopIds,
        184, 4, 1, "$b $t", 'SPT', rand(400, 12000), $cnt);
}

// ── Final count
$total = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$vTotal = (int)$pdo->query('SELECT COUNT(*) FROM product_variants')->fetchColumn();
$iTotal = (int)$pdo->query('SELECT COUNT(*) FROM inventory')->fetchColumn();
echo "────────────────────────────────────────────────────\n";
echo "AFTER TOP-UP:\n";
echo "  Products  : $total\n";
echo "  Variants  : $vTotal\n";
echo "  Inventory : $iTotal rows\n";
echo "────────────────────────────────────────────────────\n";
