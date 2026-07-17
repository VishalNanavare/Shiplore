-- =====================================================================
-- 15_support_backup.sql
-- Support / ticketing + backup-run tracking. Added to complete the two
-- Admin-Panel modules whose permission codes existed without backing tables.
-- Idempotent. Run AFTER 11_seed.sql (depends on permissions/roles/users).
-- InnoDB / utf8mb4, soft-delete + audit fields, FKs — same conventions as
-- the rest of the schema.
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- A. Support tickets
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `ticket_no` VARCHAR(40) NOT NULL,
  `requester_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `requester_type` ENUM('vendor','customer','staff','rider') NOT NULL DEFAULT 'customer',
  `vendor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `subject` VARCHAR(191) NOT NULL,
  `body` TEXT NULL,
  `category` VARCHAR(40) NULL DEFAULT NULL,
  `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status` ENUM('open','pending','resolved','closed') NOT NULL DEFAULT 'open',
  `assigned_to` BIGINT UNSIGNED NULL DEFAULT NULL,
  `last_reply_at` DATETIME NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tickets_uuid` (`uuid`),
  UNIQUE KEY `uq_tickets_no` (`ticket_no`),
  KEY `idx_tickets_status` (`status`,`priority`),
  KEY `idx_tickets_requester` (`requester_user_id`),
  KEY `idx_tickets_vendor` (`vendor_id`),
  KEY `idx_tickets_assignee` (`assigned_to`),
  CONSTRAINT `fk_tickets_requester` FOREIGN KEY (`requester_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tickets_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tickets_assignee` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` BIGINT UNSIGNED NOT NULL,
  `author_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `body` TEXT NOT NULL,
  `is_internal` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tmsg_ticket` (`ticket_id`),
  KEY `idx_tmsg_author` (`author_user_id`),
  CONSTRAINT `fk_tmsg_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tmsg_author` FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- B. Backup / restore run log
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `backup_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `type` ENUM('full','incremental','snapshot','restore') NOT NULL DEFAULT 'full',
  `scope` VARCHAR(40) NULL DEFAULT NULL,
  `status` ENUM('started','completed','failed') NOT NULL DEFAULT 'started',
  `size_bytes` BIGINT UNSIGNED NULL DEFAULT NULL,
  `location` VARCHAR(255) NULL DEFAULT NULL,
  `started_at` DATETIME NULL DEFAULT NULL,
  `finished_at` DATETIME NULL DEFAULT NULL,
  `notes` VARCHAR(255) NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_backup_uuid` (`uuid`),
  KEY `idx_backup_status` (`status`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- C. Permissions (support.* ; backup.view already exists) + role grants
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`code`,`module`,`action`,`description`,`scope_class`) VALUES
 ('support.view',    'support','view',   'View support tickets',    'platform'),
 ('support.respond', 'support','respond','Respond to support tickets','platform'),
 ('support.close',   'support','close',  'Close support tickets',   'platform');

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
   ON p.code IN ('support.view','support.respond','support.close','backup.view')
 WHERE r.code IN ('super_admin','platform_admin');

SET FOREIGN_KEY_CHECKS = 1;
