<?php

require __DIR__ . '/_db.php';
/**
 * perf2_indexes.php
 *
 * Four covering/composite indexes targeting the customer storefront hot-path:
 *   - products(category_id, status, deleted_at)  → covering index for COUNT BY category
 *   - products(status, deleted_at, created_at)   → index-based ORDER BY newest
 *   - product_variants(product_id, is_default)   → direct default-variant JOIN lookup
 *   - inventory(shop_id, available)              → in-stock EXISTS range scan
 *
 * Run once: php database/sql/perf2_indexes.php
 */

$pdo = shiplore_pdo();

$indexes = [
    [
        'table' => 'products',
        'name'  => 'idx_products_cat_status_del',
        'ddl'   => 'CREATE INDEX idx_products_cat_status_del ON products(category_id, status, deleted_at)',
    ],
    [
        'table' => 'products',
        'name'  => 'idx_products_status_created',
        'ddl'   => 'CREATE INDEX idx_products_status_created ON products(status, deleted_at, created_at)',
    ],
    [
        'table' => 'product_variants',
        'name'  => 'idx_variants_product_default',
        'ddl'   => 'CREATE INDEX idx_variants_product_default ON product_variants(product_id, is_default)',
    ],
    [
        'table' => 'inventory',
        'name'  => 'idx_inventory_shop_avail',
        'ddl'   => 'CREATE INDEX idx_inventory_shop_avail ON inventory(shop_id, available)',
    ],
];

foreach ($indexes as $idx) {
    $exists = $pdo->query(
        "SHOW INDEX FROM {$idx['table']} WHERE Key_name = '{$idx['name']}'"
    )->fetch();

    if ($exists) {
        echo "[skip] {$idx['name']} already exists on {$idx['table']}\n";
        continue;
    }

    echo "Creating {$idx['name']} on {$idx['table']} … ";
    flush();
    $t = microtime(true);
    $pdo->exec($idx['ddl']);
    printf("done (%.1fs)\n", microtime(true) - $t);
    flush();
}

echo "\nAll done.\n";
