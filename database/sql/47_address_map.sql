-- 47_address_map.sql  |  Map/formatted address on saved + order addresses
-- Adds:
--   customer_addresses.formatted_address — the full human-readable address from
--       the map reverse-geocode (Blinkit/Zomato style "deliver to" line). The
--       lat/lng columns already exist on this table.
--   order_addresses.formatted_address    — same, snapshotted onto the order so the
--       picker/rider sees the exact delivery address. (phone/latitude/longitude
--       columns already exist on order_addresses; place() now populates them.)
-- Idempotent (PREPARE-guarded, like 46_quickcommerce.sql). Additive only.
--
-- Apply: php database/apply_sql.php database/sql/47_address_map.sql

-- customer_addresses.formatted_address
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_addresses' AND COLUMN_NAME = 'formatted_address');
SET @ddl := IF(@col = 0,
    "ALTER TABLE `customer_addresses` ADD COLUMN `formatted_address` VARCHAR(500) NULL DEFAULT NULL AFTER `pincode`",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- order_addresses.formatted_address
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_addresses' AND COLUMN_NAME = 'formatted_address');
SET @ddl := IF(@col = 0,
    "ALTER TABLE `order_addresses` ADD COLUMN `formatted_address` VARCHAR(500) NULL DEFAULT NULL AFTER `pincode`",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'address-map migration applied' AS result;
