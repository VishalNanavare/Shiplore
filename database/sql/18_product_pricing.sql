-- =====================================================================
-- 18_product_pricing.sql  |  Product Module P4 — Pricing engine
-- Adds the price-list "kind" (base/offer/deal/flash) so time-bound special
-- prices reuse the existing price_lists machinery, and creates the
-- customer_groups master (Retail/Wholesale/Distributor) that price_lists and
-- customer_group_prices reference but which was never built.
--
-- Idempotent. Additive only. Run AFTER 01_master.sql + 16/17.
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

-- ---------------------------------------------------------------------
-- A. price_lists.kind — base list vs offer/deal/flash overlays (section 10).
-- ---------------------------------------------------------------------
CALL `_add_col`('price_lists','kind', "ENUM('base','offer','deal','flash') NOT NULL DEFAULT 'base'");

DROP PROCEDURE IF EXISTS `_add_col`;

-- ---------------------------------------------------------------------
-- B. customer_groups master (Retail / Wholesale / Distributor) — section 10.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customer_groups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_groups_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `customer_groups` (`code`,`name`) VALUES
  ('retail','Retail'), ('wholesale','Wholesale'), ('distributor','Distributor')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

SET FOREIGN_KEY_CHECKS = 1;
