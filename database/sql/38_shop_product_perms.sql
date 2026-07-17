-- =====================================================================
-- 38_shop_product_perms.sql  |  Phase S2 — let the Branch Manager build &
-- edit products for their shop. Safe to grant now that the approval
-- conversion exists: a manager's submit (go-live) and edits to a LIVE
-- product route through the vendor → admin change-request chain; only
-- harmless drafts are written directly.
--
-- Idempotent. Apply: php database/apply_sql.php database/sql/38_shop_product_perms.sql
-- @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §2
-- =====================================================================

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN (
   'product.create','product.update'
 )
 WHERE r.code='vendor_shop_manager';
