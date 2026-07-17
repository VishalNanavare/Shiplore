-- =====================================================================
-- 35_returns_perms.sql  |  Phase X4 — returns-pipeline permissions
-- (platform scope → platform_admin inherits; super_admin refresh).
--
-- Apply: php database/apply_sql.php database/sql/35_returns_perms.sql
-- =====================================================================

INSERT IGNORE INTO `permissions` (`code`,`module`,`action`,`scope_class`,`description`) VALUES
 ('return.view',   'return', 'view',   'platform', 'View the returns pipeline'),
 ('return.manage', 'return', 'manage', 'platform', 'Move returns through the RMA pipeline');

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r CROSS JOIN `permissions` p WHERE r.code='super_admin';

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.scope_class='platform'
 WHERE r.code='platform_admin'
   AND p.code NOT IN ('role.create','role.update','role.delete','settings.update',
                      'featureflag.manage','gateway.config.manage','user.impersonate');
