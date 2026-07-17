-- =====================================================================
-- 17_product_variants.sql  |  Product Module P2 — Variant engine
-- Per-variant commercial + logistics + stock-threshold columns, plus a
-- starter set of variant-defining attribute values (Size/Color/Storage)
-- so category-driven variant generation works out of the box.
--
-- Idempotent. Additive only. Run AFTER 01_master.sql + 16_product_module.sql.
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS `_add_col`;
DELIMITER $$
CREATE PROCEDURE `_add_col`(IN p_tbl VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tbl AND COLUMN_NAME = p_col) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_tbl, '` ADD COLUMN `', p_col, '` ', p_ddl);
    PREPARE _st FROM @sql; EXECUTE _st; DEALLOCATE PREPARE _st;
  END IF;
END$$
DELIMITER ;

-- ---------------------------------------------------------------------
-- A. product_variants — commercial / logistics / stock-threshold columns
--    (sections 6, 7, 8). weight_grams already exists.
-- ---------------------------------------------------------------------
CALL `_add_col`('product_variants','cost_price',      "DECIMAL(15,4) NULL DEFAULT NULL");
CALL `_add_col`('product_variants','purchase_price',  "DECIMAL(15,4) NULL DEFAULT NULL");
CALL `_add_col`('product_variants','landing_cost',    "DECIMAL(15,4) NULL DEFAULT NULL");
CALL `_add_col`('product_variants','length_mm',       "DECIMAL(10,2) NULL DEFAULT NULL");
CALL `_add_col`('product_variants','width_mm',        "DECIMAL(10,2) NULL DEFAULT NULL");
CALL `_add_col`('product_variants','height_mm',       "DECIMAL(10,2) NULL DEFAULT NULL");
CALL `_add_col`('product_variants','reorder_level',   "DECIMAL(12,3) NULL DEFAULT NULL");
CALL `_add_col`('product_variants','min_stock_level', "DECIMAL(12,3) NULL DEFAULT NULL");
CALL `_add_col`('product_variants','max_stock_level', "DECIMAL(12,3) NULL DEFAULT NULL");
CALL `_add_col`('product_variants','safety_stock',    "DECIMAL(12,3) NULL DEFAULT NULL");
CALL `_add_col`('product_variants','qr_code',         "VARCHAR(120) NULL DEFAULT NULL");
CALL `_add_col`('product_variants','image_media_id',  "BIGINT UNSIGNED NULL DEFAULT NULL");
CALL `_add_col`('product_variants','visibility',      "ENUM('public','hidden') NOT NULL DEFAULT 'public'");

DROP PROCEDURE IF EXISTS `_add_col`;

-- ---------------------------------------------------------------------
-- B. attribute_values — a uniqueness guard so seeds/imports are idempotent
--    (no-op if the table already has a matching index or dup-free data).
-- ---------------------------------------------------------------------
SET @has_uq := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attribute_values' AND INDEX_NAME = 'uq_attrval_attr_value');
SET @ddl := IF(@has_uq = 0, 'ALTER TABLE `attribute_values` ADD UNIQUE KEY `uq_attrval_attr_value` (`attribute_id`,`value`)', 'SELECT 1');
PREPARE _st FROM @ddl; EXECUTE _st; DEALLOCATE PREPARE _st;

-- ---------------------------------------------------------------------
-- C. Starter values for the variant-defining attributes (idempotent).
--    Resolved by attribute code so it works regardless of id ordering.
-- ---------------------------------------------------------------------
INSERT INTO `attribute_values` (`attribute_id`,`value`,`sort_order`)
SELECT a.id, v.value, v.so FROM `attributes` a
JOIN (
  SELECT 'size' AS code, 'XS' AS value, 1 AS so UNION ALL SELECT 'size','S',2 UNION ALL SELECT 'size','M',3
  UNION ALL SELECT 'size','L',4 UNION ALL SELECT 'size','XL',5 UNION ALL SELECT 'size','XXL',6
  UNION ALL SELECT 'color','Red',1 UNION ALL SELECT 'color','Black',2 UNION ALL SELECT 'color','Blue',3
  UNION ALL SELECT 'color','White',4 UNION ALL SELECT 'color','Green',5
  UNION ALL SELECT 'storage','64GB',1 UNION ALL SELECT 'storage','128GB',2 UNION ALL SELECT 'storage','256GB',3 UNION ALL SELECT 'storage','512GB',4
) v ON v.code = a.code
ON DUPLICATE KEY UPDATE `sort_order` = VALUES(`sort_order`);

SET FOREIGN_KEY_CHECKS = 1;
