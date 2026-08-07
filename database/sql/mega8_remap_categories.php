<?php

require __DIR__ . '/_db.php';
/**
 * mega8_remap_categories.php
 *
 * Redistributes 1M products from their root parent categories into child
 * sub-categories using id % N round-robin so the Flutter Categories screen
 * shows non-empty sub-category tiles.
 *
 * Run once: php database/sql/mega8_remap_categories.php
 */

$pdo = shiplore_pdo();

$remaps = [
    // root_cat_id => [child cat ids for round-robin]
    1   => [2, 3],            // Footwear → Men's Shoes, Women's Shoes
    7   => [102, 106, 110, 114],  // Electronics → Mobiles&Tablets, Laptops, Audio, Cameras
    118 => [119, 124, 129, 134],  // Fashion → Men's, Women's, Footwear, Accessories
    139 => [140, 144, 148, 152],  // Grocery → Staples, Dairy, Beverages, Snacks
    156 => [157, 161, 165],       // Home → Kitchen Appliances, Cookware, Home Decor
    169 => [170, 175, 180],       // Health → Skincare, Haircare, Supplements
    184 => [185, 189],            // Sports → Gym Equipment, Sports Apparel
];

foreach ($remaps as $rootId => $children) {
    $n     = count($children);
    $count = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE category_id = $rootId")->fetchColumn();
    echo "Remapping $count products from cat $rootId into " . implode(',', $children) . " ($n buckets) … ";
    flush();

    // Build CASE WHEN (id % N) THEN child_id ... END
    $case  = 'CASE';
    foreach ($children as $i => $childId) {
        $mod    = $i % $n;
        $case  .= " WHEN (id % $n) = $mod THEN $childId";
    }
    $case .= " ELSE {$children[0]} END";

    $start = microtime(true);
    $affected = $pdo->exec("UPDATE products SET category_id = $case WHERE category_id = $rootId");
    $elapsed  = round(microtime(true) - $start, 1);
    echo "done ($affected rows, {$elapsed}s)\n";
    flush();
}

echo "\nVerification:\n";
$sql = 'SELECT c.id, c.name, c.parent_id, COUNT(p.id) AS cnt
        FROM categories c
        LEFT JOIN products p ON p.category_id = c.id
        WHERE c.status = \'active\' AND c.deleted_at IS NULL
        GROUP BY c.id HAVING cnt > 0
        ORDER BY c.level, c.parent_id, c.id';
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $indent = str_repeat('  ', (int) ($r['parent_id'] ? 1 : 0));
    echo $indent . "cat {$r['id']} ({$r['name']}): {$r['cnt']} products\n";
}

echo "\nDone. Run the categories API to verify sub-categories now have products.\n";
