-- 44_shop_delivery_rules.sql — per-shop delivery economics for the storefront.
-- Adds a minimum order value, a delivery fee and a free-delivery threshold to
-- each shop so the customer UI can show "Min ₹X · Free over ₹Y · ~N min".
-- Run once:  php database/apply_sql.php database/sql/44_shop_delivery_rules.sql

ALTER TABLE `shops`
    ADD COLUMN `min_order_value`     DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `prep_time_min`,
    ADD COLUMN `delivery_fee`        DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `min_order_value`,
    ADD COLUMN `free_delivery_above` DECIMAL(10,2) NULL          AFTER `delivery_fee`;

-- Sensible defaults for existing shops (only those still at the 0 default).
UPDATE `shops`
   SET `min_order_value`     = 99,
       `delivery_fee`        = 25,
       `free_delivery_above` = 299
 WHERE `delivery_fee` = 0 AND `min_order_value` = 0;
