-- =====================================================================
-- 34_governance_perms.sql  |  Phase X3 — change-request permission codes
-- + idempotent role-grant refreshes (doc 44 §1.3 permission registry).
--
-- Apply: php database/apply_sql.php database/sql/34_governance_perms.sql
-- =====================================================================

INSERT IGNORE INTO `permissions` (`code`,`module`,`action`,`scope_class`,`description`) VALUES
 ('request.create',         'request', 'create',  'shop',     'Raise change requests'),
 ('request.view.own',       'request', 'view_own','shop',     'View own change requests'),
 ('request.approve.vendor', 'request', 'approve', 'vendor',   'Decide vendor-level (L1) change requests'),
 ('request.approve.admin',  'request', 'approve', 'platform', 'Decide platform-level (L2) change requests');

-- super_admin: ALL (idempotent refresh)
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r CROSS JOIN `permissions` p WHERE r.code='super_admin';

-- platform_admin: platform-scope perms (same exclusions as the seed)
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.scope_class='platform'
 WHERE r.code='platform_admin'
   AND p.code NOT IN ('role.create','role.update','role.delete','settings.update',
                      'featureflag.manage','gateway.config.manage','user.impersonate');

-- vendor_owner: vendor/shop scope (idempotent refresh) → gains request.* L1
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.scope_class IN ('vendor','shop')
 WHERE r.code='vendor_owner';

-- managers + vendor managers may raise and track requests
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
   ON p.code IN ('request.create','request.view.own')
 WHERE r.code IN ('vendor_shop_manager','vendor_manager','vendor_catalog_operator','vendor_pos_cashier','vendor_packer');
