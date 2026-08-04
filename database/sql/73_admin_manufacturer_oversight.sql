-- 73_admin_manufacturer_oversight.sql
-- Admin back-office permissions for manufacturers and monline B2B purchase orders.
--
-- Until now the admin panel had no awareness of either. Manufacturers share the
-- `vendors` table under party_type='manufacturer', so they leaked unlabelled into every
-- generic admin vendor query, while everything manufacturer-specific (units, incoming
-- purchase orders) had no admin surface at all.
--
-- WHY DISTINCT CODES, not a reuse of vendor.view / vendor.approve / vendor.reject:
-- reusing them would silently hand manufacturer approval to every role that can already
-- approve a vendor. That is the same failure mode 70_manufacturer.sql §C documents for
-- scope classes — a single existing grant quietly widening to cover a new thing. The
-- application enforces the split too: Admin\VendorController refuses to act on a
-- party_type='manufacturer' row even if the caller holds vendor.approve.
--
-- monline.po.oversight.* is likewise NOT monline.po.view. That one is scope_class
-- 'vendor' and means "a buyer viewing their own orders"; this is platform-scoped and
-- means "staff viewing everyone's".
--
-- Idempotent: INSERT IGNORE throughout, so a second run is a no-op.
--
-- Apply: php database/apply_sql.php database/sql/73_admin_manufacturer_oversight.sql

INSERT IGNORE INTO `permissions` (`code`,`module`,`action`,`scope_class`,`description`) VALUES
 ('manufacturer.view','manufacturer','view','platform','View manufacturers (admin)'),
 ('manufacturer.approve','manufacturer','approve','platform','Approve a manufacturer account'),
 ('manufacturer.reject','manufacturer','reject','platform','Reject a manufacturer account'),
 ('monline.po.oversight.view','monline_po','oversight.view','platform','View all monline B2B purchase orders (admin)'),
 ('monline.po.oversight.cancel','monline_po','oversight.cancel','platform','Force-cancel a stuck monline B2B purchase order (admin)');

-- platform_admin: all platform-scope perms except root-only ones. Re-run because
-- 11_seed.sql:210-214 will not run again on an already-deployed environment, so the
-- codes added above would otherwise reach nobody but super_admin.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.scope_class='platform'
 WHERE r.code='platform_admin'
   AND p.code NOT IN ('role.create','role.update','role.delete','settings.update',
                      'featureflag.manage','gateway.config.manage','user.impersonate');

-- super_admin holds everything.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r CROSS JOIN `permissions` p WHERE r.code='super_admin';

-- Support can look at B2B orders to answer "what happened to PO X", but not cancel one.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('manufacturer.view','monline.po.oversight.view')
 WHERE r.code IN ('support','ops_manager','readonly_auditor');

SELECT 'admin manufacturer + monline oversight permissions applied' AS result;
