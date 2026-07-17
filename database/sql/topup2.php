<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=test;charset=utf8mb4', 'root', 'root@123', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$vendorId = 1; $shopIds = [1,2,3,4,5];
$pS = $pdo->prepare("INSERT INTO products (uuid,vendor_id,category_id,tax_class_id,base_unit_id,title,slug,product_type,status,is_pos_enabled,is_online_enabled,origin) VALUES (UUID(),?,?,?,?,?,?,'simple','published',1,1,'server')");
$vS = $pdo->prepare("INSERT INTO product_variants (uuid,product_id,vendor_id,sku,mrp,base_price,unit_id,is_default,status,origin) VALUES (UUID(),?,?,?,?,?,?,1,'active','server')");
$iS = $pdo->prepare("INSERT INTO inventory (uuid,shop_id,variant_id,on_hand,reserved,reorder_level,origin) VALUES (UUID(),?,?,?,0,?,'server')");
$cnt = (int)$pdo->query('SELECT MAX(id) FROM products')->fetchColumn();

$matrix = [
    [7,4,1,'ELC',[1500,50000],'Xiaomi',['Smart TV 43 A Pro','Smart TV 55 A Pro','QLED 65 TV','Mi Box 4S','WiFi Router AX3000','Smart LED Strip 2m','360 Security Camera','Smart Door Lock','Mi True Wireless 3','Mi Neckband Pro']],
    [7,4,1,'ELC',[999,30000],'boAt',['Nirvana Ion ANC','Rockerz 450 Pro','Airdopes 141','Wave Neo Smartwatch','Storm Call 2','Bassheads 225','Newly BT Speaker','Rugby Lite Speaker','Airdopes 121v2','Xtend Smart Watch']],
    [7,4,1,'ELC',[800,25000],'Zebronics',['Zeb-Beast 3 Headphone','Juke Bar 3800','Zeb-Buddy BT Speaker','Zeb-Storm Pro Mouse','Zeb-Companion 700 Keyboard','Smart Cam 100','Power 10 Power Bank','Zeb-Sound Feast 30','Zeb-Calyx BT Speaker','Zeb-Action 3 TWS']],
    [118,3,1,'FSH',[399,4999],'Puma',['Men Run Velocity Tee','Men Rebel Hoodie','Women Favourite Tee','Men ESS Logo Polo','Women Bold Dress','Men Rebel Track Pants','Men BMW MS Jacket','Men Training Jersey','Women Running Crop','Men Active Woven Short']],
    [118,3,1,'FSH',[299,3999],'Nike',['Men NSW Club Tee','Men Dri-FIT Graphic','Women Essential Tee','Men Club Fleece Short','Women Gym Vintage Short','Men Jordan Jumpman Tee','Women NSW Fleece Pullover','Men ACG Woven Jogger','Women Air Max Top','Men Sportswear Woven Pant']],
    [118,3,1,'FSH',[499,5999],'Levis',['Men 501 Jeans Black 32x32','Men 511 Slim Blue 30x30','Women 311 Skinny Indigo','Men 512 Slim Taper Grey','Women 315 Bootcut Black','Men Trucker Jacket Denim','Women Western Jacket','Men Barstow Shirt Blue','Women Perfect Tee White','Men Standard Chino Khaki']],
    [139,2,1,'GRC',[45,850],'Nestle',['KitKat 4 Finger 37g','Milkybar 25g','Munch 10.5g','Aero Milk 40g','After Eight 200g','Nestle Milo 200g','Maggi Atta Noodles 4pk','Nescafe Latte 3-in-1','Maggi Korean Noodles','Nestle Choc Mousse']],
    [139,2,1,'GRC',[30,600],'Parle',['Monaco Classic 200g','Hide Seek Chocolate','Parle-G Gold 800g','Kreams Strawberry','Marie Light 150g','20-20 Cashew Cookies','Top Orange Biscuits','Simply Good Oats','Milk Shakti 400g','Cheeselings 100g']],
    [156,3,1,'HMK',[299,7999],'Morphy Richards',['OTG 52L RCSS','Icon OTG 28L','Hand Mixer 300W','Hand Blender 400W','Pop-Up Toaster 2 Slice','Bread Toaster 4 Slice','1.5L Kettle Insta','Electric Lunch Box 2T','Digital Rice Cooker 1.8L','Multicooker 5L']],
    [169,3,5,'HLT',[99,1500],'Derma Co',['Hyaluronic Sunscreen SPF50+','Ceramide Night Cream 50g','AHA BHA Serum 30ml','Niacinamide 10% Serum','Retinol 0.3% Serum','Co-enzyme Q10 Eye Cream','Pore Minimizer Serum','Vitamin C Glow Serum','Salicylic Toner 200ml','Glycolic Acid 10% Serum']],
    [169,3,5,'HLT',[149,1800],'Mcaffeine',['Coffee Body Scrub 100g','Coffee Face Wash 75ml','Naked Coffee Moisturizer','Under-Eye Coffee Gel','Coffee Lip Balm SPF','Coffee Body Butter 100g','Milk Moisturizer 50ml','Coffee Sunscreen SPF50','Latte Night Cream 50g','Coffee Body Wash 300ml']],
    [184,4,1,'SPT',[500,9000],'Nivia',['Carbonite 3 Football','Hurricane Volleyball','Super Grip Basketball','Champion Cricket Ball','Encounter Hard Ball','Hockey Stick Blaze','Speed Agility Ladder','Training Cone 20pk','Javelin 400g Steel','Hurdles Set Plastic']],
    [184,4,1,'SPT',[299,5000],'Boldfit',['Adjustable Incline Bench','Doorway Pull-Up Bar','Battle Rope 10m 32mm','Slam Ball 10kg','Medicine Ball 5kg','Bosu Balance Trainer','TRX Suspension Set','Heavy Punching Bag','Boxing Gloves 12oz','Wrist Roller]']],
    [1,4,1,'FTW',[799,9999],'Woodland',['GC1839 Tan Boots','GC2452 Brown Sandal','GC1519 Black Derby','GC0039 Navy Oxford','GC3445 Casual Grey','GC2733 Trekking','GC2879 Leather Loafer','GC3027 Semi-Formal','GC3266 Sports Run','GC1950 White Sneaker']],
    [1,4,1,'FTW',[699,6999],'Red Chief',['RC00225 Brown Derby','RC00226 Black Oxford','RC00227 Tan Brogue','RC00228 Navy Casual','RC00229 Cognac Monk','RC00230 Dark Boot','RC00231 Taupe Loafer','RC00232 Choco Sneaker','RC00233 Black Sandal','RC00234 Red Sportz']],
];

