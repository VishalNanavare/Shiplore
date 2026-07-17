-- =====================================================================
-- 28_pos_returns.sql  |  POS returns / exchange (T8)
-- A return references the original sale and lists the lines coming back; stock is
-- added back via InventoryService and a refund is recorded. Reports read from the
-- existing pos_sales / pos_sale_payments tables (no new tables needed for those).
--
-- Idempotent. Additive only.
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `pos_returns` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `pos_sale_id` BIGINT UNSIGNED NOT NULL,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `reason` VARCHAR(255) NULL DEFAULT NULL,
  `refund_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `refund_method` ENUM('cash','card','upi','wallet','exchange','credit_note') NOT NULL DEFAULT 'cash',
  `status` ENUM('completed','void') NOT NULL DEFAULT 'completed',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pos_returns_uuid` (`uuid`),
  KEY `idx_pr_sale` (`pos_sale_id`),
  KEY `idx_pr_vendor` (`vendor_id`,`created_at`),
  CONSTRAINT `fk_pr_sale` FOREIGN KEY (`pos_sale_id`) REFERENCES `pos_sales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_return_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pos_return_id` BIGINT UNSIGNED NOT NULL,
  `pos_sale_item_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `variant_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `qty` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pri_return` (`pos_return_id`),
  CONSTRAINT `fk_pri_return` FOREIGN KEY (`pos_return_id`) REFERENCES `pos_returns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
