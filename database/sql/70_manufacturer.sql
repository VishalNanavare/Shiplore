-- 70_manufacturer.sql  |  Manufacturer tenant type (phase A).
--
-- Adds a second seller kind alongside vendors. A manufacturer is a row in
-- `vendors` carrying party_type='manufacturer'; the panel, registration and
-- product screens are separate code, but the tenant/KYC/settlement/commission
-- machinery is shared so the admin panel keeps working unchanged.
--
-- Differences from a vendor, encoded here:
--   * no delivery range          -> mshops has no delivery_radius_km/polygon/pickup columns
--   * making price + selling     -> product_variants.making_price (base_price stays "selling")
--   * invisible to consumers     -> enforced in StoreCatalogRepository, not in schema
--   * stock lives in mshops      -> parallel mfg_* stock tables, keyed on mshop_id
--
-- NAMING: the discriminator is `party_type`, NOT `vendor_kind` — that column already
-- exists on `vendors` for the franchise hierarchy (14_warehouse_franchise_ops.sql:119)
-- with values independent/franchise_parent/franchise_branch. The two are orthogonal:
-- a manufacturer can also be a franchise parent.
--
-- Fully idempotent — safe to re-run.
--
-- Apply: php database/apply_sql.php database/sql/70_manufacturer.sql

SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS `_mfg_add_col`;
DELIMITER $$
CREATE PROCEDURE `_mfg_add_col`(IN p_tbl VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tbl AND COLUMN_NAME = p_col) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_tbl, '` ADD COLUMN `', p_col, '` ', p_ddl);
    PREPARE _st FROM @sql; EXECUTE _st; DEALLOCATE PREPARE _st;
  END IF;
END$$

DROP PROCEDURE IF EXISTS `_mfg_add_idx`$$
CREATE PROCEDURE `_mfg_add_idx`(IN p_tbl VARCHAR(64), IN p_idx VARCHAR(64), IN p_cols TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tbl AND INDEX_NAME = p_idx) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_tbl, '` ADD KEY `', p_idx, '` (', p_cols, ')');
    PREPARE _st FROM @sql; EXECUTE _st; DEALLOCATE PREPARE _st;
  END IF;
END$$
DELIMITER ;

-- ---------------------------------------------------------------------
-- A. The tenant discriminator
-- ---------------------------------------------------------------------
CALL `_mfg_add_col`('vendors', 'party_type', "ENUM('vendor','manufacturer') NOT NULL DEFAULT 'vendor' AFTER `owner_user_id`");
CALL `_mfg_add_idx`('vendors', 'idx_vendors_party_type', '`party_type`,`status`');

-- ---------------------------------------------------------------------
-- B. Principal type
--
-- users.principal_type gates login landing + the webAuth:<type> route pin.
-- audit_logs.actor_principal_type is a SEPARATE, wider enum — without it every
-- audit write by a manufacturer actor is rejected/truncated by MySQL.
-- ---------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'principal_type' AND COLUMN_TYPE LIKE "%'manufacturer'%");
SET @ddl := IF(@has = 0,
    "ALTER TABLE `users` MODIFY COLUMN `principal_type` ENUM('platform','vendor','customer','rider','manufacturer') NOT NULL",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'
      AND COLUMN_NAME = 'actor_principal_type' AND COLUMN_TYPE LIKE "%'manufacturer'%");
SET @ddl := IF(@has = 0,
    "ALTER TABLE `audit_logs` MODIFY COLUMN `actor_principal_type` ENUM('platform','vendor','customer','rider','system','pos_device','manufacturer') NOT NULL DEFAULT 'system'",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- C. Permission scope class
--
-- CRITICAL: vendor_owner is granted in bulk by scope_class IN ('vendor','shop')
-- (11_seed.sql:234, re-run at :421). If manufacturer permissions were created
-- with scope_class='vendor' they would be silently handed to EVERY vendor owner.
-- A distinct scope class is what makes that impossible.
-- ---------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'permissions'
      AND COLUMN_NAME = 'scope_class' AND COLUMN_TYPE LIKE "%'manufacturer'%");
