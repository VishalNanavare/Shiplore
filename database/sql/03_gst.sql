-- =====================================================================
-- File 03: GST TABLES (Indian GST)
-- HSN/SAC, tax classes/rates, gst_config, transactional tax breakups,
-- place-of-supply log, TCS/TDS, GSTR exports.
-- =====================================================================
USE `test`;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `hsn_sac_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(8) NOT NULL,
  `type` ENUM('HSN','SAC') NOT NULL DEFAULT 'HSN',
  `description` VARCHAR(255) NOT NULL,
  `default_tax_class_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hsn_code` (`code`),
  KEY `idx_hsn_type` (`type`),
  KEY `idx_hsn_taxclass` (`default_tax_class_id`),
  CONSTRAINT `fk_hsn_taxclass` FOREIGN KEY (`default_tax_class_id`) REFERENCES `tax_classes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tax_classes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `description` VARCHAR(255) NULL DEFAULT NULL,
  `is_exempt` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tax_classes_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tax_rates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tax_class_id` BIGINT UNSIGNED NOT NULL,
  `cgst` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `sgst` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `igst` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `cess` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `valid_from` DATE NOT NULL,
  `valid_to` DATE NULL DEFAULT NULL,
  `status` ENUM('active','superseded') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tax_rates` (`tax_class_id`,`valid_from`),
  KEY `idx_tax_rates_class_valid` (`tax_class_id`,`valid_from`,`valid_to`),
  CONSTRAINT `fk_tax_rates_class` FOREIGN KEY (`tax_class_id`) REFERENCES `tax_classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tax_class_hsn_map` (
  `tax_class_id` BIGINT UNSIGNED NOT NULL,
  `hsn_sac_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tax_class_id`,`hsn_sac_id`),
  KEY `idx_tchm_hsn` (`hsn_sac_id`),
  CONSTRAINT `fk_tchm_class` FOREIGN KEY (`tax_class_id`) REFERENCES `tax_classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tchm_hsn` FOREIGN KEY (`hsn_sac_id`) REFERENCES `hsn_sac_codes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gst_config` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope_type` ENUM('system','vendor','shop') NOT NULL DEFAULT 'system',
  `scope_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `rounding_mode` ENUM('line','invoice') NOT NULL DEFAULT 'line',
  `composition` TINYINT(1) NOT NULL DEFAULT 0,
  `rcm_default` TINYINT(1) NOT NULL DEFAULT 0,
  `einvoice_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `invoice_series_format` VARCHAR(80) NOT NULL DEFAULT 'INV/{FY}/{SEQ}',
  `fy_start_month` TINYINT UNSIGNED NOT NULL DEFAULT 4,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gst_config` (`scope_type`,`scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transactional tax breakups (rate-wise summaries; reference tables in 04/05).

CREATE TABLE `order_taxes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sub_order_id` BIGINT UNSIGNED NOT NULL,
  `hsn_snapshot` VARCHAR(8) NULL DEFAULT NULL,
  `tax_rate` DECIMAL(5,2) NOT NULL,
  `taxable_value` DECIMAL(15,4) NOT NULL,
  `cgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `place_of_supply` CHAR(2) NOT NULL,
  `supply_type` ENUM('intra','inter') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_taxes_suborder` (`sub_order_id`),
  KEY `idx_order_taxes_rate` (`tax_rate`),
  CONSTRAINT `fk_order_taxes_suborder` FOREIGN KEY (`sub_order_id`) REFERENCES `sub_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_taxes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  `hsn` VARCHAR(8) NULL DEFAULT NULL,
  `tax_rate` DECIMAL(5,2) NOT NULL,
  `taxable_value` DECIMAL(15,4) NOT NULL,
  `cgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `supply_type` ENUM('intra','inter') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_taxes_invoice` (`invoice_id`),
  CONSTRAINT `fk_invoice_taxes_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pos_sale_taxes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pos_sale_id` BIGINT UNSIGNED NOT NULL,
  `hsn` VARCHAR(8) NULL DEFAULT NULL,
  `tax_rate` DECIMAL(5,2) NOT NULL,
  `taxable_value` DECIMAL(15,4) NOT NULL,
  `cgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `supply_type` ENUM('intra','inter') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pos_sale_taxes_sale` (`pos_sale_id`),
  CONSTRAINT `fk_pos_sale_taxes_sale` FOREIGN KEY (`pos_sale_id`) REFERENCES `pos_sales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `credit_note_taxes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `credit_note_id` BIGINT UNSIGNED NOT NULL,
  `hsn` VARCHAR(8) NULL DEFAULT NULL,
  `tax_rate` DECIMAL(5,2) NOT NULL,
  `taxable_value` DECIMAL(15,4) NOT NULL,
  `cgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cn_taxes_cn` (`credit_note_id`),
  CONSTRAINT `fk_cn_taxes_cn` FOREIGN KEY (`credit_note_id`) REFERENCES `credit_notes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `place_of_supply_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `doc_type` ENUM('order','pos_sale','credit_note') NOT NULL,
  `doc_id` BIGINT UNSIGNED NOT NULL,
  `doc_uuid` CHAR(36) NULL DEFAULT NULL,
  `supplier_state` CHAR(2) NOT NULL,
  `pos_state` CHAR(2) NOT NULL,
  `supply_type` ENUM('intra','inter') NOT NULL,
  `channel` ENUM('online','pos') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pos_log_doc` (`doc_type`,`doc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tcs_tds_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `settlement_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `type` ENUM('tcs','tds') NOT NULL DEFAULT 'tcs',
  `net_taxable_supplies` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `reported_in` VARCHAR(20) NULL DEFAULT NULL,
  `status` ENUM('computed','deducted','reported','deposited') NOT NULL DEFAULT 'computed',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tcs_vendor_period` (`vendor_id`,`period_end`),
  KEY `idx_tcs_type` (`type`,`status`),
  KEY `idx_tcs_settlement` (`settlement_id`),
  CONSTRAINT `fk_tcs_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tcs_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gstr_exports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `type` ENUM('GSTR1','GSTR3B','GSTR8','HSN_SUMMARY') NOT NULL,
  `scope_type` ENUM('platform','vendor') NOT NULL DEFAULT 'platform',
  `vendor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `period` CHAR(6) NOT NULL,
  `file_media_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `format` ENUM('json','csv','xlsx') NOT NULL DEFAULT 'json',
  `status` ENUM('queued','generating','ready','failed') NOT NULL DEFAULT 'queued',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gstr_uuid` (`uuid`),
  UNIQUE KEY `uq_gstr` (`type`,`scope_type`,`vendor_id`,`period`),
  KEY `idx_gstr_period` (`period`),
  KEY `idx_gstr_vendor` (`vendor_id`),
  KEY `idx_gstr_media` (`file_media_id`),
  CONSTRAINT `fk_gstr_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_gstr_media` FOREIGN KEY (`file_media_id`) REFERENCES `media_assets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- End of 03_gst.sql
