-- =====================================================================
-- 41_media_library.sql  |  General media library (vendor + shop files)
-- Adds the original filename to media_assets so the file-manager can show
-- human names (object keys are random hex). Idempotent (PREPARE-guarded).
--
-- Apply: php database/apply_sql.php database/sql/41_media_library.sql
-- =====================================================================

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media_assets' AND COLUMN_NAME = 'original_name');
SET @ddl := IF(@col = 0,
    'ALTER TABLE `media_assets` ADD COLUMN `original_name` VARCHAR(255) NULL AFTER `mime`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Helpful index for the library's owner-scoped listings.
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media_assets' AND INDEX_NAME = 'idx_media_owner');
SET @ddl2 := IF(@idx = 0,
    'ALTER TABLE `media_assets` ADD INDEX `idx_media_owner` (`owner_type`,`owner_id`,`status`)',
    'SELECT 1');
PREPARE stmt2 FROM @ddl2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
