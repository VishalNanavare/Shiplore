-- =====================================================================
-- 79_manufacturer_pos.sql
--
-- Factory-outlet POS for the manufacturer panel.
--
-- PARALLEL TABLES, NOT THE EXISTING pos_* ONES. Three reasons, in order of weight:
--
--  1. `pos_terminals.shop_id` carries an FK to `shops(id)` (06_sync.sql) and
--     `pos_sales.terminal_id` FKs pos_terminals, so an mshop is transitively locked
--     out of the whole existing chain.
--  2. PosSaleRepository is bound to shop_id far beyond that FK: it inner-joins
--     `product_shops`, left-joins `inventory`, joins `shops`, and derives the invoice
--     sequence from pos_sales.shop_id. For a manufacturer each of those becomes
--     product_mshops / mfg_inventory / mshops. It is a different query, not a
--     different id.
--  3. `api/v1/pos/*` is the sync path for ALREADY-SHIPPED mobile and Windows POS
--     clients that cannot be updated in lockstep. Nothing here touches a table,
--     column or index those clients read.
--
-- Idempotent. DELIMITER-free and PREPARE-free: CREATE TABLE IF NOT EXISTS is already
-- idempotent, and no ALTER is needed because every table is new. Applies through
-- database/apply_sql.php, phpMyAdmin, or any client.
-- =====================================================================

