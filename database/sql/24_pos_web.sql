-- =====================================================================
-- 24_pos_web.sql  |  Web POS sale writer (T3)
-- The web POS can bill without a pre-registered terminal or an open shift, so
-- relax those NOT-NULL columns to NULL. The Windows POS still sets them.
--
-- Idempotent. Additive (widens nullability only — no data change).
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `pos_sales` MODIFY `terminal_id`        BIGINT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE `pos_sales` MODIFY `shift_id`           BIGINT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE `pos_sales` MODIFY `cashier_user_id`    BIGINT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE `pos_sales` MODIFY `offline_invoice_no` VARCHAR(60) NULL DEFAULT NULL;

SET FOREIGN_KEY_CHECKS = 1;
