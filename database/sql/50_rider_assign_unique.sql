-- 50_rider_assign_unique.sql  |  Atomic one-rider-per-delivery guarantees
-- Adds functional UNIQUE indexes (MySQL 8.0.13+/9.x) so the database itself
-- prevents the dispatch/accept races:
--   uq_da_accepted — at most ONE 'accepted' assignment per delivery (no double-accept)
--   uq_da_offered  — at most ONE live 'offered' assignment per delivery (no double-offer)
-- Non-matching rows evaluate the CASE to NULL, and NULLs never collide in a UNIQUE
-- index, so historical declined/reassigned/completed rows are unaffected.
-- Idempotent (PREPARE-guarded). Requires no existing duplicates (verified).
--
-- Apply: php database/apply_sql.php database/sql/50_rider_assign_unique.sql

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_assignments' AND INDEX_NAME = 'uq_da_accepted');
SET @ddl := IF(@idx = 0,
    "ALTER TABLE `delivery_assignments` ADD UNIQUE KEY `uq_da_accepted` ((CASE WHEN `status` = 'accepted' THEN `delivery_id` END))",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_assignments' AND INDEX_NAME = 'uq_da_offered');
SET @ddl := IF(@idx = 0,
    "ALTER TABLE `delivery_assignments` ADD UNIQUE KEY `uq_da_offered` ((CASE WHEN `status` = 'offered' THEN `delivery_id` END))",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'rider-assign-unique migration applied' AS result;
