-- =====================================================================
-- File 74: REPORT / DASHBOARD INDEXES (audit 2026-08, M21)
--
-- sub_orders is the fastest-growing table in the schema (one row per product per
-- order, by design) and orders grows at the same rate. Every existing index on
-- both leads with vendor_id, order_id or shop_id, so a plain
-- "WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 8" — the admin
-- dashboard's recent-orders panel — full-scans and filesorts the whole table on
-- every load, to return 8 rows. ReportRepository::gstSummary()/exportRows() full-
-- scan the same way regardless of how narrow the requested date window is.
--
-- Idempotent (MariaDB IF NOT EXISTS, matching 54_product_shops_index.sql).
-- No query results change — this is index-only.
-- =====================================================================
USE `test`;

ALTER TABLE `sub_orders` ADD INDEX IF NOT EXISTS `idx_suborders_del_created` (`deleted_at`,`created_at`);
ALTER TABLE `orders`     ADD INDEX IF NOT EXISTS `idx_orders_del_created`    (`deleted_at`,`created_at`);
