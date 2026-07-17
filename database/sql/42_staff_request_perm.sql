-- =====================================================================
-- 42_staff_request_perm.sql  |  Let branch managers INITIATE staff changes
-- (the mutation still routes through vendor approval via the change-request
-- engine; this only grants access to the staff section/forms). Idempotent.
--
-- Apply: php database/apply_sql.php database/sql/42_staff_request_perm.sql
-- =====================================================================

INSERT IGNORE INTO `permissions` (`code`,`module`,`action`,`scope_class`,`description`) VALUES
 ('staff.request', 'staff', 'request', 'shop', 'Initiate staff changes (routed to vendor approval)');

-- Branch managers get it.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code = 'staff.request'
 WHERE r.code = 'vendor_shop_manager';

-- Anyone who can already manage staff directly keeps the menu (the item is
-- gated on staff.request).
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT DISTINCT rp.role_id, p.id
 FROM `role_permissions` rp
 JOIN `permissions` pm ON pm.id = rp.permission_id AND pm.code = 'vendor_staff.manage'
 JOIN `permissions` p ON p.code = 'staff.request';

-- super_admin (idempotent refresh).
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code = 'staff.request'
 WHERE r.code = 'super_admin';
