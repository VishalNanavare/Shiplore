-- =====================================================================
-- 16_product_module.sql  |  Enterprise Product Management Module — P1
-- Core product header columns + content / media-meta / SEO / tax / FAQ /
-- tags / labels / custom-spec / relations tables. Wires the rich product
-- model the Admin + Vendor panels need (Amazon/Flipkart/Shopify-class).
--
-- Idempotent. Run AFTER 01_master.sql (products/variants/seo exist) and
-- 11_seed.sql. Additive only — never drops/renames existing columns.
-- InnoDB / utf8mb4, soft-delete + audit + sync fields, FKs — same
-- conventions as the rest of the schema.
--
-- NOTE: MySQL 9 has no "ADD COLUMN IF NOT EXISTS", so a guarded helper
-- procedure `_add_col` makes the ALTERs safely re-runnable. It is dropped
-- at the end of this file.
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Idempotent column-add helper
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `_add_col`;
DELIMITER $$
CREATE PROCEDURE `_add_col`(IN p_tbl VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tbl AND COLUMN_NAME = p_col
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_tbl, '` ADD COLUMN `', p_col, '` ', p_ddl);
    PREPARE _st FROM @sql; EXECUTE _st; DEALLOCATE PREPARE _st;
  END IF;
END$$
DELIMITER ;

-- ---------------------------------------------------------------------
-- A. products — header columns (sections 1, 2, 11, 12, 13, 17, 18, 19)
--    NOTE: `condition` is reserved → `product_condition`.
-- ---------------------------------------------------------------------
CALL `_add_col`('products','print_short_name',      "VARCHAR(20) NULL DEFAULT NULL");
CALL `_add_col`('products','product_type',          "ENUM('simple','variant','bundle','combo','digital','service','subscription','giftcard','downloadable','rental') NOT NULL DEFAULT 'simple'");
CALL `_add_col`('products','product_condition',     "ENUM('new','refurbished','used') NOT NULL DEFAULT 'new'");
CALL `_add_col`('products','visibility',            "ENUM('public','logged_in','group','vendor','hidden') NOT NULL DEFAULT 'public'");
CALL `_add_col`('products','manufacturer',          "VARCHAR(191) NULL DEFAULT NULL");
CALL `_add_col`('products','country_of_origin',     "VARCHAR(80) NULL DEFAULT NULL");
CALL `_add_col`('products','made_in',               "VARCHAR(80) NULL DEFAULT NULL");
CALL `_add_col`('products','vendor_product_code',   "VARCHAR(64) NULL DEFAULT NULL");
CALL `_add_col`('products','internal_product_code', "VARCHAR(64) NULL DEFAULT NULL");
CALL `_add_col`('products','gst_type',              "ENUM('inclusive','exclusive') NOT NULL DEFAULT 'inclusive'");
CALL `_add_col`('products','inventory_mode',        "ENUM('managed','unlimited') NOT NULL DEFAULT 'managed'");
-- purchase limits (section 11)
CALL `_add_col`('products','min_purchase_qty',      "DECIMAL(12,3) NULL DEFAULT NULL");
CALL `_add_col`('products','max_purchase_qty',      "DECIMAL(12,3) NULL DEFAULT NULL");
CALL `_add_col`('products','qty_step',              "DECIMAL(12,3) NOT NULL DEFAULT 1.000");
CALL `_add_col`('products','daily_purchase_limit',  "INT UNSIGNED NULL DEFAULT NULL");
CALL `_add_col`('products','monthly_purchase_limit',"INT UNSIGNED NULL DEFAULT NULL");
-- order & payment rules (section 12)
CALL `_add_col`('products','payment_restriction',   "ENUM('online','cod','both') NOT NULL DEFAULT 'both'");
CALL `_add_col`('products','refund_allowed',        "TINYINT(1) NOT NULL DEFAULT 1");
CALL `_add_col`('products','refund_window_days',    "INT UNSIGNED NULL DEFAULT NULL");
CALL `_add_col`('products','return_window_days',    "INT UNSIGNED NULL DEFAULT NULL");
CALL `_add_col`('products','replacement_window_days',"INT UNSIGNED NULL DEFAULT NULL");
-- availability (section 19)
CALL `_add_col`('products','available_from',        "DATE NULL DEFAULT NULL");
CALL `_add_col`('products','available_to',          "DATE NULL DEFAULT NULL");
CALL `_add_col`('products','preorder_enabled',      "TINYINT(1) NOT NULL DEFAULT 0");
CALL `_add_col`('products','backorder_enabled',     "TINYINT(1) NOT NULL DEFAULT 0");
CALL `_add_col`('products','out_of_stock_handling', "ENUM('hide','disable','backorder') NOT NULL DEFAULT 'disable'");
-- search & channels (sections 17, 18)
CALL `_add_col`('products','search_boost',          "ENUM('normal','high','featured') NOT NULL DEFAULT 'normal'");
CALL `_add_col`('products','is_app_enabled',        "TINYINT(1) NOT NULL DEFAULT 1");
CALL `_add_col`('products','is_marketplace_enabled',"TINYINT(1) NOT NULL DEFAULT 0");
-- shipping (section 13)
CALL `_add_col`('products','shipping_type',         "ENUM('free','paid','vendor','pickup') NOT NULL DEFAULT 'paid'");
CALL `_add_col`('products','ships_local',           "TINYINT(1) NOT NULL DEFAULT 1");
CALL `_add_col`('products','ships_national',        "TINYINT(1) NOT NULL DEFAULT 0");
CALL `_add_col`('products','ships_international',    "TINYINT(1) NOT NULL DEFAULT 0");

