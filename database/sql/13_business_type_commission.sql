-- =====================================================================
-- File 13: BUSINESS TYPE GOVERNANCE + COMMISSION HIERARCHY (additive)
-- Adds: business_types master, business_type<->category allow-map,
-- vendor selected-category map, vendor.business_type_id, commission
-- levels (business_type + product), and GST match fields. Forward
-- migration; clean rebuild via run_all drops first. Non-breaking.
-- =====================================================================
USE `test`;
SET FOREIGN_KEY_CHECKS = 0;

-- 1) Business types (admin-controlled). e.g. Footwear, Grocery, Electronics.
CREATE TABLE IF NOT EXISTS `business_types` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `slug` VARCHAR(191) NOT NULL,
  `description` VARCHAR(255) NULL DEFAULT NULL,
  `default_commission_rate` DECIMAL(5,2) NULL DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_business_types_uuid` (`uuid`),
  UNIQUE KEY `uq_business_types_code` (`code`),
  UNIQUE KEY `uq_business_types_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Which categories a business type is ALLOWED to use (admin-governed).
CREATE TABLE IF NOT EXISTS `business_type_category_map` (
  `business_type_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`business_type_id`,`category_id`),
  KEY `idx_btcm_category` (`category_id`),
  CONSTRAINT `fk_btcm_btype` FOREIGN KEY (`business_type_id`) REFERENCES `business_types` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_btcm_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Categories a vendor has selected (must be a subset of their business type's allowed set; app-enforced).
CREATE TABLE IF NOT EXISTS `vendor_category_map` (
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED NOT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`vendor_id`,`category_id`),
  KEY `idx_vcm_category` (`category_id`),
  CONSTRAINT `fk_vcm_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_vcm_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Vendor -> business type.
ALTER TABLE `vendors`
  ADD COLUMN `business_type_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `state_code`,
  ADD KEY `idx_vendors_btype` (`business_type_id`),
  ADD CONSTRAINT `fk_vendors_btype` FOREIGN KEY (`business_type_id`) REFERENCES `business_types` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- 5) Commission hierarchy: add business-type-level and product-level rules.
--    Resolution priority (app): product_id > category_id(leaf->ancestors) > business_type_id > plan.default_rate (global).
ALTER TABLE `commission_rules`
  ADD COLUMN `business_type_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `commission_plan_id`,
  ADD COLUMN `product_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `category_id`,
  ADD COLUMN `include_gst` TINYINT(1) NOT NULL DEFAULT 0 AFTER `fixed_amount`,
  ADD KEY `idx_comm_rules_btype` (`business_type_id`),
  ADD KEY `idx_comm_rules_product` (`product_id`),
  ADD CONSTRAINT `fk_comm_rules_btype` FOREIGN KEY (`business_type_id`) REFERENCES `business_types` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_comm_rules_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- 6) GST verification match fields (compare returned vs registration; audit).
ALTER TABLE `gst_verifications`
  ADD COLUMN `trade_name` VARCHAR(191) NULL DEFAULT NULL AFTER `legal_name`,
  ADD COLUMN `address_returned` VARCHAR(255) NULL DEFAULT NULL AFTER `trade_name`,
  ADD COLUMN `match_result` ENUM('match','mismatch','partial') NULL DEFAULT NULL AFTER `status`;

-- 7) Seed a few business types (idempotent by code).
INSERT IGNORE INTO `business_types` (`uuid`,`code`,`name`,`slug`,`default_commission_rate`,`status`) VALUES
 ('00000000-0000-0000-0000-0000000000g1','footwear','Footwear','footwear',8.00,'active'),
 ('00000000-0000-0000-0000-0000000000g2','grocery','Grocery','grocery',5.00,'active'),
 ('00000000-0000-0000-0000-0000000000g3','electronics','Electronics','electronics',4.00,'active'),
 ('00000000-0000-0000-0000-0000000000g4','fashion','Fashion & Apparel','fashion',10.00,'active'),
 ('00000000-0000-0000-0000-0000000000g5','fnb','Food & Beverage','fnb',12.00,'active');

SET FOREIGN_KEY_CHECKS = 1;
SELECT CONCAT('Business-type + commission-hierarchy migration applied. tables=',
       (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='test')) AS result;
-- End of 13_business_type_commission.sql
