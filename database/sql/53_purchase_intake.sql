-- 53_purchase_intake.sql  |  Supplier master + purchase intake (Add Inventory)
-- Ports the WPF Shiplore POS purchase flow to the multi-vendor MySQL cloud:
--   suppliers              — full supplier master (distinct from marketplace vendors)
--   purchase_invoices      — purchase header (challan / supplier-invoice / APMC / our-invoice + GST breakdown)
--   purchase_invoice_items — per-line: purchase price, free qty, multi-tier discount,
--                            CGST/SGST/IGST/CESS, batch/MFG/EXP, INSERT/UPDATE mode
-- Tenant-scoped by vendor_id. Stock-in reuses InventoryService::receive() (stock_batches +
-- inventory + inventory_ledger 'purchase'). Idempotent (CREATE TABLE IF NOT EXISTS).
--
-- Apply: php database/apply_sql.php database/sql/53_purchase_intake.sql

CREATE TABLE IF NOT EXISTS `suppliers` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`            CHAR(36) NOT NULL,
  `vendor_id`       BIGINT UNSIGNED NOT NULL,
  `name`            VARCHAR(191) NOT NULL,
  `gstin`           VARCHAR(20) NULL DEFAULT NULL,
  `aadhaar_no`      VARCHAR(20) NULL DEFAULT NULL,
  `phone`           VARCHAR(25) NULL DEFAULT NULL,
  `email`           VARCHAR(191) NULL DEFAULT NULL,
  `address`         VARCHAR(500) NULL DEFAULT NULL,
  `city`            VARCHAR(80) NULL DEFAULT NULL,
  `state_code`      VARCHAR(2) NULL DEFAULT NULL,
  `pincode`         VARCHAR(10) NULL DEFAULT NULL,
  `salesman_name`   VARCHAR(120) NULL DEFAULT NULL,
  `salesman_phone`  VARCHAR(20) NULL DEFAULT NULL,
  `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `row_version`     INT UNSIGNED NOT NULL DEFAULT 1,
  `origin`          VARCHAR(40) NULL DEFAULT NULL,
  `last_synced_at`  DATETIME NULL DEFAULT NULL,
  `created_by`      BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by`      BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`      DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_suppliers_uuid` (`uuid`),
  KEY `idx_suppliers_vendor` (`vendor_id`, `status`),
  KEY `idx_suppliers_name` (`name`),
  KEY `idx_suppliers_phone` (`phone`),
  KEY `idx_suppliers_gstin` (`gstin`),
  CONSTRAINT `fk_suppliers_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_invoices` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`               CHAR(36) NOT NULL,
  `vendor_id`          BIGINT UNSIGNED NOT NULL,
  `shop_id`            BIGINT UNSIGNED NULL DEFAULT NULL,
  `supplier_id`        BIGINT UNSIGNED NULL DEFAULT NULL,
  `our_invoice_no`     VARCHAR(40) NOT NULL,
  `challan_no`         VARCHAR(60) NULL DEFAULT NULL,
  `supplier_invoice_id` VARCHAR(60) NULL DEFAULT NULL,
  `invoice_date`       DATE NULL DEFAULT NULL,
  `apmc`               DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `subtotal`           DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `discount_total`     DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `sgst_total`         DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `cgst_total`         DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `igst_total`         DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `cess_total`         DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `total_qty`          DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `grand_total`        DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `status`             ENUM('draft','posted') NOT NULL DEFAULT 'posted',
  `row_version`        INT UNSIGNED NOT NULL DEFAULT 1,
  `origin`             VARCHAR(40) NULL DEFAULT NULL,
  `last_synced_at`     DATETIME NULL DEFAULT NULL,
  `created_by`         BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by`         BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`         DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_purchase_invoices_uuid` (`uuid`),
  UNIQUE KEY `uq_purchase_invoices_no` (`vendor_id`, `our_invoice_no`),
  KEY `idx_purchase_invoices_vendor` (`vendor_id`, `invoice_date`),
  KEY `idx_purchase_invoices_supplier` (`supplier_id`),
  CONSTRAINT `fk_purchase_invoices_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_invoices_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_invoice_items` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_invoice_id` BIGINT UNSIGNED NOT NULL,
  `mode`                ENUM('insert','update') NOT NULL DEFAULT 'insert',
  `product_id`          BIGINT UNSIGNED NULL DEFAULT NULL,
  `variant_id`          BIGINT UNSIGNED NULL DEFAULT NULL,
  `product_name`        VARCHAR(191) NULL DEFAULT NULL,
  `barcode`             VARCHAR(64) NULL DEFAULT NULL,
  `hsn_code`            VARCHAR(12) NULL DEFAULT NULL,
  `purchase_price`      DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `mrp`                 DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sale_price`          DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `qty`                 DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `free_qty`            DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `disc_pct`            DECIMAL(7,3) NOT NULL DEFAULT 0.000,
  `disc_amt`            DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `disc_pct2`           DECIMAL(7,3) NOT NULL DEFAULT 0.000,
  `disc_amt2`           DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cgst_pct`            DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `sgst_pct`            DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `igst_pct`            DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `cess_pct`            DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `batch_no`            VARCHAR(60) NULL DEFAULT NULL,
  `mfg_date`            DATE NULL DEFAULT NULL,
  `exp_date`            DATE NULL DEFAULT NULL,
  `line_total`          DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pii_invoice` (`purchase_invoice_id`),
  KEY `idx_pii_variant` (`variant_id`),
  CONSTRAINT `fk_pii_invoice` FOREIGN KEY (`purchase_invoice_id`) REFERENCES `purchase_invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'purchase-intake migration applied' AS result;
