USE `test`;

CREATE TABLE IF NOT EXISTS `customer_payment_instruments` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `type`        ENUM('upi','card','netbanking','wallet') NOT NULL DEFAULT 'upi',
  `label`       VARCHAR(80)  NOT NULL DEFAULT '',
  `instrument`  VARCHAR(120) NOT NULL,
  `is_default`  TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`  DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cpi_customer` (`customer_id`, `deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