SET @ddl := IF(@has = 0,
    "ALTER TABLE `permissions` MODIFY COLUMN `scope_class` ENUM('platform','vendor','shop','self','manufacturer','mshop') NOT NULL",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles'
      AND COLUMN_NAME = 'scope_class' AND COLUMN_TYPE LIKE "%'manufacturer'%");
SET @ddl := IF(@has = 0,
    "ALTER TABLE `roles` MODIFY COLUMN `scope_class` ENUM('platform','vendor','shop','self','manufacturer','mshop') NOT NULL",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_roles'
      AND COLUMN_NAME = 'scope_type' AND COLUMN_TYPE LIKE "%'manufacturer'%");
SET @ddl := IF(@has = 0,
    "ALTER TABLE `user_roles` MODIFY COLUMN `scope_type` ENUM('platform','vendor','shop','self','manufacturer','mshop') NOT NULL",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- D. Making price
--
-- base_price REMAINS the selling price (it is what the storefront, cart and POS
-- all read). making_price is the manufacturer's production cost. mrp stays NULL
-- for manufacturer products — they have no MRP concept.
-- Invariant making_price < base_price is enforced in application code; MySQL
-- CHECK constraints are not used elsewhere in this schema.
-- ---------------------------------------------------------------------
CALL `_mfg_add_col`('product_variants', 'making_price', "DECIMAL(15,4) NULL DEFAULT NULL AFTER `mrp`");

-- ---------------------------------------------------------------------
-- E. mshops — manufacturer units/factories
--
-- Mirrors `shops` MINUS every delivery/serviceability column:
--   delivery_radius_km, delivery_polygon, pickup_enabled, prep_time_min,
--   min_order_value, delivery_fee, free_delivery_above.
-- A manufacturer cannot express a delivery range because the columns do not exist.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mshops` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to vendors where party_type=manufacturer',
  `name` VARCHAR(191) NOT NULL,
  `code` VARCHAR(64) NOT NULL,
  `gstin` CHAR(15) NULL DEFAULT NULL,
  `gstin_verified_at` DATETIME NULL DEFAULT NULL,
  `gstin_status` ENUM('unverified','pending','verified','failed') NOT NULL DEFAULT 'unverified',
  `address_json` JSON NOT NULL,
  `pincode` VARCHAR(10) NOT NULL,
  `state_code` CHAR(2) NOT NULL,
  `latitude` DECIMAL(10,7) NOT NULL,
  `longitude` DECIMAL(10,7) NOT NULL,
  `status` ENUM('active','inactive','closed_temp') NOT NULL DEFAULT 'active',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mshops_uuid` (`uuid`),
  UNIQUE KEY `uq_mshops_vendor_code` (`vendor_id`,`code`),
  KEY `idx_mshops_vendor` (`vendor_id`),
  KEY `idx_mshops_geo` (`latitude`,`longitude`),
  KEY `idx_mshops_pincode` (`pincode`),
  CONSTRAINT `fk_mshops_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which of a manufacturer's units carry a given product (mirror of product_shops).
CREATE TABLE IF NOT EXISTS `product_mshops` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `mshop_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `listed_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_mshop` (`product_id`,`mshop_id`),
  KEY `idx_pms_mshop` (`mshop_id`,`status`),
  CONSTRAINT `fk_pms_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pms_mshop` FOREIGN KEY (`mshop_id`) REFERENCES `mshops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- F. Manufacturer stock
--
-- `inventory`, `inventory_ledger` and `stock_batches` are all keyed on shop_id
-- with FKs to `shops`, so manufacturer units need their own parallel tables.
-- Column shapes match their vendor counterparts exactly (04_transaction.sql:303+)
-- so InventoryService's logic can be mirrored without reinterpretation.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mfg_inventory` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `mshop_id` BIGINT UNSIGNED NOT NULL,
  `on_hand` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `reserved` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `available` DECIMAL(15,3) AS (`on_hand` - `reserved`) STORED,
  `reorder_level` DECIMAL(15,3) NULL DEFAULT NULL,
  `status` ENUM('in_stock','low','out_of_stock') NOT NULL DEFAULT 'out_of_stock',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mfg_inventory_variant_mshop` (`variant_id`,`mshop_id`),
  KEY `idx_mfg_inventory_mshop` (`mshop_id`,`status`),
  CONSTRAINT `fk_mfg_inv_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mfg_inv_mshop` FOREIGN KEY (`mshop_id`) REFERENCES `mshops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mfg_inventory_ledger` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `mshop_id` BIGINT UNSIGNED NOT NULL,
  `movement_type` ENUM('production','sale','return','adjustment','transfer_in','transfer_out','reservation','release','write_off') NOT NULL,
  `qty` DECIMAL(15,3) NOT NULL,
  `balance_after` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `ref_type` VARCHAR(40) NULL DEFAULT NULL,
  `ref_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `note` VARCHAR(255) NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mfg_ledger_variant_mshop` (`variant_id`,`mshop_id`),
  KEY `idx_mfg_ledger_ref` (`ref_type`,`ref_id`),
  KEY `idx_mfg_ledger_created` (`created_at`),
  CONSTRAINT `fk_mfg_led_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mfg_led_mshop` FOREIGN KEY (`mshop_id`) REFERENCES `mshops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mfg_stock_batches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `mshop_id` BIGINT UNSIGNED NOT NULL,
  `batch_no` VARCHAR(64) NULL DEFAULT NULL,
  `mfg_date` DATE NULL DEFAULT NULL,
  `exp_date` DATE NULL DEFAULT NULL,
  `qty` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `making_cost` DECIMAL(15,4) NULL DEFAULT NULL,
  `status` ENUM('active','expired','consumed') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mfg_batch_variant_mshop` (`variant_id`,`mshop_id`,`status`),
  KEY `idx_mfg_batch_exp` (`exp_date`),
  CONSTRAINT `fk_mfg_batch_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mfg_batch_mshop` FOREIGN KEY (`mshop_id`) REFERENCES `mshops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff -> unit assignment (mirror of staff_shop_assignments). vendor_staff is
-- reused: a manufacturer's staff are vendor_staff rows under its vendor id.
CREATE TABLE IF NOT EXISTS `mfg_staff_assignments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_staff_id` BIGINT UNSIGNED NOT NULL,
  `mshop_id` BIGINT UNSIGNED NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `assigned_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mfg_staff_mshop` (`vendor_staff_id`,`mshop_id`),
  KEY `idx_mfg_staff_mshop` (`mshop_id`,`status`),
  CONSTRAINT `fk_mfg_sa_staff` FOREIGN KEY (`vendor_staff_id`) REFERENCES `vendor_staff` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mfg_sa_mshop` FOREIGN KEY (`mshop_id`) REFERENCES `mshops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- G. Permissions (scope_class 'manufacturer' / 'mshop')
--
-- Deliberately NOT mirrored from the vendor set: no serviceability, no
-- delivery.assign, no rider.manage, no POS. Manufacturers do none of those.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`code`,`module`,`action`,`scope_class`,`description`) VALUES
 ('mfg.dashboard.view','mfg_dashboard','view','manufacturer','View manufacturer dashboard'),
 ('mfg.profile.view','mfg_profile','view','manufacturer','View manufacturer profile'),
 ('mfg.profile.manage','mfg_profile','manage','manufacturer','Manage manufacturer profile'),
 ('mfg.unit.view','mfg_unit','view','manufacturer','View manufacturing units'),
 ('mfg.unit.create','mfg_unit','create','manufacturer','Create a manufacturing unit'),
 ('mfg.unit.update','mfg_unit','update','mshop','Update a manufacturing unit'),
 ('mfg.product.view','mfg_product','view','manufacturer','View manufacturer products'),
 ('mfg.product.create','mfg_product','create','manufacturer','Create a manufacturer product'),
 ('mfg.product.update','mfg_product','update','manufacturer','Update a manufacturer product'),
 ('mfg.product.submit','mfg_product','submit','manufacturer','Submit a product for approval'),
 ('mfg.pricing.manage','mfg_pricing','manage','manufacturer','Set making and selling price'),
 ('mfg.inventory.view','mfg_inventory','view','mshop','View unit stock'),
 ('mfg.inventory.adjust','mfg_inventory','adjust','mshop','Adjust unit stock'),
 ('mfg.staff.view','mfg_staff','view','manufacturer','View manufacturer staff'),
 ('mfg.staff.manage','mfg_staff','manage','manufacturer','Manage manufacturer staff'),
 ('mfg.document.view','mfg_document','view','manufacturer','View KYC documents'),
 ('mfg.document.upload','mfg_document','upload','manufacturer','Upload KYC documents'),
 ('mfg.media.view','mfg_media','view','manufacturer','View media library'),
 ('mfg.media.upload','mfg_media','upload','manufacturer','Upload media'),
 ('mfg.po.view','mfg_po','view','manufacturer','View incoming purchase orders'),
 ('mfg.po.manage','mfg_po','manage','manufacturer','Accept/dispatch purchase orders');

