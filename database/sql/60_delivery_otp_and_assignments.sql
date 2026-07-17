-- Delivery OTP verification and per-item assignment support

ALTER TABLE sub_orders
    ADD COLUMN delivery_otp    VARCHAR(6)        NULL AFTER status,
    ADD COLUMN otp_attempts    TINYINT UNSIGNED  NOT NULL DEFAULT 0 AFTER delivery_otp,
    ADD COLUMN otp_verified_at TIMESTAMP         NULL AFTER otp_attempts;

CREATE TABLE IF NOT EXISTS order_item_assignments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL,
    order_item_id   INT UNSIGNED NOT NULL,
    mode            ENUM('pool','self') NOT NULL DEFAULT 'pool',
    rider_user_id   INT UNSIGNED NULL,
    assigned_by     INT UNSIGNED NOT NULL,
    assigned_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes           VARCHAR(255) NULL,
    UNIQUE KEY uk_item (order_item_id),
    INDEX idx_order (order_id)
);
