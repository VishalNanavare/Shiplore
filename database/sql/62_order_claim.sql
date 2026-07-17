-- Order Claim, Escalation & Audit Trail
-- Adds ownership locking, FIFO priority, and full audit log to sub_orders.

-- 1. Audit log table
CREATE TABLE IF NOT EXISTS sub_order_claim_logs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sub_order_id   BIGINT UNSIGNED NOT NULL,
  event          ENUM('claimed','refreshed','released','force_released','expired','escalated','otp_attempt') NOT NULL,
  from_role      ENUM('admin','vendor','shop') NULL,
  from_user_id   BIGINT UNSIGNED NULL,
  to_role        ENUM('admin','vendor','shop') NULL,
  to_user_id     BIGINT UNSIGNED NULL,
  reason         VARCHAR(255) NULL,
  ip_address     VARCHAR(45) NULL,
  user_agent     TEXT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_log_sub_order (sub_order_id),
  INDEX idx_log_created   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Claim + escalation + priority columns on sub_orders
ALTER TABLE sub_orders
  ADD COLUMN claimed_by_role        ENUM('admin','vendor','shop') NULL          AFTER status,
  ADD COLUMN claimed_by_user_id     BIGINT UNSIGNED NULL                        AFTER claimed_by_role,
  ADD COLUMN claimed_at             DATETIME NULL                               AFTER claimed_by_user_id,
  ADD COLUMN claim_expires_at       DATETIME NULL                               AFTER claimed_at,
  ADD COLUMN claim_heartbeat_at     DATETIME NULL                               AFTER claim_expires_at,
  ADD COLUMN escalation_level       ENUM('shop','vendor','admin') NOT NULL DEFAULT 'shop' AFTER claim_heartbeat_at,
  ADD COLUMN escalation_notified_at DATETIME NULL                               AFTER escalation_level,
  ADD COLUMN priority_level         TINYINT UNSIGNED NOT NULL DEFAULT 0         AFTER escalation_notified_at,
  ADD COLUMN otp_expires_at         DATETIME NULL                               AFTER otp_verified_at,
  ADD COLUMN otp_last_attempt_at    DATETIME NULL                               AFTER otp_expires_at,
  ADD INDEX  idx_so_claim (claimed_by_role, claim_expires_at),
  ADD INDEX  idx_so_esc   (escalation_level, status, created_at),
  ADD INDEX  idx_so_pri   (priority_level, created_at);
