-- =====================================================================
-- 43_product_shops.sql  |  Product Redesign S2 — tie products to shops
-- A product belongs to a vendor; this table lists WHICH of that vendor's
-- shops actually carry/sell it. Until now a product was implicitly in all
-- the vendor's shops (only inventory(variant_id,shop_id) hinted at "where").
-- This makes shop assignment explicit so POS can scope a shop's catalog.
--
-- Backfill keeps existing products visible: assign each to the shops where
-- it already holds inventory; products with no inventory anywhere fall back
-- to ALL of their vendor's shops (so nothing disappears from POS).
--
-- Idempotent. Additive only. Run AFTER 01_master.sql + 04_transaction.sql
-- (inventory) + 16/17 (products/variants).
-- Apply: php database/apply_sql.php database/sql/43_product_shops.sql
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `product_shops` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `listed_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_shop` (`product_id`,`shop_id`),
  KEY `idx_ps_shop` (`shop_id`,`status`),
  CONSTRAINT `fk_ps_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ps_shop`    FOREIGN KEY (`shop_id`)    REFERENCES `shops` (`id`)    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1) assign each product to every shop where one of its variants already has
--    an inventory row (guards against soft-deleted products/variants/shops).
INSERT IGNORE INTO `product_shops` (`product_id`,`shop_id`,`status`,`listed_at`)
SELECT DISTINCT pv.product_id, i.shop_id, 'active', NOW()
FROM `inventory` i
JOIN `product_variants` pv ON pv.id = i.variant_id AND pv.deleted_at IS NULL
JOIN `products` p ON p.id = pv.product_id AND p.deleted_at IS NULL
JOIN `shops` s ON s.id = i.shop_id AND s.deleted_at IS NULL;

-- 2) any product with no product_shops row yet -> assign to ALL its vendor's
--    shops so it keeps showing up in POS after filtering goes live.
INSERT IGNORE INTO `product_shops` (`product_id`,`shop_id`,`status`,`listed_at`)
SELECT p.id, s.id, 'active', NOW()
FROM `products` p
JOIN `shops` s ON s.vendor_id = p.vendor_id AND s.deleted_at IS NULL
WHERE p.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `product_shops` ps WHERE ps.product_id = p.id);

SET FOREIGN_KEY_CHECKS = 1;
