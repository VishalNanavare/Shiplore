-- 48_address_contact.sql  |  Per-address recipient name + phone (Blinkit-style)
-- Adds to customer_addresses:
--   recipient_name — "Your name" captured on the address form (who receives it).
--   phone          — "Your phone number" for the delivery.
-- (label, line1, line2, city, state_code, pincode, formatted_address, latitude,
--  longitude already exist.) Idempotent (PREPARE-guarded). Additive only.
--
-- Apply: php database/apply_sql.php database/sql/48_address_contact.sql

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_addresses' AND COLUMN_NAME = 'recipient_name');
SET @ddl := IF(@col = 0,
    "ALTER TABLE `customer_addresses` ADD COLUMN `recipient_name` VARCHAR(191) NULL DEFAULT NULL AFTER `label`",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_addresses' AND COLUMN_NAME = 'phone');
SET @ddl := IF(@col = 0,
    "ALTER TABLE `customer_addresses` ADD COLUMN `phone` VARCHAR(20) NULL DEFAULT NULL AFTER `recipient_name`",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'address-contact migration applied' AS result;
