-- =====================================================================
-- File 02: CONFIGURATION TABLES
-- settings, feature flags, plans, commission/promotion rules,
-- approval/search config, delivery fees, integrations.
-- =====================================================================
USE `test`;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope_type` ENUM('system','vendor','shop') NOT NULL DEFAULT 'system',
  `scope_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `namespace` VARCHAR(40) NOT NULL,
  `key` VARCHAR(80) NOT NULL,
  `value` JSON NOT NULL,
  `value_type` ENUM('string','int','bool','json','decimal') NOT NULL DEFAULT 'string',
  `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings` (`scope_type`,`scope_id`,`namespace`,`key`),
  KEY `idx_settings_scope` (`scope_type`,`scope_id`,`namespace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `feature_flags` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(80) NOT NULL,
  `description` VARCHAR(191) NULL DEFAULT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `rollout_json` JSON NULL,
  `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_feature_flags_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scheduled_tasks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(80) NOT NULL,
  `cron_expr` VARCHAR(40) NOT NULL,
  `handler` VARCHAR(120) NOT NULL,
  `payload` JSON NULL,
  `last_run_at` DATETIME NULL DEFAULT NULL,
  `next_run_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('active','paused') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scheduled_tasks_code` (`code`),
  KEY `idx_scheduled_tasks_status` (`status`,`next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vendor_plans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `max_shops` INT UNSIGNED NULL DEFAULT NULL,
  `max_skus` INT UNSIGNED NULL DEFAULT NULL,
  `max_staff` INT UNSIGNED NULL DEFAULT NULL,
  `price` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `billing_cycle` ENUM('monthly','yearly','free') NOT NULL DEFAULT 'free',
  `features_json` JSON NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vendor_plans_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vendor_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `namespace` VARCHAR(40) NOT NULL,
  `key` VARCHAR(80) NOT NULL,
  `value` JSON NOT NULL,
  `value_type` ENUM('string','int','bool','json','decimal') NOT NULL DEFAULT 'string',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vendor_settings` (`vendor_id`,`namespace`,`key`),
  CONSTRAINT `fk_vendor_settings_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `shop_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `namespace` VARCHAR(40) NOT NULL,
  `key` VARCHAR(80) NOT NULL,
  `value` JSON NOT NULL,
  `value_type` ENUM('string','int','bool','json','decimal') NOT NULL DEFAULT 'string',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shop_settings` (`shop_id`,`namespace`,`key`),
  CONSTRAINT `fk_shop_settings_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `commission_plans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `type` ENUM('flat','category','tiered','fixed_per_order','hybrid') NOT NULL DEFAULT 'flat',
  `default_rate` DECIMAL(5,2) NULL DEFAULT NULL,
  `min_amount` DECIMAL(15,4) NULL DEFAULT NULL,
  `max_amount` DECIMAL(15,4) NULL DEFAULT NULL,
  `base` ENUM('pre_tax','post_tax') NOT NULL DEFAULT 'pre_tax',
  `valid_from` DATE NOT NULL,
  `valid_to` DATE NULL DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_commission_plans_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `commission_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `commission_plan_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `min_gmv` DECIMAL(15,4) NULL DEFAULT NULL,
  `max_gmv` DECIMAL(15,4) NULL DEFAULT NULL,
  `rate` DECIMAL(5,2) NOT NULL,
  `fixed_amount` DECIMAL(15,4) NULL DEFAULT NULL,
  `priority` INT NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comm_rules_plan` (`commission_plan_id`),
  KEY `idx_comm_rules_category` (`category_id`),
  CONSTRAINT `fk_comm_rules_plan` FOREIGN KEY (`commission_plan_id`) REFERENCES `commission_plans` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_comm_rules_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vendor_commission_overrides` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `rate` DECIMAL(5,2) NOT NULL,
  `valid_from` DATE NOT NULL,
  `valid_to` DATE NULL DEFAULT NULL,
  `status` ENUM('active','expired') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vco_vendor` (`vendor_id`),
  KEY `idx_vco_category` (`category_id`),
  CONSTRAINT `fk_vco_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_vco_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `promotions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `vendor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `name` VARCHAR(191) NOT NULL,
  `type` ENUM('percentage','flat','bogo','bundle','tiered','free_shipping') NOT NULL,
  `value` DECIMAL(15,4) NULL DEFAULT NULL,
  `conditions` JSON NULL,
  `stacking` JSON NULL,
  `funded_by` ENUM('vendor','platform') NOT NULL DEFAULT 'platform',
  `valid_from` DATETIME NOT NULL,
  `valid_to` DATETIME NULL DEFAULT NULL,
  `status` ENUM('draft','scheduled','active','paused','expired') NOT NULL DEFAULT 'draft',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_promotions_uuid` (`uuid`),
  KEY `idx_promotions_status` (`status`,`valid_from`),
  KEY `idx_promotions_vendor` (`vendor_id`),
  CONSTRAINT `fk_promotions_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `promotion_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `promotion_id` BIGINT UNSIGNED NOT NULL,
  `rule_type` VARCHAR(40) NOT NULL,
  `operator` VARCHAR(20) NULL DEFAULT NULL,
  `value` JSON NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_promotion_rules_promo` (`promotion_id`),
  CONSTRAINT `fk_promotion_rules_promo` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `coupons` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `promotion_id` BIGINT UNSIGNED NOT NULL,
  `code` VARCHAR(40) NOT NULL,
  `usage_limit` INT UNSIGNED NULL DEFAULT NULL,
  `per_user_limit` INT UNSIGNED NULL DEFAULT NULL,
  `used_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `valid_from` DATETIME NOT NULL,
  `valid_to` DATETIME NULL DEFAULT NULL,
  `status` ENUM('active','disabled','exhausted','expired') NOT NULL DEFAULT 'active',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coupons_code` (`code`),
  KEY `idx_coupons_status` (`status`),
  KEY `idx_coupons_promo` (`promotion_id`),
  CONSTRAINT `fk_coupons_promo` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cashback_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `trigger` ENUM('order','category','campaign') NOT NULL DEFAULT 'order',
  `rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `max_cashback` DECIMAL(15,4) NULL DEFAULT NULL,
  `expiry_days` INT NULL DEFAULT NULL,
  `valid_from` DATETIME NULL DEFAULT NULL,
  `valid_to` DATETIME NULL DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `vendor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `auto_approve` TINYINT(1) NOT NULL DEFAULT 0,
  `sla_hours` INT UNSIGNED NULL DEFAULT NULL,
  `sensitive_fields` JSON NULL,
  `priority` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_approval_rules_category` (`category_id`),
  KEY `idx_approval_rules_vendor` (`vendor_id`),
  CONSTRAINT `fk_approval_rules_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_approval_rules_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rejection_reasons` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL,
  `label` VARCHAR(191) NOT NULL,
  `applies_to` ENUM('product','kyc','return') NOT NULL DEFAULT 'product',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rejection_reasons_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `search_synonyms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `term` VARCHAR(120) NOT NULL,
  `synonyms` JSON NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_search_synonyms_term` (`term`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `delivery_fees` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_zone_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `min_distance_km` DECIMAL(6,2) NULL DEFAULT NULL,
  `max_distance_km` DECIMAL(6,2) NULL DEFAULT NULL,
  `min_order_value` DECIMAL(15,4) NULL DEFAULT NULL,
  `base_fee` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `per_km_fee` DECIMAL(15,4) NULL DEFAULT NULL,
  `tax_class_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_delivery_fees_zone` (`delivery_zone_id`),
  KEY `idx_delivery_fees_tax` (`tax_class_id`),
  CONSTRAINT `fk_delivery_fees_zone` FOREIGN KEY (`delivery_zone_id`) REFERENCES `delivery_zones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_delivery_fees_tax` FOREIGN KEY (`tax_class_id`) REFERENCES `tax_classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `webhook_subscriptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_type` ENUM('vendor','integration','platform') NOT NULL DEFAULT 'platform',
  `owner_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `event` VARCHAR(80) NOT NULL,
  `target_url` VARCHAR(255) NOT NULL,
  `secret_ref` VARCHAR(120) NOT NULL,
  `status` ENUM('active','paused','failing') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_websub_event` (`event`,`status`),
  KEY `idx_websub_owner` (`owner_type`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `integration_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` VARCHAR(40) NOT NULL,
  `owner_type` ENUM('vendor','platform') NOT NULL DEFAULT 'platform',
  `owner_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `config` JSON NULL,
  `credentials_ref` VARCHAR(120) NULL DEFAULT NULL,
  `status` ENUM('connected','disconnected','error') NOT NULL DEFAULT 'disconnected',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_integration_accounts` (`provider`,`owner_type`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- End of 02_configuration.sql