-- ---------------------------------------------------------------------
-- H. Roles (ids 22+; 1-21 are taken by 11_seed.sql and 11_seed.sql:429)
--
-- Explicit ids for the same reason 11_seed.sql:177 gives: vendor_id is NULL for
-- system roles and MySQL treats NULL as distinct in (code,vendor_id), so
-- INSERT IGNORE alone would not dedupe on re-run.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `roles` (`id`,`code`,`name`,`scope_class`,`vendor_id`,`is_system`,`status`) VALUES
 (22,'manufacturer_owner','Manufacturer Owner','manufacturer',NULL,1,'active'),
 (23,'manufacturer_manager','Manufacturer Manager','manufacturer',NULL,1,'active'),
 (24,'manufacturer_unit_manager','Unit Manager','mshop',NULL,1,'active'),
 (25,'manufacturer_store_keeper','Store Keeper','mshop',NULL,1,'active'),
 (26,'manufacturer_finance_viewer','Finance Viewer (Manufacturer)','manufacturer',NULL,1,'active');

-- manufacturer_owner: every manufacturer- and mshop-scoped permission.
-- This mirrors the vendor_owner idiom (11_seed.sql:234) but is scoped to the NEW
-- classes only, so it can never pick up a vendor permission — and conversely
-- vendor_owner's grant can never pick up a manufacturer one.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.scope_class IN ('manufacturer','mshop')
 WHERE r.code = 'manufacturer_owner';
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN ('notification.view.own','wallet.view.own')
 WHERE r.code = 'manufacturer_owner';

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('mfg.dashboard.view','mfg.profile.view','mfg.unit.view','mfg.product.view','mfg.product.create',
   'mfg.product.update','mfg.product.submit','mfg.pricing.manage','mfg.inventory.view',
   'mfg.inventory.adjust','mfg.media.view','mfg.media.upload','mfg.po.view','mfg.po.manage')
 WHERE r.code = 'manufacturer_manager';

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('mfg.dashboard.view','mfg.unit.view','mfg.unit.update','mfg.product.view',
   'mfg.inventory.view','mfg.inventory.adjust','mfg.po.view','mfg.po.manage')
 WHERE r.code = 'manufacturer_unit_manager';

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('mfg.inventory.view','mfg.inventory.adjust','mfg.product.view','mfg.po.view')
 WHERE r.code = 'manufacturer_store_keeper';

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('mfg.dashboard.view','mfg.product.view','mfg.po.view','mfg.profile.view')
 WHERE r.code = 'manufacturer_finance_viewer';

DROP PROCEDURE IF EXISTS `_mfg_add_col`;
DROP PROCEDURE IF EXISTS `_mfg_add_idx`;
SET FOREIGN_KEY_CHECKS = 1;

SELECT 'manufacturer tenant migration applied' AS result;
