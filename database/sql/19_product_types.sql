-- =====================================================================
-- 19_product_types.sql  |  Product Module P6 — specialized product types
-- Satellite tables for bundle/combo, digital/downloadable, subscription and
-- gift-card products. The product_type column (16_product_module) selects
-- which satellite applies.
--
-- Idempotent. Additive only. Run AFTER 01_master.sql + 16/17/18.
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- A. product_bundle_items — components of a bundle/combo (section 5, 27).
--    price_contribution NULL => use the component variant's own price.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_bundle_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `bundle_product_id` BIGINT UNSIGNED NOT NULL,
  `item_variant_id` BIGINT UNSIGNED NOT NULL,
  `qty` DECIMAL(12,3) NOT NULL DEFAULT 1.000,
  `is_optional` TINYINT(1) NOT NULL DEFAULT 0,
  `price_contribution` DECIMAL(15,4) NULL DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pbi_uuid` (`uuid`),
  KEY `idx_pbi_bundle` (`bundle_product_id`),
  KEY `idx_pbi_item` (`item_variant_id`),
  CONSTRAINT `fk_pbi_bundle` FOREIGN KEY (`bundle_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pbi_item` FOREIGN KEY (`item_variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- B. product_downloads — digital/downloadable files (section 28).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_downloads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `media_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `label` VARCHAR(191) NOT NULL,
  `download_limit` INT UNSIGNED NULL DEFAULT NULL,
  `expiry_days` INT UNSIGNED NULL DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pd_uuid` (`uuid`),
  KEY `idx_pd_product` (`product_id`),
  CONSTRAINT `fk_pd_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pd_media` FOREIGN KEY (`media_id`) REFERENCES `media_assets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- C. product_license_keys — license-key pool for digital products (section 28).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_license_keys` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `license_key` VARCHAR(191) NOT NULL,
  `assigned_order_item_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('available','assigned','revoked') NOT NULL DEFAULT 'available',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plk_uuid` (`uuid`),
  KEY `idx_plk_product_status` (`product_id`,`status`),
  CONSTRAINT `fk_plk_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- D. product_subscription_plans — recurring plans (section 29).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_subscription_plans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `interval_unit` ENUM('daily','weekly','monthly','yearly') NOT NULL DEFAULT 'monthly',
  `price` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `billing_cycles` INT UNSIGNED NULL DEFAULT NULL,
  `trial_days` INT UNSIGNED NOT NULL DEFAULT 0,
  `auto_renew` TINYINT(1) NOT NULL DEFAULT 1,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_psp_uuid` (`uuid`),
  KEY `idx_psp_product` (`product_id`),
  CONSTRAINT `fk_psp_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- E. gift_card_products — gift-card config (1:1 with the product).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gift_card_products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `denominations_json` JSON NULL,
  `min_amount` DECIMAL(15,4) NULL DEFAULT NULL,
  `max_amount` DECIMAL(15,4) NULL DEFAULT NULL,
  `validity_days` INT UNSIGNED NULL DEFAULT NULL,
  `is_reloadable` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gcp_product` (`product_id`),
  CONSTRAINT `fk_gcp_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
