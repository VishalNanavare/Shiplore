-- =====================================================================
-- 27_pos_permissions.sql  |  Store-scoped POS permissions (T7)
-- Adds a dedicated credit-sale permission and grants it to the POS-capable
-- roles. pos.sell / pos.discount.apply / pos.price.override / pos.return.process
-- already exist (11_seed) and are enforced by the controllers in this batch.
--
-- Idempotent. Additive only.
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

-- 1) the permission code (module/action/scope_class mirror pos.sell)
INSERT INTO `permissions` (`code`, `module`, `action`, `scope_class`, `description`, `created_at`, `updated_at`)
SELECT 'pos.credit.sale', 'pos', 'credit.sale', 'shop', 'Sell on credit / udhaar at POS', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `code` = 'pos.credit.sale');

-- 2) grant to the roles that may take udhaar (owner, shop manager, cashier, super admin)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r CROSS JOIN `permissions` p
WHERE p.code = 'pos.credit.sale'
  AND r.code IN ('super_admin', 'vendor_owner', 'vendor_shop_manager', 'vendor_pos_cashier')
  AND NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

SET FOREIGN_KEY_CHECKS = 1;
