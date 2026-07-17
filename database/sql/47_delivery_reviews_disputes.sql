-- Phase 3 — delivery feedback: customer ratings + disputes, and a delivered_at
-- stamp on deliveries so on-time / SLA performance metrics can be computed.

ALTER TABLE deliveries
  ADD COLUMN delivered_at DATETIME NULL AFTER status;

-- Backfill delivered_at for already-delivered rows (best effort: use updated_at).
UPDATE deliveries SET delivered_at = updated_at
  WHERE status = 'delivered' AND delivered_at IS NULL;

CREATE TABLE IF NOT EXISTS delivery_reviews (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid             CHAR(36) NOT NULL,
  delivery_id      BIGINT UNSIGNED NOT NULL,
  order_id         BIGINT UNSIGNED NULL,
  rider_user_id    BIGINT UNSIGNED NULL,
  customer_user_id BIGINT UNSIGNED NULL,
  rating           TINYINT UNSIGNED NOT NULL,
  comment          VARCHAR(500) NULL,
  created_at       DATETIME NULL,
  updated_at       DATETIME NULL,
  deleted_at       DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_delivery_review (delivery_id),
  KEY idx_rider (rider_user_id),
  KEY idx_customer (customer_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_disputes (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid              CHAR(36) NOT NULL,
  delivery_id       BIGINT UNSIGNED NOT NULL,
  order_id          BIGINT UNSIGNED NULL,
  rider_user_id     BIGINT UNSIGNED NULL,
  raised_by_user_id BIGINT UNSIGNED NULL,
  raised_by_role    ENUM('customer','vendor','rider','admin') NOT NULL DEFAULT 'customer',
  reason            VARCHAR(120) NOT NULL,
  detail            TEXT NULL,
  status            ENUM('open','investigating','resolved','rejected') NOT NULL DEFAULT 'open',
  resolution        TEXT NULL,
  resolved_by       BIGINT UNSIGNED NULL,
  resolved_at       DATETIME NULL,
  created_at        DATETIME NULL,
  updated_at        DATETIME NULL,
  deleted_at        DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_delivery (delivery_id),
  KEY idx_status (status),
  KEY idx_rider (rider_user_id),
  KEY idx_raiser (raised_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
