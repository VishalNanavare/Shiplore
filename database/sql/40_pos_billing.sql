-- =====================================================================
-- 40_pos_billing.sql  |  POS billing — order type + MRP snapshot + the
-- POS credit-note document.
--
-- A. pos_sales.order_type  — takeaway (default; handed over at the counter)
--    vs delivery (name/mobile/address captured).
-- B. pos_sale_items.mrp_snapshot — the MRP at sale time, so the 80 mm
--    receipt can print MRP + the customer's savings.
-- C. pos_returns gains the credit-note shape: a number, a nullable sale link
--    (so a walk-in refund can be a standalone credit note), and the
--    walk-in customer + tax fields needed to print a GST credit note.
--
-- Idempotent, DELIMITER-free. Apply: php database/apply_sql.php database/sql/40_pos_billing.sql
-- @see docs/architecture/48-POS-BILLING.md
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

-- A. order type ---------------------------------------------------------
SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `pos_sales` ADD COLUMN `order_type` ENUM(''takeaway'',''delivery'') NOT NULL DEFAULT ''takeaway'' AFTER `customer_id`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_sales' AND COLUMN_NAME = 'order_type');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

-- B. MRP snapshot -------------------------------------------------------
SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `pos_sale_items` ADD COLUMN `mrp_snapshot` DECIMAL(15,4) NULL DEFAULT NULL AFTER `unit_price`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_sale_items' AND COLUMN_NAME = 'mrp_snapshot');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

-- C. POS credit-note shape on pos_returns ------------------------------
SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `pos_returns` ADD COLUMN `credit_note_no` VARCHAR(40) NULL DEFAULT NULL AFTER `uuid`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_returns' AND COLUMN_NAME = 'credit_note_no');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `pos_returns` ADD COLUMN `customer_name` VARCHAR(120) NULL DEFAULT NULL AFTER `credit_note_no`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_returns' AND COLUMN_NAME = 'customer_name');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `pos_returns` ADD COLUMN `customer_phone` VARCHAR(20) NULL DEFAULT NULL AFTER `customer_name`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_returns' AND COLUMN_NAME = 'customer_phone');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `pos_returns` ADD COLUMN `taxable_value` DECIMAL(15,4) NOT NULL DEFAULT 0.0000 AFTER `refund_amount`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_returns' AND COLUMN_NAME = 'taxable_value');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `pos_returns` ADD COLUMN `cgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000 AFTER `taxable_value`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_returns' AND COLUMN_NAME = 'cgst');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `pos_returns` ADD COLUMN `sgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000 AFTER `cgst`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_returns' AND COLUMN_NAME = 'sgst');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `pos_returns` ADD COLUMN `igst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000 AFTER `sgst`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_returns' AND COLUMN_NAME = 'igst');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

-- make the sale link optional so a walk-in credit note can stand alone
SET @ddl = (SELECT IF(DATA_TYPE IS NOT NULL AND IS_NULLABLE = 'NO',
  'ALTER TABLE `pos_returns` MODIFY `pos_sale_id` BIGINT UNSIGNED NULL DEFAULT NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_returns' AND COLUMN_NAME = 'pos_sale_id');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET FOREIGN_KEY_CHECKS = 1;
