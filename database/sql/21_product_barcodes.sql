-- =====================================================================
-- 21_product_barcodes.sql  |  Product Redesign RB1 — multiple barcodes
-- One product/variant may carry many barcodes (primary + secondaries, retail
-- pack vs master pack, different symbologies). Replaces the single
-- product_variants.barcode column for new entry; the old column is seeded in
-- as each variant's primary barcode and kept for backward compatibility.
--
-- variant_id NULL = a product-level barcode (simple products without variants).
-- barcode_value is globally unique so a POS scan resolves to exactly one row.
--
-- Idempotent. Additive only. Run AFTER 01_master.sql + 16/17.
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `product_barcodes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `barcode_type` ENUM('EAN13','UPC','CODE128','CODE39','QR','ISBN','CUSTOM') NOT NULL DEFAULT 'EAN13',
  `barcode_value` VARCHAR(64) NOT NULL,
  `pack_level` ENUM('unit','retail','master') NOT NULL DEFAULT 'unit',
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_barcodes_uuid` (`uuid`),
  UNIQUE KEY `uq_product_barcodes_value` (`barcode_value`),
  KEY `idx_pbc_product` (`product_id`),
  KEY `idx_pbc_variant` (`variant_id`),
  KEY `idx_pbc_scan` (`barcode_value`,`status`),
  CONSTRAINT `fk_pbc_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pbc_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed existing single barcodes as each variant's primary (idempotent via the
-- unique barcode_value; re-runs skip already-migrated values).
INSERT INTO `product_barcodes` (`uuid`,`product_id`,`variant_id`,`barcode_type`,`barcode_value`,`pack_level`,`is_primary`,`status`)
SELECT UUID(), pv.product_id, pv.id, 'CUSTOM', pv.barcode, 'unit', 1, 'active'
FROM `product_variants` pv
WHERE pv.barcode IS NOT NULL AND pv.barcode <> '' AND pv.deleted_at IS NULL
ON DUPLICATE KEY UPDATE `status` = `product_barcodes`.`status`;

SET FOREIGN_KEY_CHECKS = 1;
