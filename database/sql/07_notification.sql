-- =====================================================================
-- File 07: NOTIFICATION TABLES
-- templates, in-app inbox, per-channel deliveries, FCM device tokens,
-- preferences, campaigns, campaign recipients.
-- =====================================================================
USE `test`;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `notification_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(80) NOT NULL,
  `channel` ENUM('push','sms','email','in_app','whatsapp') NOT NULL,
  `locale` VARCHAR(10) NOT NULL DEFAULT 'en',
  `subject` VARCHAR(191) NULL DEFAULT NULL,
  `body` TEXT NOT NULL,
  `variables` JSON NULL,
  `dlt_template_id` VARCHAR(40) NULL DEFAULT NULL,
  `category` ENUM('transactional','promotional') NOT NULL DEFAULT 'transactional',
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `status` ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notif_templates` (`code`,`channel`,`locale`,`version`),
  KEY `idx_notif_templates_code` (`code`,`channel`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `template_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `event_code` VARCHAR(80) NOT NULL,
  `title` VARCHAR(191) NULL DEFAULT NULL,
  `body` TEXT NULL,
  `data` JSON NULL,
  `category` ENUM('transactional','promotional') NOT NULL DEFAULT 'transactional',
  `read_at` DATETIME NULL DEFAULT NULL,
  `dedupe_key` VARCHAR(120) NULL DEFAULT NULL,
  `status` ENUM('created','queued','sent','read','failed') NOT NULL DEFAULT 'created',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notifications_uuid` (`uuid`),
  UNIQUE KEY `uq_notifications_dedupe` (`dedupe_key`),
  KEY `idx_notifications_user` (`user_id`,`read_at`),
  KEY `idx_notifications_event` (`event_code`),
  KEY `idx_notifications_template` (`template_id`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notifications_template` FOREIGN KEY (`template_id`) REFERENCES `notification_templates` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notification_deliveries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notification_id` BIGINT UNSIGNED NOT NULL,
  `channel` ENUM('push','sms','email','in_app','whatsapp') NOT NULL,
  `provider` VARCHAR(40) NULL DEFAULT NULL,
  `provider_msg_id` VARCHAR(120) NULL DEFAULT NULL,
  `error` VARCHAR(255) NULL DEFAULT NULL,
  `sent_at` DATETIME NULL DEFAULT NULL,
  `delivered_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('queued','sent','delivered','failed','bounced','read') NOT NULL DEFAULT 'queued',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_deliv_notif` (`notification_id`),
  KEY `idx_notif_deliv_status` (`status`),
  KEY `idx_notif_deliv_provider_msg` (`provider_msg_id`),
  CONSTRAINT `fk_notif_deliv_notif` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `device_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `device_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `platform` ENUM('android','ios','web') NOT NULL,
  `fcm_token` VARCHAR(255) NOT NULL,
  `app_version` VARCHAR(20) NULL DEFAULT NULL,
  `last_seen_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('active','stale','invalid') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_tokens_fcm` (`fcm_token`),
  KEY `idx_device_tokens_user` (`user_id`,`status`),
  KEY `idx_device_tokens_device` (`device_id`),
  CONSTRAINT `fk_device_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_device_tokens_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notification_preferences` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `category` ENUM('transactional','promotional') NOT NULL,
  `channel` ENUM('push','sms','email','in_app','whatsapp') NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `quiet_hours` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notif_prefs` (`user_id`,`category`,`channel`),
  KEY `idx_notif_prefs_user` (`user_id`),
  CONSTRAINT `fk_notif_prefs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `campaigns` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `template_id` BIGINT UNSIGNED NOT NULL,
  `segment_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `channels` JSON NOT NULL,
  `schedule_at` DATETIME NULL DEFAULT NULL,
  `throttle_per_min` INT UNSIGNED NULL DEFAULT NULL,
  `ab_variant` JSON NULL,
  `budget_cap` INT UNSIGNED NULL DEFAULT NULL,
  `sent_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `delivered_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('draft','scheduled','running','paused','completed','cancelled') NOT NULL DEFAULT 'draft',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_campaigns_uuid` (`uuid`),
  KEY `idx_campaigns_status` (`status`,`schedule_at`),
  KEY `idx_campaigns_template` (`template_id`),
  KEY `idx_campaigns_segment` (`segment_id`),
  CONSTRAINT `fk_campaigns_template` FOREIGN KEY (`template_id`) REFERENCES `notification_templates` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_campaigns_segment` FOREIGN KEY (`segment_id`) REFERENCES `customer_segments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `campaign_recipients` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `notification_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('targeted','sent','delivered','suppressed','failed') NOT NULL DEFAULT 'targeted',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_campaign_recipients` (`campaign_id`,`user_id`),
  KEY `idx_campaign_recipients_status` (`campaign_id`,`status`),
  KEY `idx_campaign_recipients_user` (`user_id`),
  CONSTRAINT `fk_campaign_recipients_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_campaign_recipients_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- End of 07_notification.sql
