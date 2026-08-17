-- 50_rider_assign_unique.sql  |  Atomic one-rider-per-delivery guarantees
--
-- Adds UNIQUE indexes so the database itself prevents the dispatch/accept races:
--   uq_da_accepted — at most ONE 'accepted' assignment per delivery (no double-accept)
--   uq_da_offered  — at most ONE live 'offered' assignment per delivery (no double-offer)
--
-- Non-matching rows evaluate to NULL, and NULLs never collide in a UNIQUE index, so
-- historical declined/reassigned/completed rows are unaffected.
--
-- PORTABILITY — why this uses generated columns rather than functional indexes.
--
-- This file originally wrote the CASE expression directly into the index:
--     ADD UNIQUE KEY `uq_da_accepted` ((CASE WHEN `status` = 'accepted' THEN ... END))
-- That is a FUNCTIONAL INDEX, a MySQL 8.0.13+ feature. MariaDB does not support
-- functional indexes at ANY version — it indexes generated columns instead — so on
-- MariaDB the statement fails with a 1064 syntax error at the opening double paren.
--
-- That mattered because the schema as a whole REQUIRES MariaDB: 54_product_shops_index,
-- 74_report_indexes and 75_catalog_indexes all use `ADD INDEX IF NOT EXISTS`, which is
-- MariaDB-only. So the schema could not be built end-to-end on either engine — MySQL
-- choked on those three, MariaDB choked on this one. An operator hit exactly that
-- importing the full schema against MariaDB.
--
-- A STORED/VIRTUAL generated column with an ordinary UNIQUE index on it is supported by
-- both engines and enforces precisely the same constraint. The index names are
-- unchanged, so an environment that already has the functional-index version is left
-- alone by the guards below and needs no action.
--
-- Idempotent (PREPARE-guarded). Requires no existing duplicates (verified).
--
-- Apply: php database/apply_sql.php database/sql/50_rider_assign_unique.sql

-- ---- accepted -------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_assignments' AND COLUMN_NAME = 'accepted_delivery_id');
SET @ddl := IF(@col = 0,
    "ALTER TABLE `delivery_assignments` ADD COLUMN `accepted_delivery_id` BIGINT UNSIGNED AS (CASE WHEN `status` = 'accepted' THEN `delivery_id` END) VIRTUAL",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_assignments' AND INDEX_NAME = 'uq_da_accepted');
SET @ddl := IF(@idx = 0,
    "ALTER TABLE `delivery_assignments` ADD UNIQUE KEY `uq_da_accepted` (`accepted_delivery_id`)",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---- offered --------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_assignments' AND COLUMN_NAME = 'offered_delivery_id');
SET @ddl := IF(@col = 0,
    "ALTER TABLE `delivery_assignments` ADD COLUMN `offered_delivery_id` BIGINT UNSIGNED AS (CASE WHEN `status` = 'offered' THEN `delivery_id` END) VIRTUAL",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_assignments' AND INDEX_NAME = 'uq_da_offered');
SET @ddl := IF(@idx = 0,
    "ALTER TABLE `delivery_assignments` ADD UNIQUE KEY `uq_da_offered` (`offered_delivery_id`)",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'rider-assign-unique migration applied' AS result;
