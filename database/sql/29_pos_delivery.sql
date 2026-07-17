-- =====================================================================
-- 29_pos_delivery.sql  |  Shipping / delivery orders from POS (T9)
-- A POS sale can be billed for delivery: it creates a row in the existing
-- `deliveries` table so it flows through the SAME rider-assignment + tracking +
-- proof-of-delivery pipeline as web orders. So `deliveries` must accept a POS
-- sale (no sub_order) and carry the recipient inline.
--
-- Idempotent. Additive (nullability widen + new nullable columns).
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

-- A delivery may now originate from a POS sale instead of a sub_order.
ALTER TABLE `deliveries` MODIFY `sub_order_id` BIGINT UNSIGNED NULL DEFAULT NULL;

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

CALL `_add_col`('deliveries','pos_sale_id',       "BIGINT UNSIGNED NULL DEFAULT NULL");
CALL `_add_col`('deliveries','recipient_name',    "VARCHAR(191) NULL DEFAULT NULL");
CALL `_add_col`('deliveries','recipient_phone',   "VARCHAR(20) NULL DEFAULT NULL");
CALL `_add_col`('deliveries','recipient_address', "TEXT NULL");
CALL `_add_col`('pos_sales','delivery_fee',       "DECIMAL(15,4) NOT NULL DEFAULT 0.0000");

DROP PROCEDURE IF EXISTS `_add_col`;

SET FOREIGN_KEY_CHECKS = 1;
