-- =====================================================================
-- File 05: PAYMENT TABLES
-- payment methods/gateways, payments, attempts, webhooks, refunds,
-- reconciliations, wallets, wallet transactions/holds, double-entry ledger.
-- =====================================================================
USE `test`;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `payment_methods` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `channel` ENUM('online','pos','all') NOT NULL DEFAULT 'all',
  `is_prepaid` TINYINT(1) NOT NULL DEFAULT 1,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_methods_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gateway_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` ENUM('razorpay','payu','stripe','upi_psp','payout') NOT NULL,
  `owner_type` ENUM('platform','vendor') NOT NULL DEFAULT 'platform',
  `owner_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `channel` ENUM('online','pos','payout') NOT NULL DEFAULT 'online',
  `key_ref` VARCHAR(120) NOT NULL,
  `webhook_secret_ref` VARCHAR(120) NOT NULL,
  `config` JSON NULL,
  `priority` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive','sandbox') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_gateway_provider` (`provider`,`channel`,`status`),
  KEY `idx_gateway_owner` (`owner_type`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `sub_order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `pos_sale_uuid` CHAR(36) NULL DEFAULT NULL,
  `gateway` VARCHAR(40) NULL DEFAULT NULL,
  `gateway_ref` VARCHAR(120) NULL DEFAULT NULL,
  `method` VARCHAR(40) NOT NULL,
  `amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `idempotency_key` VARCHAR(64) NOT NULL,
  `captured_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('created','authorized','captured','failed','refunded','partially_refunded') NOT NULL DEFAULT 'created',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_uuid` (`uuid`),
  UNIQUE KEY `uq_payments_idem` (`idempotency_key`),
  KEY `idx_payments_order` (`order_id`),
  KEY `idx_payments_suborder` (`sub_order_id`),
  KEY `idx_payments_gateway_ref` (`gateway_ref`),
  KEY `idx_payments_status` (`status`),
  KEY `idx_payments_possale` (`pos_sale_uuid`),
  CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_suborder` FOREIGN KEY (`sub_order_id`) REFERENCES `sub_orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id` BIGINT UNSIGNED NOT NULL,
  `gateway` VARCHAR(40) NULL DEFAULT NULL,
  `attempt_no` INT NOT NULL DEFAULT 1,
  `request` JSON NULL,
  `response` JSON NULL,
  `error_code` VARCHAR(80) NULL DEFAULT NULL,
  `status` ENUM('initiated','success','failed') NOT NULL DEFAULT 'initiated',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payment_attempts_payment` (`payment_id`),
  CONSTRAINT `fk_payment_attempts_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_webhooks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` VARCHAR(40) NOT NULL,
  `event_id` VARCHAR(120) NOT NULL,
  `payment_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `signature` VARCHAR(255) NULL DEFAULT NULL,
  `payload` JSON NULL,
  `verified` TINYINT(1) NOT NULL DEFAULT 0,
  `processed_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('received','verified','processed','rejected','duplicate') NOT NULL DEFAULT 'received',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_webhooks` (`provider`,`event_id`),
  KEY `idx_payment_webhooks_status` (`status`),
  KEY `idx_payment_webhooks_payment` (`payment_id`),
  CONSTRAINT `fk_payment_webhooks_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `refunds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `payment_id` BIGINT UNSIGNED NOT NULL,
  `return_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `destination` ENUM('source','wallet','cash') NOT NULL DEFAULT 'source',
  `gateway_ref` VARCHAR(120) NULL DEFAULT NULL,
  `idempotency_key` VARCHAR(64) NOT NULL,
  `status` ENUM('initiated','processing','completed','failed') NOT NULL DEFAULT 'initiated',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refunds_uuid` (`uuid`),
  UNIQUE KEY `uq_refunds_idem` (`idempotency_key`),
  KEY `idx_refunds_payment` (`payment_id`),
  KEY `idx_refunds_return` (`return_id`),
  KEY `idx_refunds_status` (`status`),
  CONSTRAINT `fk_refunds_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_refunds_return` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `reconciliations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `gateway` VARCHAR(40) NOT NULL,
  `settlement_file_ref` VARCHAR(191) NULL DEFAULT NULL,
  `payment_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `refund_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `expected_amount` DECIMAL(15,4) NULL DEFAULT NULL,
  `actual_amount` DECIMAL(15,4) NULL DEFAULT NULL,
  `variance` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `recon_date` DATE NULL DEFAULT NULL,
  `status` ENUM('matched','mismatch','missing','chargeback','resolved') NOT NULL DEFAULT 'matched',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reconciliations_uuid` (`uuid`),
  KEY `idx_recon_gateway_date` (`gateway`,`recon_date`),
  KEY `idx_recon_status` (`status`),
  KEY `idx_recon_payment` (`payment_id`),
  KEY `idx_recon_refund` (`refund_id`),
  CONSTRAINT `fk_recon_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_recon_refund` FOREIGN KEY (`refund_id`) REFERENCES `refunds` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `wallets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `owner_type` ENUM('customer','vendor') NOT NULL,
  `owner_id` BIGINT UNSIGNED NOT NULL,
  `balance` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `status` ENUM('active','frozen','closed') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wallets_uuid` (`uuid`),
  UNIQUE KEY `uq_wallets_owner` (`owner_type`,`owner_id`,`currency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `wallet_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `wallet_id` BIGINT UNSIGNED NOT NULL,
  `type` ENUM('credit','debit') NOT NULL,
  `amount` DECIMAL(15,4) NOT NULL,
  `balance_after` DECIMAL(15,4) NOT NULL,
  `ref_type` VARCHAR(40) NULL DEFAULT NULL,
  `ref_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `reason` VARCHAR(191) NULL DEFAULT NULL,
  `idempotency_key` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wallet_tx_uuid` (`uuid`),
  UNIQUE KEY `uq_wallet_tx_idem` (`idempotency_key`),
  KEY `idx_wallet_tx_wallet` (`wallet_id`,`created_at`),
  KEY `idx_wallet_tx_ref` (`ref_type`,`ref_id`),
  CONSTRAINT `fk_wallet_tx_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `wallet_holds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `wallet_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,4) NOT NULL,
  `ref_type` VARCHAR(40) NULL DEFAULT NULL,
  `ref_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `expires_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('held','released','captured') NOT NULL DEFAULT 'held',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wallet_holds_wallet` (`wallet_id`,`status`),
  CONSTRAINT `fk_wallet_holds_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ledger_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `type` ENUM('asset','liability','income','expense','equity') NOT NULL,
  `parent_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ledger_accounts_code` (`code`),
  KEY `idx_ledger_accounts_parent` (`parent_id`),
  CONSTRAINT `fk_ledger_accounts_parent` FOREIGN KEY (`parent_id`) REFERENCES `ledger_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ledger_entries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `txn_group_uuid` CHAR(36) NOT NULL,
  `ledger_account_id` BIGINT UNSIGNED NOT NULL,
  `direction` ENUM('debit','credit') NOT NULL,
  `amount` DECIMAL(15,4) NOT NULL,
  `ref_type` VARCHAR(40) NULL DEFAULT NULL,
  `ref_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `memo` VARCHAR(191) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ledger_entries_uuid` (`uuid`),
  KEY `idx_ledger_group` (`txn_group_uuid`),
  KEY `idx_ledger_account` (`ledger_account_id`,`created_at`),
  KEY `idx_ledger_ref` (`ref_type`,`ref_id`),
  CONSTRAINT `fk_ledger_entries_account` FOREIGN KEY (`ledger_account_id`) REFERENCES `ledger_accounts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- End of 05_payment.sql