$pdo->beginTransaction();
foreach ($matrix as [$catId,$taxCls,$unitId,$prefix,[$mn,$mx],$brand,$models]) {
    foreach ($models as $model) {
        $cnt++;
        $title = "$brand $model";
        $slug = strtolower(preg_replace('/[^a-z0-9]+/','-',strtolower($title))).'-'.$cnt;
        $price = rand($mn,$mx); $mrp = (int)($price*1.15);
        $pS->execute([$vendorId,$catId,$taxCls,$unitId,$title,$slug]);
        $pid=(int)$pdo->lastInsertId();
        $sku=$prefix.'-D'.str_pad((string)$cnt,6,'0',STR_PAD_LEFT);
        $vS->execute([$pid,$vendorId,$sku,$mrp,$price,$unitId]);
        $vid=(int)$pdo->lastInsertId();
        foreach($shopIds as $sid){$oh=rand(15,200);$iS->execute([$sid,$vid,$oh,rand(5,15)]);}
    }
}
$pdo->commit();

$total=(int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$vT=(int)$pdo->query('SELECT COUNT(*) FROM product_variants')->fetchColumn();
$iT=(int)$pdo->query('SELECT COUNT(*) FROM inventory')->fetchColumn();
echo "Products  : $total\nVariants  : $vT\nInventory : $iT rows\n";
