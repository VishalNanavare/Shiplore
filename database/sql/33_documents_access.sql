-- =====================================================================
-- 33_documents_access.sql  |  Phase X2c — document access permissions.
-- New codes + idempotent re-run of the role grant joins so super_admin
-- (ALL) and vendor_owner (vendor/shop scope) pick them up automatically.
--
-- Apply: php database/apply_sql.php database/sql/33_documents_access.sql
-- @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §11 I3
-- =====================================================================

INSERT IGNORE INTO `permissions` (`code`,`module`,`action`,`scope_class`,`description`) VALUES
 ('invoice.view',       'invoice',    'view',     'vendor', 'View invoices and invoice PDFs'),
 ('invoice.download',   'invoice',    'download', 'vendor', 'Download invoice PDFs'),
 ('creditnote.view',    'creditnote', 'view',     'vendor', 'View credit notes and CN PDFs'),
 ('commission.hold.view','commission','hold_view','vendor', 'View commission hold queue');

-- super_admin: ALL (idempotent refresh)
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r CROSS JOIN `permissions` p WHERE r.code='super_admin';

-- vendor_owner: every vendor- and shop-scoped permission (idempotent refresh)
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.scope_class IN ('vendor','shop')
 WHERE r.code='vendor_owner';

-- finance viewer: documents are finance reads
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
   ON p.code IN ('invoice.view','invoice.download','creditnote.view','commission.hold.view')
 WHERE r.code='vendor_finance_viewer';
