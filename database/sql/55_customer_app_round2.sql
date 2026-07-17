-- Customer app round 2: persist delivery-partner tip, handling charge, GSTIN and
-- delivery instructions on the order header (additive, nullable — safe migration).
-- Mirrors CartService::totals() (handling/tip) and CustomerApiController::placeOrder().
ALTER TABLE `orders`
  ADD COLUMN `handling_total`        DECIMAL(12,2) NULL DEFAULT NULL AFTER `delivery_total`,
  ADD COLUMN `tip_total`             DECIMAL(12,2) NULL DEFAULT NULL AFTER `handling_total`,
  ADD COLUMN `gstin`                 VARCHAR(20)   NULL DEFAULT NULL AFTER `billing_address_json`,
  ADD COLUMN `delivery_instructions` VARCHAR(255)  NULL DEFAULT NULL AFTER `gstin`;
