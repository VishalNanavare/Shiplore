-- =====================================================================
-- 20_product_ai_rental.sql  |  Product Module P8 — rental terms
-- Rental config for product_type='rental' (section 5.10). AI suggestions are
-- code-only (provider interface + dev-stub) and need no schema.
--
-- Idempotent. Additive only.
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `product_rental_terms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `price_per_day` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `deposit` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `min_days` INT UNSIGNED NOT NULL DEFAULT 1,
  `max_days` INT UNSIGNED NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prt_product` (`product_id`),
  CONSTRAINT `fk_prt_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
