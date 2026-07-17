-- 51_api_idempotency.sql  |  Durable idempotency for API order placement
-- Replaces the best-effort cache-only dedup (which silently fails under the dummy
-- cache handler and offers no concurrency guarantee) with an insert-first row whose
-- UNIQUE(customer_id, idem_key) makes a concurrent/retried POST /customer/orders
-- collapse to a single order. The stored response is replayed verbatim.
-- Idempotent (CREATE TABLE IF NOT EXISTS).
--
-- Apply: php database/apply_sql.php database/sql/51_api_idempotency.sql

CREATE TABLE IF NOT EXISTS `api_idempotency_keys` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `idem_key`    VARCHAR(80) NOT NULL,
  `order_no`    VARCHAR(40) NULL DEFAULT NULL,
  `response`    JSON NULL DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_idem` (`customer_id`, `idem_key`),
  KEY `idx_api_idem_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'api-idempotency migration applied' AS result;
