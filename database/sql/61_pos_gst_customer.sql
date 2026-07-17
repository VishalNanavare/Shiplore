-- 61_pos_gst_customer.sql
-- Add customer info, payment method, and notes columns to pos_sales.
-- All GST columns (cgst, sgst, igst, taxable_value, discount_total) already exist from 04_transaction.sql.

ALTER TABLE pos_sales
  ADD COLUMN customer_name  VARCHAR(100) NULL DEFAULT NULL AFTER customer_id,
  ADD COLUMN customer_phone VARCHAR(20)  NULL DEFAULT NULL AFTER customer_name,
  ADD COLUMN payment_method VARCHAR(20)  NOT NULL DEFAULT 'cash' AFTER grand_total,
  ADD COLUMN notes          TEXT         NULL DEFAULT NULL AFTER payment_method;
