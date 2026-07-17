-- =====================================================================
-- File 10: STAFF & DELIVERY MANAGEMENT (delta on the validated base 00-09)
-- Fills the spec gap: vendor staff taxonomy (branch_manager, cashier,
-- packer, helper, delivery_boy, manager), staff-to-shop assignment,
-- delivery-boy logistics profile, staff attendance.
--
-- NON-DUPLICATING: connects to EXISTING users/vendors/shops. Does NOT
-- alter or replace any existing table. Existing `delivery_assignments`
-- (rider_user_id -> users) and `rider_locations` are reused as-is; a
-- delivery_boy links to them via user_id. No change to users.principal_type
-- (vendor staff = 'vendor', delivery boys = 'rider').
--
-- Tenant isolation: every row carries vendor_id (RBAC + query-layer scope).
-- =====================================================================
USE `test`;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- vendor_staff : a user's staff membership under ONE vendor.
-- staff_type is the HR/operational classification; the permission set
-- still comes from user_roles (vendor-scoped role). The two complement.
-- ---------------------------------------------------------------------
CREATE TABLE `vendor_staff` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `staff_type` ENUM('branch_manager','cashier','packer','helper','delivery_boy','manager','other') NOT NULL,
  `employee_code` VARCHAR(40) NULL DEFAULT NULL,
  `designation` VARCHAR(80) NULL DEFAULT NULL,
  `joined_at` DATE NULL DEFAULT NULL,
  `status` ENUM('active','suspended','terminated') NOT NULL DEFAULT 'active',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vendor_staff_uuid` (`uuid`),
  UNIQUE KEY `uq_vendor_staff_user_vendor` (`vendor_id`,`user_id`),
  UNIQUE KEY `uq_vendor_staff_empcode` (`vendor_id`,`employee_code`),
  KEY `idx_vendor_staff_vendor_type` (`vendor_id`,`staff_type`,`status`),
  KEY `idx_vendor_staff_user` (`user_id`),
  CONSTRAINT `fk_vendor_staff_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_vendor_staff_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- staff_shop_assignments : which shop(s) a staff member works at (M:N).
-- A staff member can only be assigned to shops owned by THEIR vendor
-- (enforced at app/query layer; vendor_id reachable via vendor_staff).
-- ---------------------------------------------------------------------
CREATE TABLE `staff_shop_assignments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_staff_id` BIGINT UNSIGNED NOT NULL,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `assigned_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_shop` (`vendor_staff_id`,`shop_id`),
  KEY `idx_ssa_shop` (`shop_id`,`status`),
  CONSTRAINT `fk_staff_shop_staff` FOREIGN KEY (`vendor_staff_id`) REFERENCES `vendor_staff` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_staff_shop_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- delivery_boys : logistics profile for a delivery rider, bound to ONE
-- vendor (strict isolation). Links to the existing delivery_assignments
-- / rider_locations via user_id. Optionally linked to a vendor_staff row.
-- ---------------------------------------------------------------------
CREATE TABLE `delivery_boys` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `vendor_staff_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `vehicle_type` ENUM('bike','scooter','bicycle','car','foot') NOT NULL DEFAULT 'bike',
  `vehicle_no` VARCHAR(20) NULL DEFAULT NULL,
  `license_no` VARCHAR(40) NULL DEFAULT NULL,
  `availability` ENUM('available','busy','offline') NOT NULL DEFAULT 'offline',
  `current_lat` DECIMAL(10,7) NULL DEFAULT NULL,
  `current_lng` DECIMAL(10,7) NULL DEFAULT NULL,
  `last_location_at` DATETIME NULL DEFAULT NULL,
  `max_active_orders` INT UNSIGNED NOT NULL DEFAULT 1,
  `status` ENUM('active','suspended','terminated') NOT NULL DEFAULT 'active',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery_boys_uuid` (`uuid`),
  UNIQUE KEY `uq_delivery_boys_user_vendor` (`vendor_id`,`user_id`),
  KEY `idx_delivery_boys_avail` (`vendor_id`,`availability`,`status`),
  KEY `idx_delivery_boys_user` (`user_id`),
  KEY `idx_delivery_boys_staff` (`vendor_staff_id`),
  CONSTRAINT `fk_delivery_boys_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_delivery_boys_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_delivery_boys_staff` FOREIGN KEY (`vendor_staff_id`) REFERENCES `vendor_staff` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- staff_attendance : shift/attendance log for any vendor staff (incl.
-- delivery boys). Cashier till-sessions remain in pos_shifts (reused).
-- ---------------------------------------------------------------------
CREATE TABLE `staff_attendance` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_staff_id` BIGINT UNSIGNED NOT NULL,
  `shop_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `check_in_at` DATETIME NULL DEFAULT NULL,
  `check_out_at` DATETIME NULL DEFAULT NULL,
  `check_in_lat` DECIMAL(10,7) NULL DEFAULT NULL,
  `check_in_lng` DECIMAL(10,7) NULL DEFAULT NULL,
  `status` ENUM('checked_in','checked_out','absent') NOT NULL DEFAULT 'checked_in',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staff_attendance_staff` (`vendor_staff_id`,`check_in_at`),
  KEY `idx_staff_attendance_shop` (`shop_id`),
  CONSTRAINT `fk_staff_attendance_staff` FOREIGN KEY (`vendor_staff_id`) REFERENCES `vendor_staff` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_staff_attendance_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
SELECT CONCAT('Staff/Delivery delta applied. Total tables now: ',
       (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='test')) AS result;
-- End of 10_staff.sql
