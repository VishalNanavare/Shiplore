-- =====================================================================
-- 78_manufacturer_governance.sql
--
-- The staff-request half of the manufacturer approvals flow.
--
-- 76_manufacturer_parity.sql already seeded mfg.request.approve (the DECIDE side).
-- This adds the propose side, so a manufacturer_manager can raise a staff change for
-- the owner to decide rather than being refused outright — mirroring the vendor
-- panel's AR-09, where staff.request plays the same role.
--
-- Nothing else is needed for the inbox: the change-request engine, change_requests
-- and change_request_approvals are all tenant-agnostic and key on vendor_id, which a
-- manufacturer has because it IS a `vendors` row.
--
-- DELIMITER-free and PREPARE-guard-free (plain INSERT IGNORE), so it applies through
-- database/apply_sql.php, phpMyAdmin, or any client. Idempotent.
--
-- SCOPE_CLASS WARNING (same as 70/76): 11_seed.sql bulk-grants every permission with
-- scope_class IN ('vendor','shop') to vendor_owner. A manufacturer permission created
-- with either class would be handed silently to EVERY EXISTING VENDOR OWNER.
-- =====================================================================

INSERT IGNORE INTO `permissions` (`code`,`module`,`action`,`scope_class`,`description`) VALUES
 ('mfg.staff.request','mfg_staff','request','manufacturer','Propose a staff change for the owner to approve');

-- Managers propose; they deliberately do NOT get mfg.staff.manage, or the request
-- path would never be taken. The owner already holds every mfg.* permission via
-- 76's bulk grant, including mfg.request.approve.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code = 'mfg.staff.request'
 WHERE r.code IN ('manufacturer_manager','manufacturer_unit_manager');
