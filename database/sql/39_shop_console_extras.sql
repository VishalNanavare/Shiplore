-- =====================================================================
-- 39_shop_console_extras.sql  |  Phase S3 — barcode printing + combos.
-- Adds the two permissions these features need and grants them to the
-- Branch Manager (combo.manage lets them PROPOSE; the approval chain still
-- binds: POS-only combo → vendor, online combo → vendor → admin). POS
-- Cashier may print barcodes too.
--
-- Idempotent. Apply: php database/apply_sql.php database/sql/39_shop_console_extras.sql
-- @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §9, §10
-- =====================================================================

INSERT IGNORE INTO `permissions` (`code`,`module`,`action`,`scope_class`,`description`) VALUES
 ('barcode.print', 'barcode', 'print',  'shop',   'Print barcode labels for product variants'),
 ('combo.manage',  'combo',   'manage', 'vendor', 'Build combo offers (subject to approval)');

-- super_admin: ALL
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r CROSS JOIN `permissions` p WHERE r.code='super_admin';

-- vendor_owner: vendor/shop scope (picks up combo.manage + barcode.print)
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.scope_class IN ('vendor','shop')
 WHERE r.code='vendor_owner';

-- Branch Manager: print labels + propose combos
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN ('barcode.print','combo.manage')
 WHERE r.code='vendor_shop_manager';

-- POS Cashier: print labels
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN ('barcode.print')
 WHERE r.code='vendor_pos_cashier';
