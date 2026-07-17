-- =====================================================================
-- 23_stock_transfers.sql  |  Inter-store transfer workflow (T1)
-- Extends stock_transfers (one variant per transfer) with the full lifecycle:
-- request -> approve(reserve) -> pack -> dispatch -> receive(partial) -> close,
-- plus reject/cancel and the actor/quantity tracking each step needs. Stock
-- movement is done by the InventoryService (reserve/transfer_out/transfer_in)
-- so there is no duplicate stock logic here.
--
-- Idempotent. Additive only (new columns + widened status enum).
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

CALL `_add_col`('stock_transfers','transfer_no',   "VARCHAR(40) NULL DEFAULT NULL");
CALL `_add_col`('stock_transfers','vendor_id',     "BIGINT UNSIGNED NULL DEFAULT NULL");
CALL `_add_col`('stock_transfers','reason',        "VARCHAR(255) NULL DEFAULT NULL");
CALL `_add_col`('stock_transfers','notes',         "TEXT NULL");
CALL `_add_col`('stock_transfers','requested_by',  "BIGINT UNSIGNED NULL DEFAULT NULL");
CALL `_add_col`('stock_transfers','approved_by',   "BIGINT UNSIGNED NULL DEFAULT NULL");
CALL `_add_col`('stock_transfers','dispatched_by', "BIGINT UNSIGNED NULL DEFAULT NULL");
CALL `_add_col`('stock_transfers','received_by',   "BIGINT UNSIGNED NULL DEFAULT NULL");
CALL `_add_col`('stock_transfers','decided_at',    "DATETIME NULL DEFAULT NULL");
CALL `_add_col`('stock_transfers','dispatched_at', "DATETIME NULL DEFAULT NULL");
CALL `_add_col`('stock_transfers','received_at',   "DATETIME NULL DEFAULT NULL");
CALL `_add_col`('stock_transfers','qty_dispatched',"DECIMAL(12,3) NOT NULL DEFAULT 0.000");
CALL `_add_col`('stock_transfers','qty_received',  "DECIMAL(12,3) NOT NULL DEFAULT 0.000");

DROP PROCEDURE IF EXISTS `_add_col`;

-- Widen the lifecycle (additive — existing values preserved).
ALTER TABLE `stock_transfers` MODIFY `status`
  ENUM('requested','approved','rejected','reserved','packed','dispatched','in_transit','received','partially_received','reconciled','cancelled','closed')
  NOT NULL DEFAULT 'requested';

-- Backfill vendor_id from the source shop for any pre-existing rows.
UPDATE `stock_transfers` t JOIN `shops` s ON s.id = t.from_shop_id
SET t.vendor_id = s.vendor_id WHERE t.vendor_id IS NULL;

SET FOREIGN_KEY_CHECKS = 1;