-- ---------------------------------------------------------------------
-- B. product_seo — structured-data columns (section 16)
-- ---------------------------------------------------------------------
CALL `_add_col`('product_seo','og_json',     "JSON NULL");
CALL `_add_col`('product_seo','twitter_json',"JSON NULL");
CALL `_add_col`('product_seo','schema_json', "JSON NULL");

-- ---------------------------------------------------------------------
-- B2. audit_logs — before/after snapshots (section 24, DECIDED: reuse audit_logs)
-- ---------------------------------------------------------------------
CALL `_add_col`('audit_logs','before_json',"JSON NULL");
CALL `_add_col`('audit_logs','after_json', "JSON NULL");

-- ---------------------------------------------------------------------
-- C. product_content (1:1) — long-form content (section 3)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_content` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `short_description` VARCHAR(500) NULL DEFAULT NULL,
  `full_description` LONGTEXT NULL,
  `additional_info` LONGTEXT NULL,
  `ingredients` LONGTEXT NULL,
  `usage_instructions` LONGTEXT NULL,
  `safety_instructions` LONGTEXT NULL,
  `storage_instructions` LONGTEXT NULL,
  `highlights_json` JSON NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_content_product` (`product_id`),
  CONSTRAINT `fk_product_content_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- D. product_tags + map (section 1.4 — Tagify)
--    vendor_id NULL = global/admin tag; else vendor-owned reusable tag.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_tags` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `vendor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `name` VARCHAR(80) NOT NULL,
  `slug` VARCHAR(96) NOT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_tags_uuid` (`uuid`),
  UNIQUE KEY `uq_product_tags_slug` (`slug`),
  KEY `idx_product_tags_vendor` (`vendor_id`),
  CONSTRAINT `fk_product_tags_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_tag_map` (
  `product_id` BIGINT UNSIGNED NOT NULL,
  `tag_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`product_id`,`tag_id`),
  KEY `idx_ptm_tag` (`tag_id`),
  CONSTRAINT `fk_ptm_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ptm_tag` FOREIGN KEY (`tag_id`) REFERENCES `product_tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- E. product_labels + map (section 15 — admin-controlled badges)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_labels` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `color` VARCHAR(20) NOT NULL DEFAULT 'primary',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_labels_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_label_map` (
  `product_id` BIGINT UNSIGNED NOT NULL,
  `label_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`product_id`,`label_id`),
  KEY `idx_plm_label` (`label_id`),
  CONSTRAINT `fk_plm_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_plm_label` FOREIGN KEY (`label_id`) REFERENCES `product_labels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- F. product_videos (section 4 — YouTube/Vimeo/direct embeds)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_videos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `provider` ENUM('youtube','vimeo','direct') NOT NULL DEFAULT 'youtube',
  `url` VARCHAR(500) NOT NULL,
  `title` VARCHAR(191) NULL DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_videos_uuid` (`uuid`),
  KEY `idx_product_videos_product` (`product_id`),
  CONSTRAINT `fk_product_videos_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- G. product_documents (sections 4, 22 — manuals, warranty, certificates)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `media_id` BIGINT UNSIGNED NOT NULL,
  `doc_type` ENUM('manual','brochure','catalog','certificate','warranty','spec_sheet') NOT NULL DEFAULT 'manual',
  `title` VARCHAR(191) NULL DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_documents_uuid` (`uuid`),
  KEY `idx_product_documents_product` (`product_id`),
  KEY `idx_product_documents_media` (`media_id`),
  CONSTRAINT `fk_product_documents_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_product_documents_media` FOREIGN KEY (`media_id`) REFERENCES `media_assets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- H. product_faqs (section 20 — product-specific Q&A)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_faqs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `question` VARCHAR(500) NOT NULL,
  `answer` LONGTEXT NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_faqs_uuid` (`uuid`),
  KEY `idx_product_faqs_product` (`product_id`),
  CONSTRAINT `fk_product_faqs_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- I. product_attribute_values (section 21 — non-variant custom specs/EAV)
--    Reuses `attributes`/`attribute_values`; value_id for dropdowns,
--    value_text for free text/number.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_attribute_values` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `attribute_id` BIGINT UNSIGNED NOT NULL,
  `attribute_value_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `value_text` VARCHAR(500) NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pav` (`product_id`,`attribute_id`),
  KEY `idx_pav_attribute` (`attribute_id`),
  KEY `idx_pav_value` (`attribute_value_id`),
  CONSTRAINT `fk_pav_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pav_attribute` FOREIGN KEY (`attribute_id`) REFERENCES `attributes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pav_value` FOREIGN KEY (`attribute_value_id`) REFERENCES `attribute_values` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- J. product_relations (section 14 — related/upsell/cross-sell/FBT/etc.)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_relations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `related_product_id` BIGINT UNSIGNED NOT NULL,
  `relation_type` ENUM('related','upsell','cross_sell','fbt','alternative','similar','accessory') NOT NULL DEFAULT 'related',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_relations` (`product_id`,`related_product_id`,`relation_type`),
  KEY `idx_pr_related` (`related_product_id`),
  CONSTRAINT `fk_pr_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pr_related` FOREIGN KEY (`related_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- K. Seed the standard admin-controlled labels (section 15). Idempotent.
-- ---------------------------------------------------------------------
INSERT INTO `product_labels` (`code`,`name`,`color`,`sort_order`) VALUES
  ('new_arrival','New Arrival','info',1),
  ('featured','Featured','primary',2),
  ('trending','Trending','warning',3),
  ('hot_deal','Hot Deal','danger',4),
  ('bestseller','Bestseller','success',5),
  ('recommended','Recommended','secondary',6),
  ('limited_stock','Limited Stock','dark',7)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ---------------------------------------------------------------------
-- Clean up the helper procedure.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `_add_col`;

SET FOREIGN_KEY_CHECKS = 1;
