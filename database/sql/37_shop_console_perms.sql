-- =====================================================================
-- 37_shop_console_perms.sql  |  Phase S1 — expand the Branch Manager role
-- so the shop console surfaces the operations a branch manager runs for
-- their store. SAFE / operational + read codes only here; the mutating
-- product/inventory/combo grants land with their approval wiring (S2/S3),
-- so a manager can never write directly before the chain exists.
--
-- Idempotent (INSERT IGNORE on code joins). A missing code simply grants
-- nothing. Apply: php database/apply_sql.php database/sql/37_shop_console_perms.sql
--
-- @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §16
-- =====================================================================

-- Branch Manager (role 13) — POS billing + returns, read access to their
-- shop's products / invoices / credit notes / commission holds, report export
-- (no approval), and the ability to raise stock-transfer requests.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN (
   'pos.sell','pos.return.process',
   'product.view',
   'invoice.view','creditnote.view','commission.hold.view',
   'report.export',
   'transfer.view','inventory.transfer'
 )
 WHERE r.code='vendor_shop_manager';

-- POS Cashier (role 14) — billing + returns for their assigned shop.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN (
   'pos.sell','pos.return.process'
 )
 WHERE r.code='vendor_pos_cashier';
