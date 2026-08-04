-- 72_monline_permission_rename.sql  |  Rename msonline.* permission codes to monline.*
--
-- 71_monline_b2b.sql (originally named 71_msonline_b2b.sql) was already applied to
-- production with the codes msonline.browse / msonline.order / msonline.po.view /
-- msonline.po.receive, and module='msonline'. The B2B marketplace subdomain was
-- corrected from msonline.shiplore.in to monline.shiplore.in after that deploy, and
-- these codes must follow — app/Views/partials/_vendor_sidebar.php reads them
-- verbatim to decide whether to show the Procurement nav links.
--
-- That check only affects non-owner STAFF: an owner's session bypasses the
-- permission-code lookup entirely and always sees the links. A vendor_manager or
-- vendor_shop_manager, however, is granted the permission by exact code match — if
-- the code in the database still said 'msonline.browse' while the application (after
-- the rename in this commit) checks for 'monline.browse', those staff would silently
-- lose the "Buy on monline" and "Purchase Orders" links with no error anywhere.
--
-- UPDATE in place, not delete+reinsert: preserves each permission's `id`, so every
-- existing role_permissions grant — vendor_owner via the scope_class bulk grant,
-- vendor_manager and vendor_shop_manager via their explicit code grants — carries
-- over automatically. No new grants need writing.
--
-- Idempotent: the WHERE clause only matches rows still carrying the old prefix, so a
-- second run does nothing.
--
-- Apply: php database/apply_sql.php database/sql/72_monline_permission_rename.sql

UPDATE `permissions`
   SET `code`   = REPLACE(`code`, 'msonline.', 'monline.'),
       `module` = 'monline'
 WHERE `code` LIKE 'msonline.%';

SELECT 'monline permission-code rename applied' AS result;
