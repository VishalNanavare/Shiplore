<?php
/**
 * Creates missing database indexes for the 900-vendor / 10K-shop dataset.
 * Each index is guarded by SHOW INDEX to be re-runnable without error.
 */
declare(strict_types=1);

require __DIR__ . '/_db.php';

$pdo = shiplore_pdo();

$indexes = [
    // pos_sales: vendor_id unindexed → full 200K-row scan for every dashboard metric
    ['pos_sales',       'idx_pos_sales_vendor',         'vendor_id'],
    ['pos_sales',       'idx_pos_sales_vendor_created', 'vendor_id, created_at'],

    // sub_orders: vendor_id unindexed → dashboard order counts full-scan
    ['sub_orders',      'idx_sub_orders_vendor',         'vendor_id, deleted_at'],
    ['sub_orders',      'idx_sub_orders_vendor_status',  'vendor_id, status, deleted_at'],
    ['sub_orders',      'idx_sub_orders_vendor_created', 'vendor_id, created_at, deleted_at'],

    // products: composite for vendor+category+status browse
    ['products',        'idx_products_vcs',              'vendor_id, category_id, status'],

    // product_variants: vendor-scoped queries used by inventory screen
    ['product_variants','idx_variants_vendor',           'vendor_id, status, product_id'],

    // shops: vendor+status for dashboard "open shops" count
    ['shops',           'idx_shops_vendor_status',       'vendor_id, status, deleted_at'],

    // inventory: variant lookups in EXISTS subqueries (catalog filter)
    ['inventory',       'idx_inventory_variant',         'variant_id, shop_id, on_hand'],

    // notifications: user+read_at for pending-ring restore
    ['notifications',   'idx_notif_user_read',           'user_id, read_at, event_code'],
];

$start = microtime(true);

foreach ($indexes as [$table, $keyName, $columns]) {
    // Check if index already exists
    $existing = $pdo->query(
        "SHOW INDEX FROM `{$table}` WHERE Key_name = '{$keyName}'"
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($existing) {
        echo "SKIP  {$table}.{$keyName} (already exists)\n";
        continue;
    }

    echo "CREATE {$table}.{$keyName} ({$columns})... ";
    $pdo->exec("CREATE INDEX `{$keyName}` ON `{$table}` ({$columns})");
    echo "done\n";
}

echo "\nAll indexes processed in " . round(microtime(true) - $start) . "s\n";

echo "\n=== VERIFICATION ===\n";
foreach ($indexes as [$table, $keyName, $columns]) {
    $exists = (bool) $pdo->query(
        "SHOW INDEX FROM `{$table}` WHERE Key_name = '{$keyName}'"
    )->fetchColumn();
    echo ($exists ? '[OK]' : '[MISSING]') . " {$table}.{$keyName}\n";
}
