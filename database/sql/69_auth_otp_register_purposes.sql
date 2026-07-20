-- 69_auth_otp_register_purposes.sql  |  Give vendor registration + store email login
-- their own OTP purposes.
--
-- Vendor registration (Auth\RegisterController) and store email login
-- (Store\AccountController) both issued codes with the DEFAULT purpose 'verify' —
-- the same namespace the public, unauthenticated endpoint api/v1/auth/otp/request
-- writes to. Because OtpService::verify() only ever reads the NEWEST unconsumed row
-- for an identifier+purpose, anyone could mint a fresh 'verify' row for a victim's
-- email/phone and silently invalidate the code that victim had actually received.
--
-- Separating the purposes makes that cross-flow collision structurally impossible.
-- Idempotent (only ALTERs when 'register_email' is absent).
--
-- Apply: php database/apply_sql.php database/sql/69_auth_otp_register_purposes.sql

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_otp'
      AND COLUMN_NAME = 'purpose' AND COLUMN_TYPE LIKE "%'register_email'%");
SET @ddl := IF(@has = 0,
    "ALTER TABLE `auth_otp` MODIFY COLUMN `purpose` ENUM('login','reset','step_up','verify','pod','register_email','login_email') NOT NULL",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'auth-otp register/login-email purposes migration applied' AS result;
