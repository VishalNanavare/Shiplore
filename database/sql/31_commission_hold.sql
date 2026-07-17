-- =====================================================================
-- 31_commission_hold.sql  |  Phase X2a — commission hold window (P0).
-- Commission must never be settleable while the return window is open:
-- ledger rows now start `on_hold` with a `window_ends_at`, get promoted to
-- `accrued` by the scheduler, and are `cancelled` when goods come back.
-- return_policies supplies the window days (most-specific wins, like
-- approval_rules: vendor+category > vendor > category > global).
--
-- Idempotent, DELIMITER-free. Apply: php database/apply_sql.php database/sql/31_commission_hold.sql
--
-- @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §8
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

-- A. commission_ledger — hold states + window (MODIFY is naturally re-runnable)
ALTER TABLE `commission_ledger` MODIFY `status`
  ENUM('on_hold','accrued','settled','reversed','cancelled') NOT NULL DEFAULT 'accrued';

SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `commission_ledger` ADD COLUMN `window_ends_at` DATETIME NULL DEFAULT NULL AFTER `status`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commission_ledger' AND COLUMN_NAME = 'window_ends_at');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `commission_ledger` ADD COLUMN `held_reason` VARCHAR(120) NULL DEFAULT NULL AFTER `window_ends_at`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commission_ledger' AND COLUMN_NAME = 'held_reason');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `commission_ledger` ADD KEY `idx_comm_ledger_window` (`status`,`window_ends_at`)',
  'SELECT 1') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commission_ledger' AND INDEX_NAME = 'idx_comm_ledger_window');
PREPARE _s FROM @ddl; EXECUTE _s; DEALLOCATE PREPARE _s;

-- B. return_policies — window-days configuration
CREATE TABLE IF NOT EXISTS `return_policies` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id`   BIGINT UNSIGNED NULL DEFAULT NULL,
  `category_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `days`        INT UNSIGNED NOT NULL DEFAULT 7,
  `priority`    INT NOT NULL DEFAULT 0,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`  BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by`  BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_return_policies_vendor` (`vendor_id`),
  KEY `idx_return_policies_category` (`category_id`),
  CONSTRAINT `fk_return_policies_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_return_policies_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Global default: 7-day return window (idempotent seed)
INSERT INTO `return_policies` (`vendor_id`, `category_id`, `days`, `priority`, `status`)
SELECT NULL, NULL, 7, 0, 'active'
WHERE NOT EXISTS (SELECT 1 FROM `return_policies` WHERE `vendor_id` IS NULL AND `category_id` IS NULL);

SET FOREIGN_KEY_CHECKS = 1;
