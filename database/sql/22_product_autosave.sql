-- =====================================================================
-- 22_product_autosave.sql  |  Product Redesign RB3 — draft autosave
-- A bookkeeping column so the redesigned form can autosave a draft section by
-- section. Drafts themselves use the existing products.status='draft'.
--
-- Idempotent. Additive only.
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

CALL `_add_col`('products','autosaved_at', "DATETIME NULL DEFAULT NULL");

DROP PROCEDURE IF EXISTS `_add_col`;
SET FOREIGN_KEY_CHECKS = 1;
