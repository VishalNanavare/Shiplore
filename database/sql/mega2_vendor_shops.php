<?php
/**
 * Seeds: 1 vendor (Mumbai Bazaar), 10 000 Mumbai shops, 500 staff users
 * Shop IDs after truncate are deterministic:
 *   1–4 000   Grocery/Kirana
 *   4 001–5 500  Electronics
 *   5 501–7 000  Fashion & Footwear
 *   7 001–8 000  Home & Kitchen
 *   8 001–9 000  Health & Beauty
 *   9 001–9 500  Sports
 *   9 501–10 000 Multi / General
 */
declare(strict_types=1);

require __DIR__ . '/_db.php';
set_time_limit(0);
$pdo = shiplore_pdo();

// ── 1. Owner user ────────────────────────────────────────────────────────────
$pdo->exec("INSERT INTO users (uuid,principal_type,name,email,phone,status,origin)
            VALUES (UUID(),'vendor','Mumbai Bazaar Owner','owner@mumbaibazaar.demo','+919900000001','active','server')");
$ownerUserId = (int)$pdo->lastInsertId();
echo "Owner user id=$ownerUserId\n";

// ── 2. Vendor ────────────────────────────────────────────────────────────────
$pdo->exec("INSERT INTO vendors (uuid,owner_user_id,vendor_kind,legal_name,display_name,slug,gstin,pan,state_code,
            is_composition,payout_cycle,status,origin)
            VALUES (UUID(),$ownerUserId,'independent',
            'Mumbai Bazaar Retail Pvt Ltd','Mumbai Bazaar','mumbai-bazaar',
            '27AABCM9999R1ZX','AABCM9999R','27',0,'weekly','active','server')");
$vendorId = (int)$pdo->lastInsertId();
echo "Vendor id=$vendorId\n";

// ── 3. Mumbai areas (name, lat, lng, pincode) ────────────────────────────────
$areas = [
    ['Colaba',18.9067,72.9161,'400005'],['Fort',18.9322,72.8347,'400001'],
    ['Churchgate',18.9352,72.8266,'400020'],['Nariman Point',18.9246,72.8224,'400021'],
    ['Cuffe Parade',18.9049,72.8196,'400005'],['Ballard Estate',18.9356,72.8410,'400001'],
    ['Kalbadevi',18.9495,72.8301,'400002'],['Bhuleshwar',18.9531,72.8314,'400004'],
    ['Null Bazaar',18.9535,72.8335,'400003'],['Crawford Market',18.9488,72.8326,'400001'],
    ['Byculla',18.9739,72.8374,'400027'],['Mazgaon',18.9719,72.8416,'400010'],
    ['Grant Road',18.9631,72.8178,'400007'],['Charni Road',18.9549,72.8194,'400004'],
    ['Marine Lines',18.9429,72.8237,'400002'],['Nagpada',18.9683,72.8316,'400008'],
    ['Dongri',18.9628,72.8352,'400009'],['Masjid Bunder',18.9460,72.8388,'400003'],
    ['Pydhonie',18.9571,72.8357,'400003'],['Sandhurst Road',18.9602,72.8385,'400009'],
    ['Dadar West',19.0176,72.8449,'400028'],['Dadar East',19.0214,72.8480,'400014'],
    ['Shivaji Park',19.0198,72.8375,'400028'],['Prabhadevi',19.0070,72.8283,'400025'],
    ['Hindu Colony',19.0205,72.8430,'400014'],['Parel',18.9930,72.8371,'400012'],
    ['Lower Parel',18.9978,72.8300,'400013'],['Worli',19.0125,72.8172,'400018'],
    ['Mahalaxmi',18.9837,72.8201,'400034'],['Haji Ali',18.9826,72.8085,'400026'],
    ['Mahim',19.0428,72.8393,'400016'],['Dharavi',19.0425,72.8536,'400017'],
    ['Matunga West',19.0282,72.8467,'400016'],['Matunga East',19.0319,72.8512,'400019'],
    ['Kings Circle',19.0306,72.8487,'400019'],['Sion',19.0396,72.8627,'400022'],
    ['Antop Hill',18.9987,72.8644,'400037'],['Wadala',19.0192,72.8534,'400037'],
    ['Chunabhatti',19.0389,72.8759,'400022'],['Chembur',19.0613,72.9020,'400071'],
    ['Govandi',19.0574,72.9213,'400043'],['Mankhurd',19.0481,72.9373,'400043'],
    ['Trombay',19.0397,72.9426,'400074'],['Kurla West',19.0728,72.8826,'400070'],
    ['Kurla East',19.0737,72.8921,'400024'],['Ghatkopar East',19.0862,72.9080,'400077'],
    ['Ghatkopar West',19.0895,72.8979,'400086'],['Vikhroli East',19.1085,72.9260,'400083'],
    ['Vikhroli West',19.1071,72.9185,'400079'],['Kanjurmarg East',19.1311,72.9399,'400042'],
    ['Kanjurmarg West',19.1297,72.9290,'400078'],['Bhandup East',19.1517,72.9430,'400042'],
    ['Bhandup West',19.1503,72.9335,'400078'],['Mulund East',19.1724,72.9560,'400081'],
    ['Mulund West',19.1762,72.9463,'400080'],['Bandra West',19.0596,72.8295,'400050'],
    ['Bandra East',19.0658,72.8474,'400051'],['Khar West',19.0729,72.8310,'400052'],
    ['Khar East',19.0712,72.8410,'400051'],['Santacruz West',19.0823,72.8390,'400054'],
    ['Santacruz East',19.0838,72.8521,'400055'],['Kalina',19.0875,72.8623,'400098'],
    ['Juhu',19.1013,72.8266,'400049'],['Vile Parle West',19.1002,72.8435,'400056'],
    ['Vile Parle East',19.0986,72.8624,'400057'],['DN Nagar',19.1159,72.8302,'400053'],
    ['Andheri West',19.1269,72.8371,'400058'],['Andheri East',19.1176,72.8777,'400069'],
    ['Versova',19.1342,72.8143,'400061'],['MIDC Andheri',19.1180,72.8784,'400093'],
    ['Jogeshwari West',19.1419,72.8393,'400102'],['Jogeshwari East',19.1361,72.8553,'400060'],
    ['Powai',19.1197,72.9060,'400076'],['Hiranandani Powai',19.1172,72.9144,'400076'],
    ['Chandivali',19.1203,72.9219,'400072'],['Sakinaka',19.1127,72.8919,'400072'],
    ['SEEPZ',19.1122,72.8683,'400096'],['Goregaon West',19.1637,72.8466,'400062'],
    ['Goregaon East',19.1579,72.8684,'400063'],['Film City Road',19.1603,72.8739,'400065'],
    ['Aarey Colony',19.1802,72.8738,'400065'],['Malad West',19.1874,72.8479,'400064'],
    ['Malad East',19.1812,72.8701,'400097'],['Marve',19.2003,72.8074,'400064'],
    ['Kandivali West',19.2003,72.8349,'400067'],['Kandivali East',19.2085,72.8663,'400101'],
    ['Poisar',19.2010,72.8448,'400067'],['Charkop',19.1985,72.8301,'400067'],
    ['Aksa Beach Road',19.2094,72.7985,'400061'],['Borivali West',19.2363,72.8542,'400091'],
    ['Borivali East',19.2304,72.8676,'400066'],['Dahisar West',19.2527,72.8504,'400068'],
    ['Dahisar East',19.2613,72.8663,'400068'],['Mira Road',19.2874,72.8700,'401107'],
    ['Bhayandar West',19.2994,72.8480,'401101'],['Bhayandar East',19.3105,72.8671,'401105'],
    ['Naigaon',19.3636,72.8579,'401208'],['Thane West',19.2004,72.9772,'400601'],
    ['Thane East',19.2069,72.9904,'400603'],['Kopri Colony',19.2050,73.0061,'400603'],
    ['Wagle Estate',19.1900,72.9750,'400604'],['Majiwada',19.2190,72.9841,'400601'],
    ['Ghodbunder Road',19.2594,72.9677,'400615'],['Manpada',19.2343,73.0012,'400612'],
    ['Hiranandani Estate',19.2578,72.9873,'400607'],['Brahmand',19.2483,73.0147,'400612'],
    ['Owale',19.2617,72.9835,'400607'],['Balkum',19.1906,72.9701,'400608'],
    ['Kalwa',19.1787,72.9946,'400605'],['Vashi',19.0695,72.9988,'400703'],
    ['Turbhe',19.0912,73.0161,'400705'],['Sanpada',19.0556,73.0079,'400705'],
    ['Nerul',19.0378,73.0096,'400706'],['Seawoods',19.0140,73.0153,'400706'],
    ['CBD Belapur',19.0186,73.0393,'400614'],['Kharghar',19.0478,73.0680,'410210'],
    ['Ulwe',18.9838,73.0503,'410206'],['Panvel',18.9894,73.1101,'410206'],
    ['Kamothe',19.0164,73.0893,'410209'],['Airoli',19.1534,72.9976,'400708'],
    ['Ghansoli',19.1344,72.9914,'400701'],['Kopar Khairane',19.1039,72.9925,'400709'],
    ['Rabale',19.1478,73.0007,'400701'],['Mahape',19.1246,73.0161,'400710'],
    ['Dombivali East',19.2167,73.0847,'421201'],['Dombivali West',19.2113,73.0716,'421202'],
    ['Kalyan West',19.2425,73.1313,'421301'],['Kalyan East',19.2397,73.1497,'421306'],
    ['Ulhasnagar',19.2215,73.1530,'421003'],['Nalasopara West',19.4142,72.7986,'401203'],
    ['Nalasopara East',19.4200,72.8140,'401209'],['Vasai West',19.3716,72.8116,'401202'],
    ['Virar West',19.4574,72.7898,'401303'],['Ambernath',19.2010,73.1897,'421501'],
    ['Badlapur',19.1555,73.2659,'421503'],['Taloja',19.1432,73.1263,'410208'],
];

// ── Shop type data ────────────────────────────────────────────────────────────
$typeRanges = [
    // [start, end, name_suffixes]
    [1,    4000,  ['Kirana Store','General Store','Supermart','Provisions Store','Bazaar','Family Store','Daily Needs','Fresh Mart','Grahak Seva Kendra','Local Market']],
    [4001, 5500,  ['Electronics','Digital Hub','Tech Store','Gadget Shop','Mobile Point','Electronic World','IT Shop','Computer Centre']],
    [5501, 7000,  ['Fashion Hub','Boutique','Garments','Clothing Store','Textile House','Readymade Store','Style Centre','Apparel']],
    [7001, 8000,  ['Home Store','Kitchen World','Home Decor','Furniture House','Interiors','Household Store','Home Centre']],
    [8001, 9000,  ['Medical Store','Pharmacy','Health Store','Chemist','Drug House','Medical Hall','Health Point']],
    [9001, 9500,  ['Sports Corner','Fitness Store','Sports Hub','Athletic Store','Gym World','Sports Arena']],
    [9501, 10000, ['Multi Store','General Mart','Super Shop','Retail Hub','Trade Centre','All Under One Roof']],
];

$streets = ['MG Road','Station Road','Market Road','Main Street','Cross Lane','Shopping Lane',
            'Bazaar Street','Commercial Road','Market Street','High Street','Old Road',
            'New Road','Linking Road','SV Road','Western Express Hwy','Eastern Express Hwy',
            'Tulsi Pipe Road','LBS Road','Pokhran Road','Ghodbunder Road'];
$buildings = ['Star Complex','Plaza','Centre','Building','Chambers','Tower','Mall',
              'Complex','Arcade','Market','Heights','Residency'];

function getTypeForId(int $id, array $typeRanges): array {
    foreach ($typeRanges as $tr) {
        if ($id >= $tr[0] && $id <= $tr[1]) return $tr[2];
    }
    return ['Store'];
}

// ── 4. Insert 10 000 shops ────────────────────────────────────────────────────
$BATCH = 500;
$shopValues = [];
$areaCount = count($areas);
echo "Inserting shops...\n";

for ($i = 1; $i <= 10000; $i++) {
    $area = $areas[($i - 1) % $areaCount];
    [$areaName, $latBase, $lngBase, $pincode] = $area;

    $types = getTypeForId($i, $typeRanges);
    $typeName = $types[($i - 1) % count($types)];
    $seq = (int)ceil($i / $areaCount);
    if ($seq === 0) $seq = 1;
    $name = addslashes("$areaName $typeName");
    if ($i % count($types) !== 1) $name = addslashes("$areaName $typeName " . (($i % 99) + 1));
    $code = 'MBZ-' . str_pad((string)$i, 5, '0', STR_PAD_LEFT);

    // Small random offset so shops aren't all at exactly the same point
    $lat = round($latBase + (mt_rand(-200, 200) / 100000), 7);
    $lng = round($lngBase + (mt_rand(-200, 200) / 100000), 7);

    $streetNum = mt_rand(1, 999);
    $street = $streets[$i % count($streets)];
    $building = $buildings[$i % count($buildings)];
    $addrJson = addslashes(json_encode([
        'line1'    => "Shop $streetNum, $building",
        'line2'    => $street,
        'area'     => $areaName,
        'city'     => 'Mumbai',
        'state'    => 'Maharashtra',
        'landmark' => 'Near ' . $areaName . ' Metro Station',
    ]));

    $status = $i % 20 === 0 ? 'closed_temp' : 'active';
    $shopValues[] = "(UUID(),$vendorId,'$name','$code',NULL,'unverified','$addrJson','$pincode','27',$lat,$lng,'active','server')";

    if (count($shopValues) >= $BATCH) {
        $pdo->exec("INSERT INTO shops (uuid,vendor_id,name,code,gstin,gstin_status,address_json,pincode,state_code,latitude,longitude,status,origin) VALUES " . implode(',', $shopValues));
        $shopValues = [];
        echo "  Inserted shops up to $i\n";
    }
}
if ($shopValues) {
    $pdo->exec("INSERT INTO shops (uuid,vendor_id,name,code,gstin,gstin_status,address_json,pincode,state_code,latitude,longitude,status,origin) VALUES " . implode(',', $shopValues));
}
echo "Shops done.\n";

// ── 5. Create 500 staff users ─────────────────────────────────────────────────
echo "Creating staff users...\n";
$staffValues = [];
for ($i = 1; $i <= 500; $i++) {
    $name  = addslashes("Staff Member $i");
    $email = "staff$i\@mumbaibazaar.demo";
    $phone = '+91' . (9800000000 + $i);
    $staffValues[] = "(UUID(),'vendor','$name','$email','$phone','active','server')";
    if (count($staffValues) >= 100) {
        $pdo->exec("INSERT INTO users (uuid,principal_type,name,email,phone,status,origin) VALUES " . implode(',', $staffValues));
        $staffValues = [];
    }
}
if ($staffValues) $pdo->exec("INSERT INTO users (uuid,principal_type,name,email,phone,status,origin) VALUES " . implode(',', $staffValues));

// ── 6. Link all staff to vendor ───────────────────────────────────────────────
$staffTypes = ['branch_manager','cashier','cashier','packer','helper'];
$staffIds = $pdo->query("SELECT id FROM users WHERE email LIKE 'staff%@mumbaibazaar.demo' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$vsValues = [];
foreach ($staffIds as $idx => $uid) {
    $stype = $staffTypes[$idx % count($staffTypes)];
    $ecode = 'EMP' . str_pad((string)($idx + 1), 4, '0', STR_PAD_LEFT);
    $vsValues[] = "(UUID(),$uid,$vendorId,'$stype','$ecode','active','server')";
    if (count($vsValues) >= 100) {
        $pdo->exec("INSERT INTO vendor_staff (uuid,user_id,vendor_id,staff_type,employee_code,status,origin) VALUES " . implode(',', $vsValues));
        $vsValues = [];
    }
}
if ($vsValues) $pdo->exec("INSERT INTO vendor_staff (uuid,user_id,vendor_id,staff_type,employee_code,status,origin) VALUES " . implode(',', $vsValues));

echo "Staff done. Total staff: " . count($staffIds) . "\n";
echo "vendor_id=$vendorId owner_user_id=$ownerUserId\n";
echo "Shops: " . (int)$pdo->query('SELECT COUNT(*) FROM shops')->fetchColumn() . "\n";
