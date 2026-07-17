-- 45_vertical_lifecycle.sql  |  Vertical-aware order lifecycle (goods + food)
-- Adds:
--   orders.vertical  — which storefront vertical the order belongs to (goods|food),
--                      stamped at placement; drives cancel/return rules + UI.
--   refunds.kind     — distinguishes a customer cancellation refund from a return
--                      refund, both flowing through RefundService.
-- Idempotent (PREPARE-guarded, like 41_media_library.sql). Additive only.
--
-- Apply: php database/apply_sql.php database/sql/45_vertical_lifecycle.sql

-- orders.vertical
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'vertical');
SET @ddl := IF(@col = 0,
    "ALTER TABLE `orders` ADD COLUMN `vertical` VARCHAR(20) NULL DEFAULT NULL AFTER `channel`",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_vertical');
SET @ddl := IF(@idx = 0,
    'ALTER TABLE `orders` ADD INDEX `idx_orders_vertical` (`vertical`)',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- refunds.kind
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refunds' AND COLUMN_NAME = 'kind');
SET @ddl := IF(@col = 0,
    "ALTER TABLE `refunds` ADD COLUMN `kind` VARCHAR(20) NULL DEFAULT NULL AFTER `return_id`",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- returns.customer_note (the `returns` table has only reason_code_id, no free text)
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'returns' AND COLUMN_NAME = 'customer_note');
SET @ddl := IF(@col = 0,
    "ALTER TABLE `returns` ADD COLUMN `customer_note` VARCHAR(500) NULL DEFAULT NULL AFTER `reason_code_id`",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'vertical-lifecycle migration applied' AS result;
