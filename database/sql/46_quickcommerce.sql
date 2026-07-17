-- 46_quickcommerce.sql  |  Quick-commerce delivery location on the order
-- Adds:
--   orders.delivery_lat / orders.delivery_lng — the map-picked location the order
--       is delivered to. Captured at checkout; used to record/verify per-item
--       serviceability (each shop's delivery_radius_km). Today only the billing
--       address text is stored; these make the delivery point explicit.
-- Idempotent (PREPARE-guarded, like 45_vertical_lifecycle.sql). Additive only.
--
-- Apply: php database/apply_sql.php database/sql/46_quickcommerce.sql

-- orders.delivery_lat
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'delivery_lat');
SET @ddl := IF(@col = 0,
    "ALTER TABLE `orders` ADD COLUMN `delivery_lat` DECIMAL(10,7) NULL DEFAULT NULL AFTER `billing_address_json`",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- orders.delivery_lng
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'delivery_lng');
SET @ddl := IF(@col = 0,
    "ALTER TABLE `orders` ADD COLUMN `delivery_lng` DECIMAL(10,7) NULL DEFAULT NULL AFTER `delivery_lat`",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'quickcommerce migration applied' AS result;
