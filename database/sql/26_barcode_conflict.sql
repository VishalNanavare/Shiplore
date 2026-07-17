-- =====================================================================
-- 26_barcode_conflict.sql  |  Multiple items per barcode (T6)
-- Drop the GLOBAL unique on product_barcodes.barcode_value so the same barcode
-- can map to several items (different pack / price) — the POS then shows a
-- picker. Uniqueness is enforced per-variant in the app layer instead. The
-- non-unique scan index (idx_pbc_scan) stays for fast lookup.
--
-- Idempotent. Non-destructive (index change only; no data touched).
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

SET @drop := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_barcodes' AND INDEX_NAME = 'uq_product_barcodes_value');
SET @sql := IF(@drop > 0, 'ALTER TABLE `product_barcodes` DROP INDEX `uq_product_barcodes_value`', 'DO 0');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- Ensure the fast scan index exists (lookup by value + status).
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_barcodes' AND INDEX_NAME = 'idx_pbc_scan');
SET @sql := IF(@has = 0, 'ALTER TABLE `product_barcodes` ADD INDEX `idx_pbc_scan` (`barcode_value`,`status`)', 'DO 0');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET FOREIGN_KEY_CHECKS = 1;
