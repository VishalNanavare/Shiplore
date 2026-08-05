-- =====================================================================
-- File 75: CATALOG / STOREFRONT INDEXES (audit 2026-08, I1 + M20 + M24)
--
-- I1: five StoreCatalogRepository methods hard-code
-- FORCE INDEX (idx_products_cat_status_del) / USE INDEX against it, but that
-- index was created only by database/sql/perf2_indexes.php — a standalone script
-- with hard-coded credentials, sourced by nothing in this numbered series.
-- run_all.sql cannot SOURCE a .php file, so a database rebuilt from run_all.sql
-- alone (a staging clone, a rebuilt replica, a restored DR copy, a fresh dev
-- environment) hits MySQL error 1176 (ER_KEY_DOES_NOT_EXIST) — a hard failure,
-- not a silent fallback — on the entire storefront browse, the mobile catalogue
-- API and the facet worker. The first four indexes below are promoted verbatim
-- (identical table/column definitions) from perf1_indexes.php / perf2_indexes.php
-- into this numbered series; both scripts stay in place and are themselves
-- idempotent, so running them again after this file is harmless.
--
-- M20: products has no (deleted_at, created_at) index. AdminProductRepository::
-- list() always filters deleted_at but status is OPTIONAL, so the unfiltered
-- admin product list/export (ORDER BY created_at DESC) has nothing to serve it —
-- idx_products_status_created (below) doesn't help because it leads with status.
--
-- M24: mshops has no (status, deleted_at) index for the monline browse join.
--
-- Idempotent (MariaDB IF NOT EXISTS, matching 54_product_shops_index.sql). No
-- query results change — this is index-only. Building these on a large existing
-- `products` table takes time; MariaDB 10.11 adds secondary indexes online, but
-- run it in a quiet window.
-- =====================================================================
USE `test`;

ALTER TABLE `products`         ADD INDEX IF NOT EXISTS `idx_products_cat_status_del`    (`category_id`,`status`,`deleted_at`);
ALTER TABLE `products`         ADD INDEX IF NOT EXISTS `idx_products_status_created`     (`status`,`deleted_at`,`created_at`);
ALTER TABLE `product_variants` ADD INDEX IF NOT EXISTS `idx_variants_product_default`    (`product_id`,`is_default`);
ALTER TABLE `inventory`        ADD INDEX IF NOT EXISTS `idx_inventory_shop_avail`        (`shop_id`,`available`);
ALTER TABLE `products`         ADD INDEX IF NOT EXISTS `idx_products_del_created`        (`deleted_at`,`created_at`);
ALTER TABLE `mshops`           ADD INDEX IF NOT EXISTS `idx_mshops_status_del`           (`status`,`deleted_at`);
