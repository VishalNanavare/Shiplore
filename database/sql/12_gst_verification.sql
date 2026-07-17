-- =====================================================================
-- File 12: GST VERIFICATION (forward migration; additive, non-breaking)
-- Backs the MANDATORY rule: GSTIN must be verified (via GST Portal API)
-- BEFORE vendor/shop registration completes. Adds a verification log +
-- status/timestamp columns on vendors and shops. Run once.
-- (Re-running the ALTERs will error on existing columns — by design this
--  is a one-way migration; the clean rebuild via run_all drops first.)
-- =====================================================================
USE `test`;
SET FOREIGN_KEY_CHECKS = 0;

-- Append-style verification attempts/results (polymorphic scope: vendor|shop, no FK).
CREATE TABLE IF NOT EXISTS `gst_verifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `scope_type` ENUM('vendor','shop') NOT NULL,
  `scope_id` BIGINT UNSIGNED NOT NULL,
  `gstin` CHAR(15) NOT NULL,
  `provider` VARCHAR(40) NOT NULL DEFAULT 'gst_portal',
  `legal_name` VARCHAR(191) NULL DEFAULT NULL,
  `state_code` CHAR(2) NULL DEFAULT NULL,
  `status` ENUM('pending','verified','failed') NOT NULL DEFAULT 'pending',
  `response` JSON NULL,
  `verified_at` DATETIME NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gst_verifications_uuid` (`uuid`),
  KEY `idx_gstverif_scope` (`scope_type`,`scope_id`),
  KEY `idx_gstverif_gstin` (`gstin`),
  KEY `idx_gstverif_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Denormalized gate flags (queried on the onboarding gate; source of truth = gst_verifications).
ALTER TABLE `vendors`
  ADD COLUMN `gstin_verified_at` DATETIME NULL DEFAULT NULL AFTER `state_code`,
  ADD COLUMN `gstin_status` ENUM('unverified','pending','verified','failed') NOT NULL DEFAULT 'unverified' AFTER `gstin_verified_at`;

ALTER TABLE `shops`
  ADD COLUMN `gstin_verified_at` DATETIME NULL DEFAULT NULL AFTER `gstin`,
  ADD COLUMN `gstin_status` ENUM('unverified','pending','verified','failed') NOT NULL DEFAULT 'unverified' AFTER `gstin_verified_at`;

SET FOREIGN_KEY_CHECKS = 1;
SELECT CONCAT('GST verification migration applied. gst_verifications + vendors/shops.gstin_status. tables=',
       (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='test')) AS result;
-- End of 12_gst_verification.sql
