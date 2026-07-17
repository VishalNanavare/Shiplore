-- 52_auth_otp_pod.sql  |  Allow 'pod' as an OTP purpose
-- RiderRepository issues + verifies delivery proof-of-delivery OTPs with purpose
-- 'pod', but auth_otp.purpose was ENUM('login','reset','step_up','verify'). Under
-- the default non-strict connection MySQL silently coerced 'pod' -> '' on insert,
-- so verify() (WHERE purpose='pod') never matched and rider OTP-POD ALWAYS failed.
-- Idempotent (only ALTERs when 'pod' is absent).
--
-- Apply: php database/apply_sql.php database/sql/52_auth_otp_pod.sql

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_otp'
      AND COLUMN_NAME = 'purpose' AND COLUMN_TYPE LIKE "%'pod'%");
SET @ddl := IF(@has = 0,
    "ALTER TABLE `auth_otp` MODIFY COLUMN `purpose` ENUM('login','reset','step_up','verify','pod') NOT NULL",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'auth-otp-pod migration applied' AS result;
