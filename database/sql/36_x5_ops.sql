-- =====================================================================
-- 36_x5_ops.sql  |  Phase X5 — ops completeness.
-- A. Rider payout plans + entries (GAP-033: delivery_boys had ZERO
--    compensation fields) and the plan link on delivery_boys.
-- B. Combo inventory mode on products (virtual = derive from components,
--    assembled = own stock) — doc 44 §9.
--
-- Idempotent, DELIMITER-free. Apply: php database/apply_sql.php database/sql/36_x5_ops.sql
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `rider_payout_plans` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id`           BIGINT UNSIGNED NULL DEFAULT NULL,
  `name`                VARCHAR(80) NOT NULL,
  `model`               ENUM('fixed_commission','per_km','per_order','salary','hybrid') NOT NULL,
  `pct_of_order`        DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `per_km_rate`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `per_order_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `base_salary`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `incentive_threshold` INT UNSIGNED NOT NULL DEFAULT 0,
  `status`              ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`          BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rider_payout_plans_vendor` (`vendor_id`),
  CONSTRAINT `fk_rider_payout_plans_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rider_payout_entries` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`          CHAR(36) NOT NULL,
  `delivery_id`   BIGINT UNSIGNED NULL DEFAULT NULL,
  `rider_user_id` BIGINT UNSIGNED NOT NULL,
  `vendor_id`     BIGINT UNSIGNED NULL DEFAULT NULL,
  `model`         VARCHAR(20) NOT NULL,
  `order_value`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `distance_km`   DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `amount`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status`        ENUM('accrued','paid','reversed') NOT NULL DEFAULT 'accrued',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rider_payout_entries_uuid` (`uuid`),
  UNIQUE KEY `uq_rider_payout_delivery` (`delivery_id`),
  KEY `idx_rider_payout_rider` (`rider_user_id`,`status`),
  CONSTRAINT `fk_rider_payout_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_rider_payout_user` FOREIGN KEY (`rider_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `delivery_boys` ADD COLUMN `payout_plan_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `max_active_orders`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_boys' AND COLUMN_NAME = 'payout_plan_id');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `products` ADD COLUMN `combo_inventory_mode` ENUM(''virtual'',''assembled'') NULL DEFAULT NULL AFTER `is_online_enabled`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'combo_inventory_mode');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET FOREIGN_KEY_CHECKS = 1;
