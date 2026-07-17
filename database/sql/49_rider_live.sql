-- 49_rider_live.sql  |  Live rider dispatch (offer TTL) + duty shifts
-- Adds:
--   delivery_assignments.expires_at — when an OFFER to a rider lapses; the dispatch
--       engine then re-offers to the next-nearest online rider (Blinkit/Zomato-style
--       accept-with-countdown). Expired offers are marked status='reassigned'.
--   rider_shifts — rider duty/login sessions (clock-in on "go online", clock-out on
--       "go offline"); powers login-hours + performance.
-- Idempotent (PREPARE-guarded). Additive only.
--
-- Apply: php database/apply_sql.php database/sql/49_rider_live.sql

-- delivery_assignments.expires_at
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_assignments' AND COLUMN_NAME = 'expires_at');
SET @ddl := IF(@col = 0,
    "ALTER TABLE `delivery_assignments` ADD COLUMN `expires_at` DATETIME NULL DEFAULT NULL AFTER `offered_at`",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_assignments' AND INDEX_NAME = 'idx_da_offer_expiry');
SET @ddl := IF(@idx = 0,
    "ALTER TABLE `delivery_assignments` ADD INDEX `idx_da_offer_expiry` (`status`, `expires_at`)",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- rider_shifts (duty/login sessions)
CREATE TABLE IF NOT EXISTS `rider_shifts` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`          CHAR(36) NOT NULL,
  `rider_user_id` BIGINT UNSIGNED NOT NULL,
  `started_at`    DATETIME NOT NULL,
  `ended_at`      DATETIME NULL DEFAULT NULL,
  `duration_min`  INT UNSIGNED NULL DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rider_shifts_uuid` (`uuid`),
  KEY `idx_rider_shifts_rider` (`rider_user_id`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'rider-live migration applied' AS result;
