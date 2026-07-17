-- =====================================================================
-- File 08: AUDIT TABLES (append-only, immutable, tamper-evident)
-- audit_logs, audit_log_details, login_attempts, audit_checkpoints,
-- data_access_logs.
-- These intentionally OMIT updated_at / deleted_at / created_by / updated_by:
-- rows are never updated or deleted. Production grants should exclude
-- UPDATE/DELETE on these tables. Polymorphic entity refs => no FK.
-- =====================================================================
USE `test`;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `actor_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `actor_principal_type` ENUM('platform','vendor','customer','rider','system','pos_device') NOT NULL DEFAULT 'system',
  `actor_role` VARCHAR(64) NULL DEFAULT NULL,
  `impersonated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `scope_type` ENUM('platform','vendor','shop','self') NOT NULL DEFAULT 'platform',
  `scope_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `action` VARCHAR(80) NOT NULL,
  `entity_type` VARCHAR(40) NOT NULL,
  `entity_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `entity_uuid` CHAR(36) NULL DEFAULT NULL,
  `request_id` VARCHAR(64) NULL DEFAULT NULL,
  `ip` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  `channel` ENUM('web','customer_app','vendor_app','rider_app','pos','api','system') NULL DEFAULT NULL,
  `step_up_token_id` VARCHAR(64) NULL DEFAULT NULL,
  `result` ENUM('success','failure','denied') NOT NULL DEFAULT 'success',
  `prev_hash` CHAR(64) NULL DEFAULT NULL,
  `row_hash` CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_audit_logs_uuid` (`uuid`),
  UNIQUE KEY `uq_audit_logs_row_hash` (`row_hash`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_actor` (`actor_user_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_scope` (`scope_type`,`scope_id`),
  KEY `idx_audit_request` (`request_id`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log_details` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `audit_log_id` BIGINT UNSIGNED NOT NULL,
  `before` JSON NULL,
  `after` JSON NULL,
  `metadata` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_audit_log_details_log` (`audit_log_id`),
  CONSTRAINT `fk_audit_log_details_log` FOREIGN KEY (`audit_log_id`) REFERENCES `audit_logs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(191) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `ip` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  `channel` VARCHAR(20) NULL DEFAULT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `failure_reason` VARCHAR(80) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_identifier` (`identifier`,`created_at`),
  KEY `idx_login_attempts_ip` (`ip`,`created_at`),
  KEY `idx_login_attempts_user` (`user_id`),
  KEY `idx_login_attempts_success` (`success`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_checkpoints` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period` CHAR(10) NOT NULL,
  `last_audit_id` BIGINT UNSIGNED NOT NULL,
  `root_hash` CHAR(64) NOT NULL,
  `anchored_ref` VARCHAR(191) NULL DEFAULT NULL,
  `verified_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_audit_checkpoints_period` (`period`),
  UNIQUE KEY `uq_audit_checkpoints_root` (`root_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `data_access_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `access_type` ENUM('view_pii','export','bulk_read','report') NOT NULL,
  `entity_type` VARCHAR(40) NOT NULL,
  `scope_type` ENUM('platform','vendor','shop','self') NULL DEFAULT NULL,
  `scope_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `record_count` INT UNSIGNED NULL DEFAULT NULL,
  `request_id` VARCHAR(64) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_data_access_actor` (`actor_user_id`,`created_at`),
  KEY `idx_data_access_type` (`access_type`),
  KEY `idx_data_access_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- End of 08_audit.sql
