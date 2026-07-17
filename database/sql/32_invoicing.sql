-- =====================================================================
-- 32_invoicing.sql  |  Phase X2b — invoice numbering series + debit notes.
-- invoice_series issues gapless per-shop / per-FY / per-doc-type numbers
-- (call next() inside the caller's transaction). debit_notes mirrors
-- credit_notes for upward adjustments (doc 44 §12).
--
-- Idempotent, DELIMITER-free. Apply: php database/apply_sql.php database/sql/32_invoicing.sql
--
-- @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §11–12
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `invoice_series` (
  `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`   BIGINT UNSIGNED NULL DEFAULT NULL,
  `fy`        CHAR(7) NOT NULL,
  `doc_type`  ENUM('tax_invoice','bill_of_supply','credit_note','debit_note','pos_receipt') NOT NULL,
  `prefix`    VARCHAR(20) NULL DEFAULT NULL,
  `next_no`   INT UNSIGNED NOT NULL DEFAULT 1,
  `locked`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_series` (`shop_id`,`fy`,`doc_type`),
  CONSTRAINT `fk_invoice_series_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `debit_notes` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`          CHAR(36) NOT NULL,
  `sub_order_id`  BIGINT UNSIGNED NULL DEFAULT NULL,
  `vendor_id`     BIGINT UNSIGNED NOT NULL,
  `debit_note_no` VARCHAR(40) NOT NULL,
  `dn_date`       DATE NOT NULL,
  `reason`        VARCHAR(191) NULL DEFAULT NULL,
  `taxable_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cgst_total`    DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst_total`    DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst_total`    DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess_total`    DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `grand_total`   DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `media_id`      BIGINT UNSIGNED NULL DEFAULT NULL,
  `status`        ENUM('generated','sent','cancelled') NOT NULL DEFAULT 'generated',
  `created_by`    BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by`    BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_debit_notes_uuid` (`uuid`),
  UNIQUE KEY `uq_debit_notes_no` (`debit_note_no`),
  KEY `idx_debit_notes_vendor` (`vendor_id`),
  KEY `idx_debit_notes_suborder` (`sub_order_id`),
  CONSTRAINT `fk_debit_notes_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_debit_notes_suborder` FOREIGN KEY (`sub_order_id`) REFERENCES `sub_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_debit_notes_media` FOREIGN KEY (`media_id`) REFERENCES `media_assets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
