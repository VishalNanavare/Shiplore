-- =====================================================================
-- 25_pos_billing_modes.sql  |  POS billing modes (T5)
-- Temp-product flag on POS lines, and a customer credit (udhaar) ledger with
-- repayments. Split payment + shipping reuse existing tables (pos_sale_payments,
-- sub_orders).
--
-- Idempotent. Additive only.
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS `_add_col`;
DELIMITER $$
CREATE PROCEDURE `_add_col`(IN p_tbl VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tbl AND COLUMN_NAME = p_col) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_tbl, '` ADD COLUMN `', p_col, '` ', p_ddl);
    PREPARE _st FROM @sql; EXECUTE _st; DEALLOCATE PREPARE _st;
  END IF;
END$$
DELIMITER ;

CALL `_add_col`('pos_sale_items','is_temporary', "TINYINT(1) NOT NULL DEFAULT 0");
DROP PROCEDURE IF EXISTS `_add_col`;

-- Temp products are real sale lines with no catalogue variant.
ALTER TABLE `pos_sale_items` MODIFY `variant_id` BIGINT UNSIGNED NULL DEFAULT NULL;

-- Customer credit (udhaar) — one row per credit sale, reduced by repayments.
CREATE TABLE IF NOT EXISTS `customer_credits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `shop_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `pos_sale_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `balance` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `due_date` DATE NULL DEFAULT NULL,
  `status` ENUM('open','partial','cleared') NOT NULL DEFAULT 'open',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_credits_uuid` (`uuid`),
  KEY `idx_cc_vendor_status` (`vendor_id`,`status`),
  KEY `idx_cc_customer` (`customer_id`),
  CONSTRAINT `fk_cc_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cc_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `credit_repayments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `customer_credit_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `method` ENUM('cash','card','upi','wallet') NOT NULL DEFAULT 'cash',
  `reference` VARCHAR(120) NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_credit_repay_uuid` (`uuid`),
  KEY `idx_cr_credit` (`customer_credit_id`),
  CONSTRAINT `fk_cr_credit` FOREIGN KEY (`customer_credit_id`) REFERENCES `customer_credits` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
