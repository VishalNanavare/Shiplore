-- =====================================================================
-- File 06: SYNC TABLES (offline POS sync + sessions/tokens)
-- pos_terminals, pos_sequence, sync_batches, sync_conflicts,
-- sync_entities, sync_cursors, sync_dead_letter, auth_tokens, sessions.
-- =====================================================================
USE `test`;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `pos_terminals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `shop_id` BIGINT UNSIGNED NOT NULL,
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
  UNIQUE KEY `uq_pos_terminals_uuid` (`uuid`),
  UNIQUE KEY `uq_pos_terminals_prefix` (`shop_id`,`invoice_prefix`),
  KEY `idx_pos_terminals_shop` (`shop_id`,`status`),
  KEY `idx_pos_terminals_vendor` (`vendor_id`),
  CONSTRAINT `fk_pos_terminals_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pos_sequence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `terminal_id` BIGINT UNSIGNED NOT NULL,
  `financial_year` CHAR(7) NOT NULL,
  `last_invoice_no` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pos_sequence` (`terminal_id`,`financial_year`),
  CONSTRAINT `fk_pos_sequence_terminal` FOREIGN KEY (`terminal_id`) REFERENCES `pos_terminals` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sync_batches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `terminal_id` BIGINT UNSIGNED NOT NULL,
  `direction` ENUM('push','pull') NOT NULL,
  `anchor_from` BIGINT UNSIGNED NULL DEFAULT NULL,
  `anchor_to` BIGINT UNSIGNED NULL DEFAULT NULL,
  `item_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `idempotency_key` VARCHAR(64) NOT NULL,
  `received_at` DATETIME NULL DEFAULT NULL,
  `processed_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('received','processing','applied','partial','failed') NOT NULL DEFAULT 'received',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sync_batches_uuid` (`uuid`),
  UNIQUE KEY `uq_sync_batches_idem` (`idempotency_key`),
  KEY `idx_sync_batches_terminal` (`terminal_id`,`direction`,`status`),
  CONSTRAINT `fk_sync_batches_terminal` FOREIGN KEY (`terminal_id`) REFERENCES `pos_terminals` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sync_conflicts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `terminal_id` BIGINT UNSIGNED NOT NULL,
  `entity_type` VARCHAR(40) NOT NULL,
  `entity_uuid` CHAR(36) NOT NULL,
  `conflict_type` ENUM('oversell','price_drift','tax_mismatch','duplicate','dependency','version') NOT NULL,
  `server_version` INT UNSIGNED NULL DEFAULT NULL,
  `client_version` INT UNSIGNED NULL DEFAULT NULL,
  `payload_server` JSON NULL,
  `payload_client` JSON NULL,
  `resolution` ENUM('server_wins','client_wins','recorded_flagged','merged','manual') NULL DEFAULT NULL,
  `resolved_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `resolved_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('open','resolved','escalated') NOT NULL DEFAULT 'open',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sync_conflicts_uuid` (`uuid`),
  KEY `idx_sync_conflicts_entity` (`entity_type`,`entity_uuid`),
  KEY `idx_sync_conflicts_status` (`status`,`conflict_type`),
  KEY `idx_sync_conflicts_terminal` (`terminal_id`),
  CONSTRAINT `fk_sync_conflicts_terminal` FOREIGN KEY (`terminal_id`) REFERENCES `pos_terminals` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sync_conflicts_resolver` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sync_entities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `entity_type` VARCHAR(40) NOT NULL,
  `entity_uuid` CHAR(36) NOT NULL,
  `op` ENUM('insert','update','delete') NOT NULL,
  `payload` JSON NOT NULL,
  `server_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `result` ENUM('applied','conflict','skipped','deferred') NOT NULL DEFAULT 'applied',
  `conflict_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `error` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sync_entities` (`entity_type`,`entity_uuid`,`batch_id`),
  KEY `idx_sync_entities_batch` (`batch_id`),
  KEY `idx_sync_entities_entity` (`entity_type`,`entity_uuid`),
  KEY `idx_sync_entities_result` (`result`),
  KEY `idx_sync_entities_conflict` (`conflict_id`),
  CONSTRAINT `fk_sync_entities_batch` FOREIGN KEY (`batch_id`) REFERENCES `sync_batches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sync_entities_conflict` FOREIGN KEY (`conflict_id`) REFERENCES `sync_conflicts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sync_cursors` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `terminal_id` BIGINT UNSIGNED NOT NULL,
  `entity_type` VARCHAR(40) NOT NULL,
  `last_anchor` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_pulled_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sync_cursors` (`terminal_id`,`entity_type`),
  KEY `idx_sync_cursors_terminal` (`terminal_id`),
  CONSTRAINT `fk_sync_cursors_terminal` FOREIGN KEY (`terminal_id`) REFERENCES `pos_terminals` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sync_dead_letter` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `terminal_id` BIGINT UNSIGNED NOT NULL,
  `entity_type` VARCHAR(40) NOT NULL,
  `entity_uuid` CHAR(36) NOT NULL,
  `payload` JSON NULL,
  `reason` VARCHAR(255) NULL DEFAULT NULL,
  `attempts` INT NOT NULL DEFAULT 0,
  `status` ENUM('open','retried','resolved','discarded') NOT NULL DEFAULT 'open',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sync_dead_letter_uuid` (`uuid`),
  KEY `idx_sync_dead_letter_terminal` (`terminal_id`,`status`),
  CONSTRAINT `fk_sync_dead_letter_terminal` FOREIGN KEY (`terminal_id`) REFERENCES `pos_terminals` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `auth_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `device_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `family_id` CHAR(36) NOT NULL,
  `rotated_from` BIGINT UNSIGNED NULL DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `revoked_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('active','rotated','revoked','expired') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_tokens_hash` (`token_hash`),
  KEY `idx_auth_tokens_user` (`user_id`),
  KEY `idx_auth_tokens_family` (`family_id`),
  KEY `idx_auth_tokens_device` (`device_id`),
  CONSTRAINT `fk_auth_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_auth_tokens_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `device_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `ip` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  `last_active_at` DATETIME NULL DEFAULT NULL,
  `revoked_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sessions_user` (`user_id`,`status`),
  KEY `idx_sessions_device` (`device_id`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sessions_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- End of 06_sync.sql