-- ---------------------------------------------------------------------
-- A. Terminals.
--
-- Written now so no ALTER is needed if a device client ever arrives, but the WEB
-- POS does not use it: it writes NULL to terminal_id and never inserts here. That is
-- also why mfg_pos_shifts and mfg_pos_sales carry their own mshop_id — pos_shifts has
-- no shop column at all and can only reach tenancy through terminal_id, which is
-- useless when terminal_id is NULL.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mfg_pos_terminals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `mshop_id` BIGINT UNSIGNED NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `device_fingerprint` VARCHAR(191) NULL DEFAULT NULL,
  `invoice_prefix` VARCHAR(20) NOT NULL,
  `activation_code` CHAR(64) NULL DEFAULT NULL,
  `last_sync_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('inactive','active','suspended','revoked') NOT NULL DEFAULT 'inactive',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mfg_pos_terminals_uuid` (`uuid`),
  UNIQUE KEY `uq_mfg_pos_terminals_prefix` (`mshop_id`,`invoice_prefix`),
  KEY `idx_mfg_pos_terminals_vendor` (`vendor_id`),
  CONSTRAINT `fk_mfg_pos_term_mshop` FOREIGN KEY (`mshop_id`) REFERENCES `mshops` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  -- pos_terminals leaves vendor_id unconstrained; constraining it here is free and correct.
  CONSTRAINT `fk_mfg_pos_term_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- B. Shifts. Unused by the web path in v1 (shift_id is NULL) but defined now for
--    the same reason as terminals.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mfg_pos_shifts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `terminal_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `mshop_id` BIGINT UNSIGNED NOT NULL,
  `cashier_user_id` BIGINT UNSIGNED NOT NULL,
  `opened_at` DATETIME NOT NULL,
  `closed_at` DATETIME NULL DEFAULT NULL,
  `opening_float` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `closing_cash` DECIMAL(15,4) NULL DEFAULT NULL,
  `expected_cash` DECIMAL(15,4) NULL DEFAULT NULL,
  `variance` DECIMAL(15,4) NULL DEFAULT NULL,
  `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mfg_pos_shifts_uuid` (`uuid`),
  KEY `idx_mfg_pos_shifts_mshop` (`mshop_id`,`status`),
  CONSTRAINT `fk_mfg_pos_shift_term` FOREIGN KEY (`terminal_id`) REFERENCES `mfg_pos_terminals` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_mfg_pos_shift_mshop` FOREIGN KEY (`mshop_id`) REFERENCES `mshops` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_mfg_pos_shift_cashier` FOREIGN KEY (`cashier_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- C. Sales. Three deliberate divergences from `pos_sales`, each closing a defect
--    that table has:
--
--    - The column is `mshop_id` WITH a real FK. pos_sales.shop_id has no FK at all,
--      so an mshop id would sit undetected among real shop ids.
--    - `client_uuid` is its own column with a unique key SCOPED BY vendor_id. The
--      vendor idempotency lookup overloads offline_invoice_no and queries it with no
--      tenant predicate, so a colliding uuid returns another tenant's sale.
--    - `financial_year` + `seq_no` are stored and uniquely keyed, so the racy
--      count-based numbering cannot silently issue a duplicate invoice number.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mfg_pos_sales` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `terminal_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `shift_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `mshop_id` BIGINT UNSIGNED NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `cashier_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `customer_name` VARCHAR(100) NULL DEFAULT NULL,
  `customer_phone` VARCHAR(20) NULL DEFAULT NULL,
  `client_uuid` VARCHAR(60) NULL DEFAULT NULL,
  `invoice_no` VARCHAR(60) NULL DEFAULT NULL,
  `financial_year` CHAR(7) NOT NULL,
  `seq_no` BIGINT UNSIGNED NOT NULL,
  `subtotal` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `discount_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `taxable_value` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `round_off` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `grand_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `payment_method` VARCHAR(20) NOT NULL DEFAULT 'cash',
  `notes` TEXT NULL DEFAULT NULL,
  `sold_at` DATETIME NOT NULL,
  `status` ENUM('completed','void','returned') NOT NULL DEFAULT 'completed',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mfg_pos_sales_uuid` (`uuid`),
  UNIQUE KEY `uq_mfg_pos_sales_client` (`vendor_id`,`client_uuid`),
  UNIQUE KEY `uq_mfg_pos_sales_seq` (`mshop_id`,`financial_year`,`seq_no`),
  KEY `idx_mfg_pos_sales_sold` (`mshop_id`,`sold_at`),
  CONSTRAINT `fk_mfg_pos_sale_mshop` FOREIGN KEY (`mshop_id`) REFERENCES `mshops` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_mfg_pos_sale_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_mfg_pos_sale_term` FOREIGN KEY (`terminal_id`) REFERENCES `mfg_pos_terminals` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_mfg_pos_sale_shift` FOREIGN KEY (`shift_id`) REFERENCES `mfg_pos_shifts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_mfg_pos_sale_cashier` FOREIGN KEY (`cashier_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- D. Sale lines.
--
-- No `mrp_snapshot`: mrp is 0/unused for manufacturer products, so the vendor
-- receipt's "you saved" line would print the whole sale value as a discount. The
-- replacement is `making_price_snapshot`, which makes outlet margin reportable later
-- without a price-history join.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mfg_pos_sale_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `mfg_pos_sale_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `product_title_snapshot` VARCHAR(191) NOT NULL,
  `sku_snapshot` VARCHAR(64) NULL DEFAULT NULL,
  `hsn_snapshot` VARCHAR(8) NULL DEFAULT NULL,
  `qty` DECIMAL(15,3) NOT NULL,
  `unit_price` DECIMAL(15,4) NOT NULL,
  `making_price_snapshot` DECIMAL(15,4) NULL DEFAULT NULL,
  `discount_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `taxable_value` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `cgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `is_gst_inclusive` TINYINT(1) NOT NULL DEFAULT 0,
  `line_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `is_temporary` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active','returned') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mfg_pos_items_uuid` (`uuid`),
  KEY `idx_mfg_pos_items_sale` (`mfg_pos_sale_id`),
  CONSTRAINT `fk_mfg_pos_item_sale` FOREIGN KEY (`mfg_pos_sale_id`) REFERENCES `mfg_pos_sales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mfg_pos_item_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mfg_pos_sale_payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `mfg_pos_sale_id` BIGINT UNSIGNED NOT NULL,
  `tender_type` ENUM('cash','card','upi','wallet') NOT NULL,
  `amount` DECIMAL(15,4) NOT NULL,
  `reference` VARCHAR(120) NULL DEFAULT NULL,
  `status` ENUM('captured','void') NOT NULL DEFAULT 'captured',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mfg_pos_pay_uuid` (`uuid`),
  KEY `idx_mfg_pos_pay_sale` (`mfg_pos_sale_id`),
  CONSTRAINT `fk_mfg_pos_pay_sale` FOREIGN KEY (`mfg_pos_sale_id`) REFERENCES `mfg_pos_sales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- E. Invoice sequence, per unit per financial year.
--
-- The vendor scheme is `COUNT(*) + 1` over pos_sales: not FY-aware (so
-- INV-26-000500 rolls into INV-27-000501), void and returned sales consume numbers,
-- deleted rows reuse them, and it is racy with nothing enforcing uniqueness. A
-- counter row is locked by the UPDATE that increments it, and the UNIQUE key on
-- mfg_pos_sales(mshop_id, financial_year, seq_no) is the backstop if it is ever
-- bypassed: the insert fails loudly instead of issuing a duplicate.
--
-- Keyed on mshop_id, not terminal_id — the web POS has no terminal, which is
-- precisely why pos_sequence is unusable for it.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mfg_pos_sequence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mshop_id` BIGINT UNSIGNED NOT NULL,
  `financial_year` CHAR(7) NOT NULL,
  `last_invoice_no` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mfg_pos_seq` (`mshop_id`,`financial_year`),
  CONSTRAINT `fk_mfg_pos_seq_mshop` FOREIGN KEY (`mshop_id`) REFERENCES `mshops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No mfg_pos_sale_taxes: the GST rollup is derivable from mfg_pos_sale_items, each of
-- which carries hsn_snapshot + tax_rate + the four tax columns. Add a frozen rollup
-- table only when GSTR filing needs one.

-- ---------------------------------------------------------------------
-- F. Permissions. scope_class 'manufacturer'/'mshop' ONLY — 11_seed.sql bulk-grants
--    every permission with scope_class IN ('vendor','shop') to vendor_owner, so
--    either of those would hand the factory-outlet POS to every vendor on the
--    platform. mfg.pos.sell was already seeded by 76; this adds the read side.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`code`,`module`,`action`,`scope_class`,`description`) VALUES
 ('mfg.pos.view','mfg_pos','view','mshop','Open the factory-outlet POS and read its receipts');

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN ('mfg.pos.view','mfg.pos.sell')
 WHERE r.code IN ('manufacturer_owner','manufacturer_manager','manufacturer_unit_manager');
