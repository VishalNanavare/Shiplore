-- =====================================================================
-- 75_manufacturer_delivery.sql
--
-- Gives manufacturing units delivery capability, and manufacturers their own
-- delivery flow for dispatched purchase orders.
--
-- ⚠ THIS REVERSES A DELIBERATE DESIGN DECISION. 70_manufacturer.sql omitted every
-- delivery column from `mshops` ON PURPOSE, so that "a manufacturer cannot set a
-- delivery range" was enforced by the schema rather than by a validation rule that
-- someone could later forget. Two tests existed solely to keep it that way
-- (ManufacturerPanelIsolationTest::testMshopsHasNoDeliveryColumns and
-- testFactoryLocationPartialHasNoDeliveryFields). The operator asked for full parity
-- with the vendor panel including delivery, so the decision is reversed here — and
-- those tests are rewritten to assert the NEW intent rather than deleted, so the
-- reversal stays visible in the history.
--
-- Kept as a SEPARATE migration from 74 for exactly that reason: it is the one piece
-- of this parity work that changes a previously-considered answer, and an operator
-- may want to apply the rest without it.
--
-- Idempotent throughout. Apply after 74_manufacturer_parity.sql.
-- =====================================================================

-- ---------------------------------------------------------------------
-- A. Delivery columns on mshops, mirroring `shops` (01_master.sql:380 and
--    44_shop_delivery_rules.sql). Same names and types, so any future shared
--    serviceability code reads both tables identically.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `_mfg_add_delivery_col`;
DELIMITER $$
CREATE PROCEDURE `_mfg_add_delivery_col`(IN col VARCHAR(64), IN def TEXT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mshops' AND COLUMN_NAME = col) THEN
        SET @ddl = CONCAT('ALTER TABLE `mshops` ADD COLUMN `', col, '` ', def);
        PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END$$
DELIMITER ;

CALL `_mfg_add_delivery_col`('delivery_enabled', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `longitude`");
CALL `_mfg_add_delivery_col`('delivery_radius_km', "DECIMAL(6,2) NULL DEFAULT NULL AFTER `delivery_enabled`");
CALL `_mfg_add_delivery_col`('pickup_enabled', "TINYINT(1) NOT NULL DEFAULT 1 AFTER `delivery_radius_km`");
CALL `_mfg_add_delivery_col`('prep_time_min', "SMALLINT UNSIGNED NULL DEFAULT NULL AFTER `pickup_enabled`");
CALL `_mfg_add_delivery_col`('min_order_value', "DECIMAL(15,4) NULL DEFAULT NULL AFTER `prep_time_min`");
CALL `_mfg_add_delivery_col`('delivery_fee', "DECIMAL(15,4) NULL DEFAULT NULL AFTER `min_order_value`");
CALL `_mfg_add_delivery_col`('free_delivery_above', "DECIMAL(15,4) NULL DEFAULT NULL AFTER `delivery_fee`");

DROP PROCEDURE IF EXISTS `_mfg_add_delivery_col`;

-- delivery_enabled defaults to 0: turning this on is a per-unit decision the
-- manufacturer makes, not something a migration should switch on for everyone.

-- ---------------------------------------------------------------------
-- B. Opening hours and holidays, mirroring shop_hours (01_master.sql:414).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mshop_hours` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mshop_id` BIGINT UNSIGNED NOT NULL,
  `day_of_week` TINYINT UNSIGNED NOT NULL COMMENT '0=Sunday .. 6=Saturday',
  `open_time` TIME NULL DEFAULT NULL,
  `close_time` TIME NULL DEFAULT NULL,
  `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mshop_hours_day` (`mshop_id`,`day_of_week`),
  CONSTRAINT `fk_mshop_hours_mshop` FOREIGN KEY (`mshop_id`) REFERENCES `mshops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mshop_holidays` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mshop_id` BIGINT UNSIGNED NOT NULL,
  `holiday_date` DATE NOT NULL,
  `reason` VARCHAR(191) NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mshop_holiday` (`mshop_id`,`holiday_date`),
  CONSTRAINT `fk_mshop_holidays_mshop` FOREIGN KEY (`mshop_id`) REFERENCES `mshops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- C. Deliveries for dispatched purchase orders.
--
-- A NEW table rather than reusing `deliveries`, because that one is welded to the
-- consumer pipeline: deliveries.sub_order_id is NOT NULL with an FK to `sub_orders`
-- (04_transaction.sql:748-752), and a manufacturer's orders live in
-- mfg_purchase_orders. Relaxing that FK would put a B2B flow inside the live consumer
-- checkout path — the same reasoning 71_monline_b2b.sql gives for not reusing
-- `orders`.
--
-- Riders need NO new schema: delivery_boys.vendor_id already points at `vendors`
-- (10_staff.sql:80) and a manufacturer IS a vendors row, so a manufacturer's riders
-- are ordinary delivery_boys rows.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mfg_deliveries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `po_id` BIGINT UNSIGNED NOT NULL,
  `mshop_id` BIGINT UNSIGNED NOT NULL,
  `rider_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `mode` ENUM('self','pool','3pl','pickup') NOT NULL DEFAULT 'self',
  `delivery_fee` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `eta_at` DATETIME NULL DEFAULT NULL,
  `assigned_at` DATETIME NULL DEFAULT NULL,
  `picked_up_at` DATETIME NULL DEFAULT NULL,
  `delivered_at` DATETIME NULL DEFAULT NULL,
  `failure_reason` VARCHAR(191) NULL DEFAULT NULL,
  `status` ENUM('pending','assigned','picked_up','in_transit','delivered','failed','returned') NOT NULL DEFAULT 'pending',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mfg_deliveries_uuid` (`uuid`),
  UNIQUE KEY `uq_mfg_deliveries_po` (`po_id`),
  KEY `idx_mfg_deliveries_status` (`status`,`mshop_id`),
  KEY `idx_mfg_deliveries_rider` (`rider_user_id`,`status`),
  CONSTRAINT `fk_mfg_deliveries_po` FOREIGN KEY (`po_id`) REFERENCES `mfg_purchase_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mfg_deliveries_mshop` FOREIGN KEY (`mshop_id`) REFERENCES `mshops` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_mfg_deliveries_rider` FOREIGN KEY (`rider_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- D. Permission for unit serviceability. mfg.delivery.assign and mfg.rider.manage
--    were already seeded by 74.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`code`,`module`,`action`,`scope_class`,`description`) VALUES
 ('mfg.unit.serviceability','mfg_unit','serviceability','mshop','Set a unit''s delivery range and opening hours');

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code = 'mfg.unit.serviceability'
 WHERE r.code IN ('manufacturer_owner','manufacturer_manager','manufacturer_unit_manager');
