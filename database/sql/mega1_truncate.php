<?php
/* Truncate all sales/order/product/shop/vendor data and reset auto_increment */
$pdo = new PDO('mysql:host=127.0.0.1;dbname=test;charset=utf8mb4','root','root@123',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$tables = [
    'pos_sale_items','pos_sale_taxes','pos_sale_payments','pos_sales',
    'pos_shifts','pos_terminals',
    'order_items','sub_orders','orders',
    'inventory','product_media','product_shops','product_variants','products',
    'shops','vendor_staff','vendors',
    'notifications',
];
foreach ($tables as $t) {
    $pdo->exec("TRUNCATE TABLE `$t`");
    echo "Truncated $t\n";
}
// Remove seeded demo users (principal_type vendor/customer created by seed scripts)
$pdo->exec("DELETE FROM users WHERE principal_type IN ('vendor') AND id > 100");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
echo "Done. All tables clean.\n";
