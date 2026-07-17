-- Phase 4 — rider financial settlement: COD cash reconciliation (cash the rider
-- collected vs deposited) and rider earnings payout batches (mirrors the vendor
-- payout model: manual batch → mark paid, no live banking gateway).

-- COD collections currently have no rider link; add one so cash can be
-- reconciled per rider.
ALTER TABLE cod_collections
  ADD COLUMN rider_user_id BIGINT UNSIGNED NULL AFTER delivery_id,
  ADD KEY idx_cod_rider (rider_user_id);

-- Let an accrued payout entry be parked in a batch before it is paid (and link
-- it to its batch for audit / reversal).
ALTER TABLE rider_payout_entries
  MODIFY COLUMN status ENUM('accrued','batched','paid','reversed') NOT NULL DEFAULT 'accrued',
  ADD COLUMN batch_id BIGINT UNSIGNED NULL AFTER status,
  ADD KEY idx_rpe_batch (batch_id);

CREATE TABLE IF NOT EXISTS rider_payout_batches (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid         CHAR(36) NOT NULL,
  period_start DATE NULL,
  period_end   DATE NULL,
  total_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
  rider_count  INT UNSIGNED NOT NULL DEFAULT 0,
  status       ENUM('created','completed','partial','failed') NOT NULL DEFAULT 'created',
  note         VARCHAR(255) NULL,
  created_by   BIGINT UNSIGNED NULL,
  updated_by   BIGINT UNSIGNED NULL,
  created_at   DATETIME NULL,
  updated_at   DATETIME NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rider_payouts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36) NOT NULL,
  batch_id        BIGINT UNSIGNED NOT NULL,
  rider_user_id   BIGINT UNSIGNED NOT NULL,
  entries_count   INT UNSIGNED NOT NULL DEFAULT 0,
  gross_amount    DECIMAL(15,4) NOT NULL DEFAULT 0,
  cod_outstanding DECIMAL(15,4) NOT NULL DEFAULT 0,
  net_amount      DECIMAL(15,4) NOT NULL DEFAULT 0,
  bank_ref        VARCHAR(120) NULL,
  status          ENUM('pending','paid','failed','held') NOT NULL DEFAULT 'pending',
  paid_at         DATETIME NULL,
  created_by      BIGINT UNSIGNED NULL,
  updated_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME NULL,
  updated_at      DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_rp_batch (batch_id),
  KEY idx_rp_rider (rider_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
