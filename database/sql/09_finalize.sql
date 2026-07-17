-- =====================================================================
-- File 09: FINALIZE
-- Re-enable integrity checks after the full load.
-- =====================================================================
USE `test`;

SET FOREIGN_KEY_CHECKS = 1;
SET UNIQUE_CHECKS = 1;

-- Optional: verify no orphaned foreign keys exist (run manually if desired).
-- SELECT table_name, constraint_name FROM information_schema.referential_constraints
--   WHERE constraint_schema = 'test';

SELECT CONCAT('Schema load complete. Tables created: ',
       (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'test')) AS result;
