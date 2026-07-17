-- =====================================================================
-- 30_governance.sql  |  Phase X1 — universal change-request engine.
-- The staff→vendor(→admin) approval spine: change_requests envelope +
-- per-level decisions; wires the previously-unused approval_rules into a
-- routing matrix; extends audit_logs with the approval-grade fields
-- (device, approval level, reason).
--
-- Idempotent. DELIMITER-free (PREPARE-guarded ALTERs) so it can be applied
-- through any client, including mysqli::multi_query.
--
-- @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §1
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- A. change_requests — the universal request envelope
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `change_requests` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`            CHAR(36) NOT NULL,
  `request_no`      VARCHAR(30) NOT NULL,
  `entity_type`     VARCHAR(40) NOT NULL,
  `entity_id`       BIGINT UNSIGNED NULL DEFAULT NULL,
  `action`          VARCHAR(40) NOT NULL,
  `field_group`     VARCHAR(40) NOT NULL DEFAULT 'default',
  `payload_old`     JSON NULL,
  `payload_new`     JSON NULL,
  `reason`          VARCHAR(255) NULL DEFAULT NULL,
  `requested_by`    BIGINT UNSIGNED NOT NULL,
  `requester_role`  VARCHAR(40) NOT NULL,
  `vendor_id`       BIGINT UNSIGNED NULL DEFAULT NULL,
  `shop_id`         BIGINT UNSIGNED NULL DEFAULT NULL,
  `current_level`   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `required_levels` JSON NOT NULL,
  `status`          ENUM('draft','pending_l1','pending_l2','changes_requested','approved','applied','apply_failed','rejected','withdrawn','expired') NOT NULL DEFAULT 'pending_l1',
  `apply_error`     VARCHAR(255) NULL DEFAULT NULL,
  `sla_due_at`      DATETIME NULL DEFAULT NULL,
  `applied_at`      DATETIME NULL DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_change_requests_uuid` (`uuid`),
  UNIQUE KEY `uq_change_requests_no` (`request_no`),
  KEY `idx_chreq_status_vendor` (`status`,`vendor_id`),
  KEY `idx_chreq_entity` (`entity_type`,`entity_id`,`status`),
  KEY `idx_chreq_sla` (`status`,`sla_due_at`),
  CONSTRAINT `fk_chreq_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_chreq_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_chreq_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- B. change_request_approvals — one row per level decision
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `change_request_approvals` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `change_request_id` BIGINT UNSIGNED NOT NULL,
  `level_no`          TINYINT UNSIGNED NOT NULL,
  `approver_role`     VARCHAR(40) NOT NULL,
  `approver_user_id`  BIGINT UNSIGNED NULL DEFAULT NULL,
  `decision`          ENUM('approved','rejected','changes_requested') NOT NULL,
  `reason`            VARCHAR(255) NULL DEFAULT NULL,
  `ip`                VARCHAR(45) NULL DEFAULT NULL,
  `device_id`         BIGINT UNSIGNED NULL DEFAULT NULL,
  `decided_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chreq_appr_request` (`change_request_id`,`level_no`),
  CONSTRAINT `fk_chreq_appr_request` FOREIGN KEY (`change_request_id`) REFERENCES `change_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_chreq_appr_user` FOREIGN KEY (`approver_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_chreq_appr_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- C. approval_rules — gains the routing dimensions (entity/action/levels)
--    PREPARE-guarded so the file stays re-runnable without DELIMITER.
-- ---------------------------------------------------------------------
SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `approval_rules` ADD COLUMN `entity_type` VARCHAR(40) NULL DEFAULT NULL AFTER `id`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'approval_rules' AND COLUMN_NAME = 'entity_type');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `approval_rules` ADD COLUMN `action` VARCHAR(40) NULL DEFAULT NULL AFTER `entity_type`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'approval_rules' AND COLUMN_NAME = 'action');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `approval_rules` ADD COLUMN `levels` JSON NULL AFTER `action`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'approval_rules' AND COLUMN_NAME = 'levels');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `approval_rules` ADD KEY `idx_approval_rules_route` (`entity_type`,`action`)',
  'SELECT 1') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'approval_rules' AND INDEX_NAME = 'idx_approval_rules_route');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ---------------------------------------------------------------------
-- D. audit_logs — approval-grade fields (device, level, reason)
-- ---------------------------------------------------------------------
SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `audit_logs` ADD COLUMN `device_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `user_agent`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND COLUMN_NAME = 'device_id');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `audit_logs` ADD COLUMN `approval_level` TINYINT UNSIGNED NULL DEFAULT NULL AFTER `step_up_token_id`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND COLUMN_NAME = 'approval_level');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `audit_logs` ADD COLUMN `reason` VARCHAR(255) NULL DEFAULT NULL AFTER `approval_level`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND COLUMN_NAME = 'reason');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET FOREIGN_KEY_CHECKS = 1;
