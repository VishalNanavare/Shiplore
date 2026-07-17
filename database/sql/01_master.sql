-- =====================================================================
-- File 01: MASTER TABLES
-- Identity & Access, Vendor, Shop & Geo, Catalog, Product, Pricing,
-- Media & Content, Loyalty & Engagement masters.
-- (FOREIGN_KEY_CHECKS already 0 from 00_init.)
-- Note: created_by/updated_by columns are indexed but intentionally NOT
-- FK-constrained to users (avoids making `users` a hub of ~300 FKs;
-- blame integrity enforced at app layer). Meaningful user relationships
-- (owner_user_id, reviewed_by, cashier_user_id, etc.) DO carry FKs.
-- =====================================================================
USE `test`;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- A. IDENTITY & ACCESS
-- ---------------------------------------------------------------------

CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `principal_type` ENUM('platform','vendor','customer','rider') NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `email` VARCHAR(191) NULL DEFAULT NULL,
  `phone` VARCHAR(20) NULL DEFAULT NULL,
  `password_hash` VARCHAR(255) NULL DEFAULT NULL,
  `email_verified_at` DATETIME NULL DEFAULT NULL,
  `phone_verified_at` DATETIME NULL DEFAULT NULL,
  `avatar_media_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `default_locale` VARCHAR(10) NULL DEFAULT NULL,
  `last_login_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('invited','active','suspended','deactivated') NOT NULL DEFAULT 'active',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_uuid` (`uuid`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_phone` (`phone`),
  KEY `idx_users_principal_status` (`principal_type`,`status`),
  KEY `idx_users_avatar` (`avatar_media_id`),
  CONSTRAINT `fk_users_avatar` FOREIGN KEY (`avatar_media_id`) REFERENCES `media_assets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `auth_identities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `provider` ENUM('password','phone_otp','email_otp','google') NOT NULL,
  `identifier` VARCHAR(191) NOT NULL,
  `secret_hash` VARCHAR(255) NULL DEFAULT NULL,
  `verified_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_identities_provider_ident` (`provider`,`identifier`),
  KEY `idx_auth_identities_user` (`user_id`),
  CONSTRAINT `fk_auth_identities_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `auth_otp` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(191) NOT NULL,
  `code_hash` CHAR(64) NOT NULL,
  `purpose` ENUM('login','reset','step_up','verify') NOT NULL,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `consumed_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_auth_otp_identifier` (`identifier`,`purpose`),
  KEY `idx_auth_otp_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `permissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `module` VARCHAR(40) NOT NULL,
  `action` VARCHAR(40) NOT NULL,
  `description` VARCHAR(191) NULL DEFAULT NULL,
  `scope_class` ENUM('platform','vendor','shop','self') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_code` (`code`),
  KEY `idx_permissions_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `roles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `scope_class` ENUM('platform','vendor','shop','self') NOT NULL,
  `vendor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `attributes` JSON NULL,
  `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_code_vendor` (`code`,`vendor_id`),
  KEY `idx_roles_vendor` (`vendor_id`),
  CONSTRAINT `fk_roles_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `role_permissions` (
  `role_id` BIGINT UNSIGNED NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `idx_role_permissions_perm` (`permission_id`),
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_role_permissions_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_roles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role_id` BIGINT UNSIGNED NOT NULL,
  `scope_type` ENUM('platform','vendor','shop','self') NOT NULL,
  `scope_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `attributes` JSON NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_roles` (`user_id`,`role_id`,`scope_type`,`scope_id`),
  KEY `idx_user_roles_role` (`role_id`),
  KEY `idx_user_roles_scope` (`scope_type`,`scope_id`),
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `devices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `platform` ENUM('android','ios','web','pos') NOT NULL,
  `device_fingerprint` VARCHAR(191) NULL DEFAULT NULL,
  `app_version` VARCHAR(20) NULL DEFAULT NULL,
  `trusted` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active','revoked') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_devices_user` (`user_id`),
  CONSTRAINT `fk_devices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- B. VENDOR & CUSTOMER
-- ---------------------------------------------------------------------

CREATE TABLE `vendors` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `owner_user_id` BIGINT UNSIGNED NOT NULL,
  `legal_name` VARCHAR(191) NOT NULL,
  `display_name` VARCHAR(191) NOT NULL,
  `slug` VARCHAR(191) NOT NULL,
  `gstin` CHAR(15) NULL DEFAULT NULL,
  `pan` CHAR(10) NULL DEFAULT NULL,
  `state_code` CHAR(2) NULL DEFAULT NULL,
  `commission_plan_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `plan_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `payout_cycle` ENUM('daily','weekly','biweekly','monthly') NOT NULL DEFAULT 'weekly',
  `logo_media_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `is_composition` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('draft','submitted','under_review','approved','active','suspended','terminated') NOT NULL DEFAULT 'draft',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vendors_uuid` (`uuid`),
  UNIQUE KEY `uq_vendors_slug` (`slug`),
  UNIQUE KEY `uq_vendors_gstin` (`gstin`),
  KEY `idx_vendors_status` (`status`),
  KEY `idx_vendors_state` (`state_code`),
  KEY `idx_vendors_owner` (`owner_user_id`),
  KEY `idx_vendors_commission_plan` (`commission_plan_id`),
  KEY `idx_vendors_plan` (`plan_id`),
  CONSTRAINT `fk_vendors_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_vendors_commission_plan` FOREIGN KEY (`commission_plan_id`) REFERENCES `commission_plans` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_vendors_plan` FOREIGN KEY (`plan_id`) REFERENCES `vendor_plans` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_vendors_logo` FOREIGN KEY (`logo_media_id`) REFERENCES `media_assets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vendor_kyc` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `reviewed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `reviewed_at` DATETIME NULL DEFAULT NULL,
  `notes` TEXT NULL,
  `status` ENUM('pending','verified','rejected','changes_requested') NOT NULL DEFAULT 'pending',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vendor_kyc_vendor` (`vendor_id`),
  KEY `idx_vendor_kyc_reviewer` (`reviewed_by`),
  CONSTRAINT `fk_vendor_kyc_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_vendor_kyc_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vendor_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `doc_type` ENUM('gst','pan','bank','fssai','address','other') NOT NULL,
  `media_id` BIGINT UNSIGNED NOT NULL,
  `expires_at` DATE NULL DEFAULT NULL,
  `status` ENUM('uploaded','verified','rejected') NOT NULL DEFAULT 'uploaded',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vendor_documents_vendor` (`vendor_id`,`doc_type`),
  KEY `idx_vendor_documents_media` (`media_id`),
  CONSTRAINT `fk_vendor_documents_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_vendor_documents_media` FOREIGN KEY (`media_id`) REFERENCES `media_assets` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vendor_bank_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `account_no_enc` VARBINARY(255) NOT NULL,
  `account_last4` CHAR(4) NOT NULL,
  `ifsc` VARCHAR(11) NOT NULL,
  `holder_name` VARCHAR(191) NOT NULL,
  `verified_at` DATETIME NULL DEFAULT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('pending','verified','failed') NOT NULL DEFAULT 'pending',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vbank_vendor` (`vendor_id`),
  CONSTRAINT `fk_vbank_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vendor_plan_subscriptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `plan_id` BIGINT UNSIGNED NOT NULL,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vps_vendor` (`vendor_id`),
  KEY `idx_vps_plan` (`plan_id`),
  CONSTRAINT `fk_vps_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_vps_plan` FOREIGN KEY (`plan_id`) REFERENCES `vendor_plans` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `uuid` CHAR(36) NOT NULL,
  `default_address_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `loyalty_tier_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `lifetime_value` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `segment_cache` JSON NULL,
  `status` ENUM('active','blocked') NOT NULL DEFAULT 'active',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customers_user` (`user_id`),
  UNIQUE KEY `uq_customers_uuid` (`uuid`),
  KEY `idx_customers_default_addr` (`default_address_id`),
  KEY `idx_customers_tier` (`loyalty_tier_id`),
  CONSTRAINT `fk_customers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_customers_default_addr` FOREIGN KEY (`default_address_id`) REFERENCES `customer_addresses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_customers_tier` FOREIGN KEY (`loyalty_tier_id`) REFERENCES `loyalty_tiers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customer_addresses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `label` VARCHAR(40) NULL DEFAULT NULL,
  `line1` VARCHAR(191) NOT NULL,
  `line2` VARCHAR(191) NULL DEFAULT NULL,
  `city` VARCHAR(80) NOT NULL,
  `state_code` CHAR(2) NOT NULL,
  `pincode` VARCHAR(10) NOT NULL,
  `latitude` DECIMAL(10,7) NULL DEFAULT NULL,
  `longitude` DECIMAL(10,7) NULL DEFAULT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `uuid` CHAR(36) NOT NULL,
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_caddr_uuid` (`uuid`),
  KEY `idx_caddr_customer` (`customer_id`),
  KEY `idx_caddr_pincode` (`pincode`),
  KEY `idx_caddr_geo` (`latitude`,`longitude`),
  CONSTRAINT `fk_caddr_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customer_consents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `consent_type` ENUM('marketing','sms','whatsapp','email','data') NOT NULL,
  `granted` TINYINT(1) NOT NULL DEFAULT 0,
  `granted_at` DATETIME NULL DEFAULT NULL,
  `revoked_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_consents` (`customer_id`,`consent_type`),
  CONSTRAINT `fk_consents_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customer_segments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `definition` JSON NULL,
  `is_dynamic` TINYINT(1) NOT NULL DEFAULT 1,
  `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customer_segment_map` (
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `segment_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`customer_id`,`segment_id`),
  KEY `idx_csm_segment` (`segment_id`),
  CONSTRAINT `fk_csm_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_csm_segment` FOREIGN KEY (`segment_id`) REFERENCES `customer_segments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- C. SHOP & GEO
-- ---------------------------------------------------------------------

CREATE TABLE `shops` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `code` VARCHAR(64) NOT NULL,
  `gstin` CHAR(15) NULL DEFAULT NULL,
  `address_json` JSON NOT NULL,
  `pincode` VARCHAR(10) NOT NULL,
  `state_code` CHAR(2) NOT NULL,
  `latitude` DECIMAL(10,7) NOT NULL,
  `longitude` DECIMAL(10,7) NOT NULL,
  `delivery_radius_km` DECIMAL(6,2) NULL DEFAULT NULL,
  `delivery_polygon` JSON NULL,
  `pickup_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `prep_time_min` INT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('active','inactive','closed_temp') NOT NULL DEFAULT 'active',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shops_uuid` (`uuid`),
  UNIQUE KEY `uq_shops_vendor_code` (`vendor_id`,`code`),
  KEY `idx_shops_vendor` (`vendor_id`),
  KEY `idx_shops_geo` (`latitude`,`longitude`),
  KEY `idx_shops_pincode` (`pincode`),
  CONSTRAINT `fk_shops_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `shop_hours` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `day_of_week` TINYINT NOT NULL,
  `open_time` TIME NULL DEFAULT NULL,
  `close_time` TIME NULL DEFAULT NULL,
  `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shop_hours_shop` (`shop_id`),
  CONSTRAINT `fk_shop_hours_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `shop_holidays` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `holiday_date` DATE NOT NULL,
  `reason` VARCHAR(191) NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shop_holidays` (`shop_id`,`holiday_date`),
  CONSTRAINT `fk_shop_holidays_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `shop_serviceable_areas` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `area_type` ENUM('radius','polygon','pincode') NOT NULL,
  `value` JSON NOT NULL,
  `priority` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ssa_shop` (`shop_id`),
  CONSTRAINT `fk_ssa_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `serviceable_areas` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pincode` VARCHAR(10) NOT NULL,
  `is_serviceable` TINYINT(1) NOT NULL DEFAULT 1,
  `shop_ids` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_serviceable_pincode` (`pincode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `geo_locations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `query_hash` CHAR(64) NOT NULL,
  `formatted_address` VARCHAR(255) NULL DEFAULT NULL,
  `latitude` DECIMAL(10,7) NULL DEFAULT NULL,
  `longitude` DECIMAL(10,7) NULL DEFAULT NULL,
  `pincode` VARCHAR(10) NULL DEFAULT NULL,
  `state_code` CHAR(2) NULL DEFAULT NULL,
  `provider` VARCHAR(40) NULL DEFAULT NULL,
  `expires_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_geo_query` (`query_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pincodes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pincode` VARCHAR(10) NOT NULL,
  `city` VARCHAR(80) NULL DEFAULT NULL,
  `district` VARCHAR(80) NULL DEFAULT NULL,
  `state_code` CHAR(2) NULL DEFAULT NULL,
  `zone` VARCHAR(40) NULL DEFAULT NULL,
  `latitude` DECIMAL(10,7) NULL DEFAULT NULL,
  `longitude` DECIMAL(10,7) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pincodes_pincode` (`pincode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `delivery_zones` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `pincode_from` VARCHAR(10) NULL DEFAULT NULL,
  `pincode_to` VARCHAR(10) NULL DEFAULT NULL,
  `zone_type` ENUM('local','zonal','national') NOT NULL DEFAULT 'local',
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- D. CATALOG MASTERS
-- ---------------------------------------------------------------------

CREATE TABLE `categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `parent_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `name` VARCHAR(191) NOT NULL,
  `slug` VARCHAR(191) NOT NULL,
  `path` VARCHAR(255) NOT NULL,
  `level` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `default_tax_class_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `default_commission_rate` DECIMAL(5,2) NULL DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_uuid` (`uuid`),
  UNIQUE KEY `uq_categories_slug` (`slug`),
  KEY `idx_categories_parent` (`parent_id`),
  KEY `idx_categories_path` (`path`),
  KEY `idx_categories_tax` (`default_tax_class_id`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_categories_tax` FOREIGN KEY (`default_tax_class_id`) REFERENCES `tax_classes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `brands` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `slug` VARCHAR(191) NOT NULL,
  `logo_media_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('active','pending','inactive') NOT NULL DEFAULT 'active',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_brands_uuid` (`uuid`),
  UNIQUE KEY `uq_brands_slug` (`slug`),
  KEY `idx_brands_logo` (`logo_media_id`),
  CONSTRAINT `fk_brands_logo` FOREIGN KEY (`logo_media_id`) REFERENCES `media_assets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attributes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `type` ENUM('select','multiselect','text','number','boolean') NOT NULL,
  `is_variant_defining` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attributes_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attribute_values` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attribute_id` BIGINT UNSIGNED NOT NULL,
  `value` VARCHAR(191) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attr_values` (`attribute_id`,`value`),
  CONSTRAINT `fk_attr_values_attr` FOREIGN KEY (`attribute_id`) REFERENCES `attributes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attribute_sets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attribute_sets_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attribute_set_items` (
  `attribute_set_id` BIGINT UNSIGNED NOT NULL,
  `attribute_id` BIGINT UNSIGNED NOT NULL,
  `is_required` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`attribute_set_id`,`attribute_id`),
  KEY `idx_asi_attr` (`attribute_id`),
  CONSTRAINT `fk_asi_set` FOREIGN KEY (`attribute_set_id`) REFERENCES `attribute_sets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_asi_attr` FOREIGN KEY (`attribute_id`) REFERENCES `attributes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `category_attributes` (
  `category_id` BIGINT UNSIGNED NOT NULL,
  `attribute_id` BIGINT UNSIGNED NOT NULL,
  `is_filterable` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`category_id`,`attribute_id`),
  KEY `idx_catattr_attr` (`attribute_id`),
  CONSTRAINT `fk_catattr_cat` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_catattr_attr` FOREIGN KEY (`attribute_id`) REFERENCES `attributes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `units` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `base_unit_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `factor` DECIMAL(15,6) NOT NULL DEFAULT 1.000000,
  `allow_fraction` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_units_code` (`code`),
  KEY `idx_units_base` (`base_unit_id`),
  CONSTRAINT `fk_units_base` FOREIGN KEY (`base_unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `unit_conversions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_unit_id` BIGINT UNSIGNED NOT NULL,
  `to_unit_id` BIGINT UNSIGNED NOT NULL,
  `factor` DECIMAL(15,6) NOT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit_conv` (`from_unit_id`,`to_unit_id`),
  KEY `idx_unit_conv_to` (`to_unit_id`),
  CONSTRAINT `fk_unit_conv_from` FOREIGN KEY (`from_unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_unit_conv_to` FOREIGN KEY (`to_unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- E. PRODUCT MASTERS
-- ---------------------------------------------------------------------

CREATE TABLE `products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED NOT NULL,
  `brand_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `attribute_set_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `hsn_sac_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `tax_class_id` BIGINT UNSIGNED NOT NULL,
  `base_unit_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(191) NOT NULL,
  `slug` VARCHAR(191) NOT NULL,
  `description` LONGTEXT NULL,
  `is_pos_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `is_online_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `approval_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('draft','submitted','under_review','approved','rejected','published','unpublished') NOT NULL DEFAULT 'draft',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_uuid` (`uuid`),
  UNIQUE KEY `uq_products_slug` (`slug`),
  KEY `idx_products_vendor_status` (`vendor_id`,`status`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_brand` (`brand_id`),
  KEY `idx_products_tax` (`tax_class_id`),
  KEY `idx_products_hsn` (`hsn_sac_id`),
  KEY `idx_products_unit` (`base_unit_id`),
  KEY `idx_products_attrset` (`attribute_set_id`),
  FULLTEXT KEY `ft_products` (`title`,`description`),
  CONSTRAINT `fk_products_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_products_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_products_attrset` FOREIGN KEY (`attribute_set_id`) REFERENCES `attribute_sets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_products_hsn` FOREIGN KEY (`hsn_sac_id`) REFERENCES `hsn_sac_codes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_products_tax` FOREIGN KEY (`tax_class_id`) REFERENCES `tax_classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_products_unit` FOREIGN KEY (`base_unit_id`) REFERENCES `units` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_variants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `sku` VARCHAR(64) NOT NULL,
  `barcode` VARCHAR(64) NULL DEFAULT NULL,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  `mrp` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `base_price` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `weight_grams` DECIMAL(10,3) NULL DEFAULT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive','discontinued') NOT NULL DEFAULT 'active',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_variants_uuid` (`uuid`),
  UNIQUE KEY `uq_variants_sku` (`sku`),
  KEY `idx_variants_product` (`product_id`),
  KEY `idx_variants_vendor` (`vendor_id`),
  KEY `idx_variants_barcode` (`barcode`),
  KEY `idx_variants_unit` (`unit_id`),
  CONSTRAINT `fk_variants_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_variants_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_variants_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `variant_attribute_values` (
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `attribute_id` BIGINT UNSIGNED NOT NULL,
  `attribute_value_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`variant_id`,`attribute_id`),
  KEY `idx_vav_attr` (`attribute_id`),
  KEY `idx_vav_value` (`attribute_value_id`),
  CONSTRAINT `fk_vav_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_vav_attr` FOREIGN KEY (`attribute_id`) REFERENCES `attributes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_vav_value` FOREIGN KEY (`attribute_value_id`) REFERENCES `attribute_values` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_media` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `media_id` BIGINT UNSIGNED NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product_media_product` (`product_id`),
  KEY `idx_product_media_media` (`media_id`),
  CONSTRAINT `fk_product_media_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_product_media_media` FOREIGN KEY (`media_id`) REFERENCES `media_assets` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_categories` (
  `product_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`product_id`,`category_id`),
  KEY `idx_prodcat_cat` (`category_id`),
  CONSTRAINT `fk_prodcat_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prodcat_cat` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_tax` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `tax_class_id` BIGINT UNSIGNED NOT NULL,
  `override_reason` VARCHAR(191) NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_tax_product` (`product_id`),
  KEY `idx_product_tax_class` (`tax_class_id`),
  CONSTRAINT `fk_product_tax_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_product_tax_class` FOREIGN KEY (`tax_class_id`) REFERENCES `tax_classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_translations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `language_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(191) NOT NULL,
  `description` LONGTEXT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prodtrans` (`product_id`,`language_id`),
  KEY `idx_prodtrans_lang` (`language_id`),
  CONSTRAINT `fk_prodtrans_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prodtrans_lang` FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_seo` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `meta_title` VARCHAR(191) NULL DEFAULT NULL,
  `meta_description` VARCHAR(255) NULL DEFAULT NULL,
  `meta_keywords` VARCHAR(255) NULL DEFAULT NULL,
  `canonical_url` VARCHAR(255) NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_seo_product` (`product_id`),
  CONSTRAINT `fk_product_seo_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- F. PRICING MASTERS
-- ---------------------------------------------------------------------

CREATE TABLE `price_lists` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `channel` ENUM('online','pos','all') NOT NULL DEFAULT 'all',
  `customer_group_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `shop_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `is_gst_inclusive` TINYINT(1) NOT NULL DEFAULT 0,
  `priority` INT NOT NULL DEFAULT 0,
  `valid_from` DATETIME NOT NULL,
  `valid_to` DATETIME NULL DEFAULT NULL,
  `status` ENUM('active','scheduled','expired') NOT NULL DEFAULT 'active',
  `uuid` CHAR(36) NOT NULL,
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_price_lists_uuid` (`uuid`),
  KEY `idx_pricelists_channel` (`channel`,`priority`),
  KEY `idx_pricelists_shop` (`shop_id`),
  CONSTRAINT `fk_pricelists_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `price_list_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `price_list_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `price` DECIMAL(15,4) NOT NULL,
  `uuid` CHAR(36) NOT NULL,
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pli` (`price_list_id`,`variant_id`),
  UNIQUE KEY `uq_pli_uuid` (`uuid`),
  KEY `idx_pli_variant` (`variant_id`),
  CONSTRAINT `fk_pli_pricelist` FOREIGN KEY (`price_list_id`) REFERENCES `price_lists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pli_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `price_tiers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `price_list_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `min_qty` DECIMAL(15,3) NOT NULL,
  `price` DECIMAL(15,4) NOT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_price_tiers` (`price_list_id`,`variant_id`,`min_qty`),
  KEY `idx_price_tiers_variant` (`variant_id`),
  CONSTRAINT `fk_price_tiers_pricelist` FOREIGN KEY (`price_list_id`) REFERENCES `price_lists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_price_tiers_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customer_group_prices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_group_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `price` DECIMAL(15,4) NOT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cgp_variant` (`variant_id`),
  KEY `idx_cgp_group` (`customer_group_id`),
  CONSTRAINT `fk_cgp_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- G. MEDIA & CONTENT MASTERS
-- ---------------------------------------------------------------------

CREATE TABLE `media_assets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `bucket` VARCHAR(80) NOT NULL,
  `object_key` VARCHAR(255) NOT NULL,
  `owner_type` VARCHAR(40) NOT NULL,
  `owner_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `visibility` ENUM('public','private') NOT NULL DEFAULT 'private',
  `mime` VARCHAR(100) NOT NULL,
  `size_bytes` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('pending','active','orphan','deleted') NOT NULL DEFAULT 'pending',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_media_uuid` (`uuid`),
  UNIQUE KEY `uq_media_object_key` (`object_key`),
  KEY `idx_media_owner` (`owner_type`,`owner_id`),
  KEY `idx_media_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `media_derivatives` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `media_asset_id` BIGINT UNSIGNED NOT NULL,
  `variant` ENUM('thumb','medium','large') NOT NULL,
  `object_key` VARCHAR(255) NOT NULL,
  `width` INT UNSIGNED NULL DEFAULT NULL,
  `height` INT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('pending','active','failed') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_media_deriv_key` (`object_key`),
  KEY `idx_media_deriv_asset` (`media_asset_id`),
  CONSTRAINT `fk_media_deriv_asset` FOREIGN KEY (`media_asset_id`) REFERENCES `media_assets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `languages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(10) NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `is_rtl` TINYINT(1) NOT NULL DEFAULT 0,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_languages_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `translations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `language_id` BIGINT UNSIGNED NOT NULL,
  `namespace` VARCHAR(40) NOT NULL,
  `key` VARCHAR(191) NOT NULL,
  `value` TEXT NOT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_translations` (`language_id`,`namespace`,`key`),
  CONSTRAINT `fk_translations_lang` FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cms_pages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(191) NOT NULL,
  `title` VARCHAR(191) NOT NULL,
  `body` LONGTEXT NULL,
  `language_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `published_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cms_pages_slug_lang` (`slug`,`language_id`),
  KEY `idx_cms_pages_lang` (`language_id`),
  CONSTRAINT `fk_cms_pages_lang` FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `banners` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(191) NOT NULL,
  `placement` ENUM('home','category','app_top') NOT NULL DEFAULT 'home',
  `media_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `target_url` VARCHAR(255) NULL DEFAULT NULL,
  `segment_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `starts_at` DATETIME NULL DEFAULT NULL,
  `ends_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('scheduled','active','expired','disabled') NOT NULL DEFAULT 'scheduled',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_banners_placement` (`placement`,`status`),
  KEY `idx_banners_media` (`media_id`),
  KEY `idx_banners_segment` (`segment_id`),
  CONSTRAINT `fk_banners_media` FOREIGN KEY (`media_id`) REFERENCES `media_assets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_banners_segment` FOREIGN KEY (`segment_id`) REFERENCES `customer_segments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `home_layouts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel` ENUM('app','web') NOT NULL DEFAULT 'app',
  `layout_json` JSON NOT NULL,
  `version` INT NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `faqs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` VARCHAR(80) NULL DEFAULT NULL,
  `question` VARCHAR(255) NOT NULL,
  `answer` TEXT NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','hidden') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `content_blocks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(80) NOT NULL,
  `title` VARCHAR(191) NULL DEFAULT NULL,
  `body` LONGTEXT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_content_blocks_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- H. LOYALTY & ENGAGEMENT MASTERS
-- ---------------------------------------------------------------------

CREATE TABLE `loyalty_programs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `earn_rule` JSON NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `loyalty_tiers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loyalty_program_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `min_points` INT NOT NULL DEFAULT 0,
  `benefits` JSON NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_loyalty_tiers_program` (`loyalty_program_id`),
  CONSTRAINT `fk_loyalty_tiers_program` FOREIGN KEY (`loyalty_program_id`) REFERENCES `loyalty_programs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `wishlists` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(191) NOT NULL DEFAULT 'My Wishlist',
  `is_default` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_wishlists_customer` (`customer_id`),
  CONSTRAINT `fk_wishlists_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `wishlist_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `wishlist_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `added_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wishlist_items` (`wishlist_id`,`variant_id`),
  KEY `idx_wishlist_items_variant` (`variant_id`),
  CONSTRAINT `fk_wishlist_items_wishlist` FOREIGN KEY (`wishlist_id`) REFERENCES `wishlists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_wishlist_items_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `saved_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `qty` DECIMAL(15,3) NOT NULL DEFAULT 1.000,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_saved_items` (`customer_id`,`variant_id`),
  KEY `idx_saved_items_variant` (`variant_id`),
  CONSTRAINT `fk_saved_items_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_saved_items_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `recently_viewed` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recently_viewed` (`customer_id`,`viewed_at`),
  KEY `idx_recently_viewed_variant` (`variant_id`),
  CONSTRAINT `fk_recently_viewed_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_recently_viewed_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- End of 01_master.sql
