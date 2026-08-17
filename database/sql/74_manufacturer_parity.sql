-- =====================================================================
-- 74_manufacturer_parity.sql
--
-- Brings the manufacturer panel up to vendor-panel parity. 70_manufacturer.sql
-- seeded 21 mfg.* permissions and created mfg_inventory / mfg_inventory_ledger /
-- mfg_stock_batches / mfg_staff_assignments, then stopped: five of those
-- permission families had no route, controller or view, and the three inventory
-- tables had no PHP referencing them at all. This adds only what those existing
-- structures could not express.
--
-- Idempotent throughout (information_schema guards + INSERT IGNORE), so it is safe
-- to re-run. Apply AFTER 73_admin_manufacturer_oversight.sql.
--
-- SCOPE_CLASS WARNING, repeated from 70 because it is the trap here:
-- 11_seed.sql:234 (re-run at :421) grants vendor_owner every permission with
-- scope_class IN ('vendor','shop') in bulk. A manufacturer permission created with
-- either of those classes is therefore handed silently to EVERY EXISTING VENDOR
-- OWNER. Manufacturer permissions must use 'manufacturer' or 'mshop'.
-- =====================================================================

-- ---------------------------------------------------------------------
-- A. vendor_staff.staff_type — manufacturer staff share this table
--
-- Manufacturer staff are ordinary `vendor_staff` rows (that is the existing
-- design: only the unit assignment differs, via mfg_staff_assignments rather than
-- staff_shop_assignments). But the enum only ever held vendor shop roles, so a
-- manufacturer had no honest value to store — the closest fits were 'manager' and
-- 'other', which would have made the admin staff screens read as though every
-- factory store keeper were an unclassified "other".
--
-- Additive MODIFY: existing rows keep their values, and no vendor code reads the
-- new members. Same technique 70 used to widen users.principal_type.
-- ---------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_staff'
      AND COLUMN_NAME = 'staff_type' AND COLUMN_TYPE LIKE "%'unit_manager'%");
SET @ddl := IF(@has = 0,
    "ALTER TABLE `vendor_staff` MODIFY COLUMN `staff_type` ENUM('branch_manager','cashier','packer','helper','delivery_boy','manager','other','unit_manager','store_keeper','finance_viewer') NOT NULL",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- B. Permissions for the surfaces 70 did not anticipate.
--
-- 'mshop' scope is used for anything a unit-assigned staff member does inside one
-- unit; 'manufacturer' for tenant-wide concerns. Neither is bulk-granted to any
-- vendor role.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`code`,`module`,`action`,`scope_class`,`description`) VALUES
 ('mfg.notification.view','mfg_notification','view','manufacturer','View own notifications'),
 ('mfg.transfer.view','mfg_transfer','view','manufacturer','View inter-unit stock transfers'),
 ('mfg.transfer.manage','mfg_transfer','manage','mshop','Raise and action inter-unit stock transfers'),
 ('mfg.gst.view','mfg_gst','view','manufacturer','View GST collected on B2B sales'),
 ('mfg.invoice.view','mfg_invoice','view','manufacturer','View purchase-order invoices'),
 ('mfg.barcode.print','mfg_barcode','print','manufacturer','Print barcode labels'),
 ('mfg.report.export','mfg_report','export','manufacturer','Export manufacturer reports'),
 ('mfg.request.approve','mfg_request','approve','manufacturer','Approve staff change requests'),
 ('mfg.delivery.assign','mfg_delivery','assign','mshop','Assign a rider to a dispatched purchase order'),
 ('mfg.rider.manage','mfg_rider','manage','manufacturer','Manage the manufacturer''s own delivery riders'),
 ('mfg.pos.sell','mfg_pos','sell','mshop','Sell at a factory-outlet POS counter');

-- ---------------------------------------------------------------------
-- C. Grants.
--
-- Mirrors 70's shape: the owner gets everything manufacturer- or mshop-scoped, and
-- the narrower roles get only what their job needs. Written as INSERT ... SELECT
-- against permissions.code so re-running cannot double-insert (uq on
-- role_id+permission_id) and so a permission added above is picked up here.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
  ON p.scope_class IN ('manufacturer','mshop') AND p.code LIKE 'mfg.%'
 WHERE r.code = 'manufacturer_owner';

-- Manager: everything except the owner-only identity and staff controls.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
  ON p.code IN ('mfg.notification.view','mfg.transfer.view','mfg.transfer.manage',
                'mfg.gst.view','mfg.invoice.view','mfg.barcode.print','mfg.report.export',
                'mfg.delivery.assign','mfg.pos.sell')
 WHERE r.code = 'manufacturer_manager';

-- Unit manager: runs one unit — stock movement, dispatch and the counter.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
  ON p.code IN ('mfg.notification.view','mfg.transfer.view','mfg.transfer.manage',
                'mfg.barcode.print','mfg.delivery.assign','mfg.pos.sell')
 WHERE r.code = 'manufacturer_unit_manager';

-- Store keeper: stock and labels only. No dispatch, no counter, no money.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
  ON p.code IN ('mfg.notification.view','mfg.transfer.view','mfg.barcode.print')
 WHERE r.code = 'manufacturer_store_keeper';

-- Finance viewer: read-only money.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
  ON p.code IN ('mfg.notification.view','mfg.gst.view','mfg.invoice.view','mfg.report.export')
 WHERE r.code = 'manufacturer_finance_viewer';
