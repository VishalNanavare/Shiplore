-- =====================================================================
-- File 14: WAREHOUSE, FRANCHISE, BACKUP, COMMISSION-VERSIONING, GST-HASH
-- Future-expansion scaffolds + governance, all ADDITIVE (no redesign).
-- Forward migration; clean rebuild via run_all drops first.
-- =====================================================================
USE `test`;
SET FOREIGN_KEY_CHECKS = 0;

-- 1) Warehouses (vendor-owned stock-holding locations, future distribution).
CREATE TABLE IF NOT EXISTS `warehouses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `code` VARCHAR(64) NOT NULL,
  `address_json` JSON NULL,
  `pincode` VARCHAR(10) NULL DEFAULT NULL,
  `state_code` CHAR(2) NULL DEFAULT NULL,
  `latitude` DECIMAL(10,7) NULL DEFAULT NULL,
  `longitude` DECIMAL(10,7) NULL DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_warehouses_uuid` (`uuid`),
  UNIQUE KEY `uq_warehouses_vendor_code` (`vendor_id`,`code`),
  KEY `idx_warehouses_vendor` (`vendor_id`),
  CONSTRAINT `fk_warehouses_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Warehouse stock balances (mirrors inventory; warehouse-level).
CREATE TABLE IF NOT EXISTS `warehouse_inventory` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `warehouse_id` BIGINT UNSIGNED NOT NULL,
  `on_hand` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `reserved` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `available` DECIMAL(15,3) AS (`on_hand` - `reserved`) STORED,
  `reorder_level` DECIMAL(15,3) NULL DEFAULT NULL,
  `status` ENUM('in_stock','low','out_of_stock') NOT NULL DEFAULT 'in_stock',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wh_inv_uuid` (`uuid`),
  UNIQUE KEY `uq_wh_inv_variant_wh` (`variant_id`,`warehouse_id`),
  KEY `idx_wh_inv_warehouse` (`warehouse_id`,`status`),
  CONSTRAINT `fk_wh_inv_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_wh_inv_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Warehouse transfers (warehouse->warehouse and warehouse->shop). Shop<->shop stays in stock_transfers.
CREATE TABLE IF NOT EXISTS `warehouse_transfers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `from_warehouse_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `to_warehouse_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `to_shop_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `qty` DECIMAL(15,3) NOT NULL,
  `status` ENUM('requested','approved','dispatched','received','partially_received','reconciled','cancelled') NOT NULL DEFAULT 'requested',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wh_transfers_uuid` (`uuid`),
  KEY `idx_wh_transfers_from` (`from_warehouse_id`),
  KEY `idx_wh_transfers_to_wh` (`to_warehouse_id`),
  KEY `idx_wh_transfers_to_shop` (`to_shop_id`),
  KEY `idx_wh_transfers_variant` (`variant_id`),
  CONSTRAINT `fk_wh_transfers_from` FOREIGN KEY (`from_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_wh_transfers_to_wh` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_wh_transfers_to_shop` FOREIGN KEY (`to_shop_id`) REFERENCES `shops` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_wh_transfers_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Backup/restore tracking.
CREATE TABLE IF NOT EXISTS `backup_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `type` ENUM('db','media','full') NOT NULL DEFAULT 'db',
  `status` ENUM('started','completed','failed') NOT NULL DEFAULT 'started',
  `location_ref` VARCHAR(255) NULL DEFAULT NULL,
  `size_bytes` BIGINT UNSIGNED NULL DEFAULT NULL,
  `checksum` CHAR(64) NULL DEFAULT NULL,
  `started_at` DATETIME NULL DEFAULT NULL,
  `finished_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_backup_runs_uuid` (`uuid`),
  KEY `idx_backup_runs_type` (`type`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Commission versioned history (audit of rule changes over time).
CREATE TABLE IF NOT EXISTS `commission_rule_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `commission_rule_id` BIGINT UNSIGNED NOT NULL,
  `version` INT UNSIGNED NOT NULL,
  `snapshot` JSON NOT NULL,
  `changed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comm_rule_version` (`commission_rule_id`,`version`),
  CONSTRAINT `fk_crv_rule` FOREIGN KEY (`commission_rule_id`) REFERENCES `commission_rules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Franchise / multi-branch hierarchy (extensible; no new table needed).
ALTER TABLE `vendors`
  ADD COLUMN `parent_vendor_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `owner_user_id`,
  ADD COLUMN `vendor_kind` ENUM('independent','franchise_parent','franchise_branch') NOT NULL DEFAULT 'independent' AFTER `parent_vendor_id`,
  ADD KEY `idx_vendors_parent` (`parent_vendor_id`),
  ADD CONSTRAINT `fk_vendors_parent` FOREIGN KEY (`parent_vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- 7) Commission rule extensions: type, campaign override, versioning, effective dating.
ALTER TABLE `commission_rules`
  ADD COLUMN `commission_type` ENUM('percentage','fixed','slab','exception') NOT NULL DEFAULT 'percentage' AFTER `rate`,
  ADD COLUMN `promotion_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `product_id`,
  ADD COLUMN `version` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `priority`,
  ADD COLUMN `effective_from` DATE NULL DEFAULT NULL AFTER `version`,
  ADD COLUMN `effective_to` DATE NULL DEFAULT NULL AFTER `effective_from`,
  ADD KEY `idx_comm_rules_promotion` (`promotion_id`),
  ADD CONSTRAINT `fk_comm_rules_promotion` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- 8) GST verification payload hashes (audit).
ALTER TABLE `gst_verifications`
  ADD COLUMN `request_hash` CHAR(64) NULL DEFAULT NULL AFTER `response`,
  ADD COLUMN `response_hash` CHAR(64) NULL DEFAULT NULL AFTER `request_hash`;

-- 9) Canonical code for delivery_zones (master governance).
ALTER TABLE `delivery_zones`
  ADD COLUMN `code` VARCHAR(40) NULL DEFAULT NULL AFTER `name`,
  ADD UNIQUE KEY `uq_delivery_zones_code` (`code`);

SET FOREIGN_KEY_CHECKS = 1;
SELECT CONCAT('Warehouse/franchise/ops migration applied. tables=',
       (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='test')) AS result;
-- End of 14_warehouse_franchise_ops.sql
