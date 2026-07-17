-- 46_rider_documents.sql — rider KYC documents (license / RC / insurance / PAN /
-- photo). Mirrors vendor_documents but scoped to the rider user. The file itself
-- lives in media_assets (owner_type='rider'); this row is the typed, verifiable
-- record. Run once:  php database/apply_sql.php database/sql/46_rider_documents.sql

CREATE TABLE IF NOT EXISTS `rider_documents` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36) NOT NULL,
    `rider_user_id`  BIGINT UNSIGNED NOT NULL,
    `doc_type`       ENUM('license','rc','insurance','pan','photo','other') NOT NULL DEFAULT 'other',
    `media_id`       BIGINT UNSIGNED NULL,
    `expires_at`     DATE NULL,
    `status`         ENUM('uploaded','verified','rejected') NOT NULL DEFAULT 'uploaded',
    `note`           VARCHAR(191) NULL,
    `created_by`     BIGINT UNSIGNED NULL,
    `updated_by`     BIGINT UNSIGNED NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`     DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rider_documents_uuid` (`uuid`),
    KEY `idx_rider_documents_rider` (`rider_user_id`, `deleted_at`),
    KEY `idx_rider_documents_media` (`media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
