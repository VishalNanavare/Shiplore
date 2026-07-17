-- =====================================================================
-- File 04: TRANSACTION TABLES
-- Cart/checkout, orders/invoices, inventory (ledger), product approval,
-- POS sales, returns/credit notes, delivery, reviews, commission &
-- settlement, loyalty/promo usage, jobs/import-export/webhook/search logs.
-- Append-only tables (ledgers/history/logs) keep created_at only.
-- =====================================================================
USE `test`;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- A. CART & CHECKOUT
-- ---------------------------------------------------------------------

CREATE TABLE `carts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `customer_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `session_token` VARCHAR(64) NULL DEFAULT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `expires_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('active','converted','abandoned','expired') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_carts_uuid` (`uuid`),
  KEY `idx_carts_customer` (`customer_id`,`status`),
  KEY `idx_carts_session` (`session_token`),
  CONSTRAINT `fk_carts_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cart_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cart_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `qty` DECIMAL(15,3) NOT NULL DEFAULT 1.000,
  `unit_price_snapshot` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `status` ENUM('active','saved','removed') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cart_items` (`cart_id`,`variant_id`),
  KEY `idx_cart_items_variant` (`variant_id`),
  KEY `idx_cart_items_vendor` (`vendor_id`),
  KEY `idx_cart_items_shop` (`shop_id`),
  CONSTRAINT `fk_cart_items_cart` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cart_items_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cart_adjustments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cart_id` BIGINT UNSIGNED NOT NULL,
  `type` ENUM('coupon','promo','delivery','tax') NOT NULL,
  `code` VARCHAR(40) NULL DEFAULT NULL,
  `amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `meta` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cart_adjustments_cart` (`cart_id`),
  CONSTRAINT `fk_cart_adjustments_cart` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `checkout_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `cart_id` BIGINT UNSIGNED NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `address_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `delivery_slot` VARCHAR(40) NULL DEFAULT NULL,
  `payment_method` VARCHAR(40) NULL DEFAULT NULL,
  `validated_totals` JSON NULL,
  `idempotency_key` VARCHAR(64) NULL DEFAULT NULL,
  `status` ENUM('open','validated','placed','failed','expired') NOT NULL DEFAULT 'open',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_checkout_uuid` (`uuid`),
  UNIQUE KEY `uq_checkout_idem` (`idempotency_key`),
  KEY `idx_checkout_cart` (`cart_id`),
  KEY `idx_checkout_customer` (`customer_id`),
  CONSTRAINT `fk_checkout_cart` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_checkout_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_checkout_address` FOREIGN KEY (`address_id`) REFERENCES `customer_addresses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- B. ORDERS & INVOICES
-- ---------------------------------------------------------------------

CREATE TABLE `orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `order_no` VARCHAR(40) NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `channel` ENUM('online','app','pos') NOT NULL DEFAULT 'online',
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `subtotal` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `discount_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `tax_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `delivery_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `grand_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `billing_address_json` JSON NOT NULL,
  `payment_status` ENUM('pending','paid','partially_paid','failed','refunded') NOT NULL DEFAULT 'pending',
  `placed_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('created','confirmed','partially_fulfilled','completed','cancelled') NOT NULL DEFAULT 'created',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_uuid` (`uuid`),
  UNIQUE KEY `uq_orders_no` (`order_no`),
  KEY `idx_orders_customer` (`customer_id`),
  KEY `idx_orders_status` (`status`),
  KEY `idx_orders_payment` (`payment_status`),
  CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sub_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `sub_order_no` VARCHAR(40) NOT NULL,
  `subtotal` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `discount_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `taxable_value` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `delivery_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `round_off` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `grand_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `commission_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `accept_deadline_at` DATETIME NULL DEFAULT NULL,
  `delivered_at` DATETIME NULL DEFAULT NULL,
  `place_of_supply` CHAR(2) NOT NULL,
  `status` ENUM('pending','confirmed','accepted','packed','ready','out_for_delivery','delivered','cancelled','returned','completed') NOT NULL DEFAULT 'pending',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sub_orders_uuid` (`uuid`),
  UNIQUE KEY `uq_sub_orders_no` (`sub_order_no`),
  KEY `idx_suborders_vendor_status` (`vendor_id`,`status`),
  KEY `idx_suborders_order` (`order_id`),
  KEY `idx_suborders_shop` (`shop_id`),
  CONSTRAINT `fk_suborders_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_suborders_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_suborders_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_addresses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `type` ENUM('billing','shipping') NOT NULL DEFAULT 'shipping',
  `name` VARCHAR(191) NOT NULL,
  `phone` VARCHAR(20) NULL DEFAULT NULL,
  `line1` VARCHAR(191) NOT NULL,
  `line2` VARCHAR(191) NULL DEFAULT NULL,
  `city` VARCHAR(80) NOT NULL,
  `state_code` CHAR(2) NOT NULL,
  `pincode` VARCHAR(10) NOT NULL,
  `latitude` DECIMAL(10,7) NULL DEFAULT NULL,
  `longitude` DECIMAL(10,7) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_addresses_order` (`order_id`),
  CONSTRAINT `fk_order_addresses_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `sub_order_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `product_title_snapshot` VARCHAR(191) NOT NULL,
  `sku_snapshot` VARCHAR(64) NOT NULL,
  `hsn_snapshot` VARCHAR(8) NULL DEFAULT NULL,
  `qty` DECIMAL(15,3) NOT NULL,
  `unit_price` DECIMAL(15,4) NOT NULL,
  `mrp` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `discount_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `taxable_value` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `cgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `is_gst_inclusive` TINYINT(1) NOT NULL DEFAULT 0,
  `line_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `status` ENUM('active','cancelled','returned') NOT NULL DEFAULT 'active',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_items_uuid` (`uuid`),
  KEY `idx_order_items_suborder` (`sub_order_id`),
  KEY `idx_order_items_order` (`order_id`),
  KEY `idx_order_items_variant` (`variant_id`),
  CONSTRAINT `fk_order_items_suborder` FOREIGN KEY (`sub_order_id`) REFERENCES `sub_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_order_items_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sub_order_id` BIGINT UNSIGNED NOT NULL,
  `from_status` VARCHAR(40) NULL DEFAULT NULL,
  `to_status` VARCHAR(40) NOT NULL,
  `actor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `reason` VARCHAR(191) NULL DEFAULT NULL,
  `meta` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_osh_suborder` (`sub_order_id`,`created_at`),
  KEY `idx_osh_actor` (`actor_id`),
  CONSTRAINT `fk_osh_suborder` FOREIGN KEY (`sub_order_id`) REFERENCES `sub_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_osh_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `sub_order_id` BIGINT UNSIGNED NOT NULL,
  `invoice_no` VARCHAR(40) NOT NULL,
  `invoice_date` DATE NOT NULL,
  `place_of_supply` CHAR(2) NOT NULL,
  `supplier_gstin` CHAR(15) NULL DEFAULT NULL,
  `irn` VARCHAR(64) NULL DEFAULT NULL,
  `signed_qr` TEXT NULL,
  `taxable_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cgst_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `round_off` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `grand_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `media_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `doc_type` ENUM('tax_invoice','bill_of_supply') NOT NULL DEFAULT 'tax_invoice',
  `status` ENUM('generated','sent','cancelled') NOT NULL DEFAULT 'generated',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoices_uuid` (`uuid`),
  UNIQUE KEY `uq_invoices_suborder` (`sub_order_id`),
  UNIQUE KEY `uq_invoices_no` (`invoice_no`),
  KEY `idx_invoices_date` (`invoice_date`),
  KEY `idx_invoices_media` (`media_id`),
  CONSTRAINT `fk_invoices_suborder` FOREIGN KEY (`sub_order_id`) REFERENCES `sub_orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_invoices_media` FOREIGN KEY (`media_id`) REFERENCES `media_assets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  `order_item_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `description` VARCHAR(191) NOT NULL,
  `hsn` VARCHAR(8) NULL DEFAULT NULL,
  `qty` DECIMAL(15,3) NOT NULL,
  `unit_price` DECIMAL(15,4) NOT NULL,
  `taxable_value` DECIMAL(15,4) NOT NULL,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `cgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `line_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_lines_invoice` (`invoice_id`),
  KEY `idx_invoice_lines_item` (`order_item_id`),
  CONSTRAINT `fk_invoice_lines_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_invoice_lines_item` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- C. INVENTORY
-- ---------------------------------------------------------------------

CREATE TABLE `inventory` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `on_hand` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `reserved` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `available` DECIMAL(15,3) AS (`on_hand` - `reserved`) STORED,
  `reorder_level` DECIMAL(15,3) NULL DEFAULT NULL,
  `status` ENUM('in_stock','low','out_of_stock') NOT NULL DEFAULT 'in_stock',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventory_uuid` (`uuid`),
  UNIQUE KEY `uq_inventory_variant_shop` (`variant_id`,`shop_id`),
  KEY `idx_inventory_shop` (`shop_id`,`status`),
  CONSTRAINT `fk_inventory_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_batches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `batch_no` VARCHAR(64) NOT NULL,
  `mfg_date` DATE NULL DEFAULT NULL,
  `expiry_date` DATE NULL DEFAULT NULL,
  `qty` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `cost_price` DECIMAL(15,4) NULL DEFAULT NULL,
  `status` ENUM('active','expired','depleted') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stock_batches` (`variant_id`,`shop_id`,`batch_no`),
  KEY `idx_stock_batches_shop` (`shop_id`),
  KEY `idx_stock_batches_expiry` (`expiry_date`),
  CONSTRAINT `fk_stock_batches_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_batches_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_ledger` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `movement_type` ENUM('purchase','sale','pos_sale','return','adjustment','transfer_in','transfer_out','reservation','release','write_off') NOT NULL,
  `qty_delta` DECIMAL(15,3) NOT NULL,
  `balance_after` DECIMAL(15,3) NOT NULL,
  `ref_type` VARCHAR(40) NULL DEFAULT NULL,
  `ref_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `ref_uuid` CHAR(36) NULL DEFAULT NULL,
  `batch_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `reason_code` VARCHAR(40) NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventory_ledger_uuid` (`uuid`),
  KEY `idx_invled_variant_shop` (`variant_id`,`shop_id`,`created_at`),
  KEY `idx_invled_ref` (`ref_type`,`ref_id`),
  KEY `idx_invled_batch` (`batch_id`),
  CONSTRAINT `fk_invled_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_invled_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_invled_batch` FOREIGN KEY (`batch_id`) REFERENCES `stock_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_reservations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `qty` DECIMAL(15,3) NOT NULL,
  `expires_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('held','committed','released','expired') NOT NULL DEFAULT 'held',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stock_resv_order` (`order_id`),
  KEY `idx_stock_resv_expiry` (`expires_at`,`status`),
  KEY `idx_stock_resv_variant_shop` (`variant_id`,`shop_id`),
  CONSTRAINT `fk_stock_resv_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_resv_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_resv_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_transfers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `from_shop_id` BIGINT UNSIGNED NOT NULL,
  `to_shop_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `qty` DECIMAL(15,3) NOT NULL,
  `status` ENUM('requested','approved','dispatched','received','partially_received','reconciled','cancelled') NOT NULL DEFAULT 'requested',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stock_transfers_uuid` (`uuid`),
  KEY `idx_stock_transfers_from` (`from_shop_id`),
  KEY `idx_stock_transfers_to` (`to_shop_id`),
  KEY `idx_stock_transfers_variant` (`variant_id`),
  CONSTRAINT `fk_stock_transfers_from` FOREIGN KEY (`from_shop_id`) REFERENCES `shops` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_transfers_to` FOREIGN KEY (`to_shop_id`) REFERENCES `shops` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_transfers_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_adjustments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `qty_delta` DECIMAL(15,3) NOT NULL,
  `reason_code` VARCHAR(40) NULL DEFAULT NULL,
  `notes` VARCHAR(191) NULL DEFAULT NULL,
  `approved_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stock_adj_shop` (`shop_id`),
  KEY `idx_stock_adj_variant` (`variant_id`),
  KEY `idx_stock_adj_approver` (`approved_by`),
  CONSTRAINT `fk_stock_adj_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_adj_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_adj_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `low_stock_alerts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `threshold` DECIMAL(15,3) NOT NULL,
  `current_qty` DECIMAL(15,3) NOT NULL,
  `notified_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('open','resolved') NOT NULL DEFAULT 'open',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_low_stock_shop` (`shop_id`,`status`),
  KEY `idx_low_stock_variant` (`variant_id`),
  CONSTRAINT `fk_low_stock_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_low_stock_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- D. PRODUCT APPROVAL
-- ---------------------------------------------------------------------

CREATE TABLE `product_approvals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `submitted_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `reviewer_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `sla_due_at` DATETIME NULL DEFAULT NULL,
  `decided_at` DATETIME NULL DEFAULT NULL,
  `reason_code_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `notes` TEXT NULL,
  `status` ENUM('submitted','under_review','approved','rejected','changes_requested') NOT NULL DEFAULT 'submitted',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_approvals_uuid` (`uuid`),
  KEY `idx_papprovals_status` (`status`,`sla_due_at`),
  KEY `idx_papprovals_product` (`product_id`),
  KEY `idx_papprovals_reviewer` (`reviewer_id`),
  KEY `idx_papprovals_reason` (`reason_code_id`),
  CONSTRAINT `fk_papprovals_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_papprovals_submitter` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_papprovals_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_papprovals_reason` FOREIGN KEY (`reason_code_id`) REFERENCES `rejection_reasons` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_approval_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_approval_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `from_status` VARCHAR(40) NULL DEFAULT NULL,
  `to_status` VARCHAR(40) NOT NULL,
  `actor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `reason` VARCHAR(191) NULL DEFAULT NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pah_approval` (`product_approval_id`),
  KEY `idx_pah_actor` (`actor_id`),
  CONSTRAINT `fk_pah_approval` FOREIGN KEY (`product_approval_id`) REFERENCES `product_approvals` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pah_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- E. POS SALES
-- ---------------------------------------------------------------------

CREATE TABLE `pos_shifts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `terminal_id` BIGINT UNSIGNED NOT NULL,
  `cashier_user_id` BIGINT UNSIGNED NOT NULL,
  `opened_at` DATETIME NOT NULL,
  `closed_at` DATETIME NULL DEFAULT NULL,
  `opening_float` DECIMAL(15,4) NULL DEFAULT NULL,
  `closing_cash` DECIMAL(15,4) NULL DEFAULT NULL,
  `expected_cash` DECIMAL(15,4) NULL DEFAULT NULL,
  `variance` DECIMAL(15,4) NULL DEFAULT NULL,
  `sync_status` ENUM('pending','synced','conflict') NOT NULL DEFAULT 'pending',
  `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pos_shifts_uuid` (`uuid`),
  KEY `idx_posshifts_terminal` (`terminal_id`,`status`),
  KEY `idx_posshifts_cashier` (`cashier_user_id`),
  CONSTRAINT `fk_posshifts_terminal` FOREIGN KEY (`terminal_id`) REFERENCES `pos_terminals` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_posshifts_cashier` FOREIGN KEY (`cashier_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pos_sales` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `terminal_id` BIGINT UNSIGNED NOT NULL,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `shift_id` BIGINT UNSIGNED NOT NULL,
  `cashier_user_id` BIGINT UNSIGNED NOT NULL,
  `customer_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `offline_invoice_no` VARCHAR(40) NOT NULL,
  `server_invoice_no` VARCHAR(40) NULL DEFAULT NULL,
  `subtotal` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `discount_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `taxable_value` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `round_off` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `grand_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sold_at` DATETIME NOT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `sync_status` ENUM('pending','synced','conflict') NOT NULL DEFAULT 'pending',
  `status` ENUM('completed','void','returned') NOT NULL DEFAULT 'completed',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pos_sales_uuid` (`uuid`),
  UNIQUE KEY `uq_pos_sales_terminal_inv` (`terminal_id`,`offline_invoice_no`),
  KEY `idx_pos_sales_shop_sold` (`shop_id`,`sold_at`),
  KEY `idx_pos_sales_sync` (`sync_status`),
  KEY `idx_pos_sales_shift` (`shift_id`),
  KEY `idx_pos_sales_cashier` (`cashier_user_id`),
  KEY `idx_pos_sales_customer` (`customer_id`),
  CONSTRAINT `fk_pos_sales_terminal` FOREIGN KEY (`terminal_id`) REFERENCES `pos_terminals` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pos_sales_shift` FOREIGN KEY (`shift_id`) REFERENCES `pos_shifts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pos_sales_cashier` FOREIGN KEY (`cashier_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pos_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pos_sale_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `pos_sale_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `product_title_snapshot` VARCHAR(191) NOT NULL,
  `sku_snapshot` VARCHAR(64) NOT NULL,
  `hsn_snapshot` VARCHAR(8) NULL DEFAULT NULL,
  `qty` DECIMAL(15,3) NOT NULL,
  `unit_price` DECIMAL(15,4) NOT NULL,
  `discount_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `taxable_value` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `cgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `is_gst_inclusive` TINYINT(1) NOT NULL DEFAULT 0,
  `line_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `status` ENUM('active','returned') NOT NULL DEFAULT 'active',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pos_sale_items_uuid` (`uuid`),
  KEY `idx_pos_sale_items_sale` (`pos_sale_id`),
  KEY `idx_pos_sale_items_variant` (`variant_id`),
  CONSTRAINT `fk_pos_sale_items_sale` FOREIGN KEY (`pos_sale_id`) REFERENCES `pos_sales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pos_sale_items_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pos_sale_payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `pos_sale_id` BIGINT UNSIGNED NOT NULL,
  `tender_type` ENUM('cash','card','upi','wallet') NOT NULL,
  `amount` DECIMAL(15,4) NOT NULL,
  `reference` VARCHAR(120) NULL DEFAULT NULL,
  `status` ENUM('captured','void') NOT NULL DEFAULT 'captured',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pos_sale_payments_uuid` (`uuid`),
  KEY `idx_pos_sale_payments_sale` (`pos_sale_id`),
  CONSTRAINT `fk_pos_sale_payments_sale` FOREIGN KEY (`pos_sale_id`) REFERENCES `pos_sales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- F. RETURNS & CREDIT NOTES
-- ---------------------------------------------------------------------

CREATE TABLE `returns` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `sub_order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `pos_sale_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `customer_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `reason_code_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `channel` ENUM('online','pos') NOT NULL DEFAULT 'online',
  `refund_to` ENUM('source','wallet','cash') NOT NULL DEFAULT 'source',
  `qc_result` VARCHAR(40) NULL DEFAULT NULL,
  `status` ENUM('requested','approved','rejected','pickup_scheduled','picked_up','qc','refund_approved','refunded','restocked','write_off') NOT NULL DEFAULT 'requested',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_returns_uuid` (`uuid`),
  KEY `idx_returns_suborder` (`sub_order_id`),
  KEY `idx_returns_possale` (`pos_sale_id`),
  KEY `idx_returns_customer` (`customer_id`),
  KEY `idx_returns_status` (`status`),
  KEY `idx_returns_reason` (`reason_code_id`),
  CONSTRAINT `fk_returns_suborder` FOREIGN KEY (`sub_order_id`) REFERENCES `sub_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_returns_possale` FOREIGN KEY (`pos_sale_id`) REFERENCES `pos_sales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_returns_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_returns_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_returns_reason` FOREIGN KEY (`reason_code_id`) REFERENCES `rejection_reasons` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `return_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_id` BIGINT UNSIGNED NOT NULL,
  `order_item_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `pos_sale_item_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `qty` DECIMAL(15,3) NOT NULL,
  `refund_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `tax_reversed` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `condition` ENUM('resaleable','damaged') NOT NULL DEFAULT 'resaleable',
  `status` ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_return_items_return` (`return_id`),
  KEY `idx_return_items_oitem` (`order_item_id`),
  KEY `idx_return_items_pitem` (`pos_sale_item_id`),
  CONSTRAINT `fk_return_items_return` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_return_items_oitem` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_return_items_pitem` FOREIGN KEY (`pos_sale_item_id`) REFERENCES `pos_sale_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `credit_notes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `return_id` BIGINT UNSIGNED NOT NULL,
  `sub_order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `pos_sale_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `credit_note_no` VARCHAR(40) NOT NULL,
  `cn_date` DATE NOT NULL,
  `taxable_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cgst_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `grand_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `media_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('generated','sent','cancelled') NOT NULL DEFAULT 'generated',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_credit_notes_uuid` (`uuid`),
  UNIQUE KEY `uq_credit_notes_no` (`credit_note_no`),
  KEY `idx_credit_notes_return` (`return_id`),
  KEY `idx_credit_notes_media` (`media_id`),
  CONSTRAINT `fk_credit_notes_return` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_credit_notes_media` FOREIGN KEY (`media_id`) REFERENCES `media_assets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `credit_note_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `credit_note_id` BIGINT UNSIGNED NOT NULL,
  `order_item_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `description` VARCHAR(191) NOT NULL,
  `hsn` VARCHAR(8) NULL DEFAULT NULL,
  `qty` DECIMAL(15,3) NOT NULL,
  `taxable_value` DECIMAL(15,4) NOT NULL,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `cgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `line_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cnl_cn` (`credit_note_id`),
  KEY `idx_cnl_item` (`order_item_id`),
  CONSTRAINT `fk_cnl_cn` FOREIGN KEY (`credit_note_id`) REFERENCES `credit_notes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cnl_item` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- G. DELIVERY
-- ---------------------------------------------------------------------

CREATE TABLE `deliveries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `sub_order_id` BIGINT UNSIGNED NOT NULL,
  `shop_id` BIGINT UNSIGNED NOT NULL,
  `mode` ENUM('self','pool','3pl','pickup') NOT NULL DEFAULT 'self',
  `delivery_fee` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `eta_at` DATETIME NULL DEFAULT NULL,
  `pod_type` ENUM('otp','photo','signature') NULL DEFAULT NULL,
  `pod_ref` VARCHAR(120) NULL DEFAULT NULL,
  `status` ENUM('pending','assigned','picked_up','out_for_delivery','delivered','failed','returned') NOT NULL DEFAULT 'pending',
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `origin` VARCHAR(40) NOT NULL DEFAULT 'server',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_deliveries_uuid` (`uuid`),
  KEY `idx_deliveries_suborder` (`sub_order_id`),
  KEY `idx_deliveries_status` (`status`),
  KEY `idx_deliveries_shop` (`shop_id`),
  CONSTRAINT `fk_deliveries_suborder` FOREIGN KEY (`sub_order_id`) REFERENCES `sub_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_deliveries_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `delivery_assignments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_id` BIGINT UNSIGNED NOT NULL,
  `rider_user_id` BIGINT UNSIGNED NOT NULL,
  `offered_at` DATETIME NULL DEFAULT NULL,
  `accepted_at` DATETIME NULL DEFAULT NULL,
  `declined_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('offered','accepted','declined','reassigned','completed') NOT NULL DEFAULT 'offered',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dassign_delivery` (`delivery_id`),
  KEY `idx_dassign_rider` (`rider_user_id`),
  CONSTRAINT `fk_dassign_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dassign_rider` FOREIGN KEY (`rider_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `delivery_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_id` BIGINT UNSIGNED NOT NULL,
  `from_status` VARCHAR(40) NULL DEFAULT NULL,
  `to_status` VARCHAR(40) NOT NULL,
  `actor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `latitude` DECIMAL(10,7) NULL DEFAULT NULL,
  `longitude` DECIMAL(10,7) NULL DEFAULT NULL,
  `note` VARCHAR(191) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dsh_delivery` (`delivery_id`,`created_at`),
  CONSTRAINT `fk_dsh_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dsh_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cod_collections` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_id` BIGINT UNSIGNED NOT NULL,
  `payment_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `collected_at` DATETIME NULL DEFAULT NULL,
  `deposited_at` DATETIME NULL DEFAULT NULL,
  `reconciled_at` DATETIME NULL DEFAULT NULL,
  `variance` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `status` ENUM('pending','collected','deposited','reconciled','short') NOT NULL DEFAULT 'pending',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cod_delivery` (`delivery_id`,`status`),
  KEY `idx_cod_payment` (`payment_id`),
  CONSTRAINT `fk_cod_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cod_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rider_locations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rider_user_id` BIGINT UNSIGNED NOT NULL,
  `latitude` DECIMAL(10,7) NOT NULL,
  `longitude` DECIMAL(10,7) NOT NULL,
  `accuracy` DECIMAL(8,2) NULL DEFAULT NULL,
  `recorded_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rider_locations` (`rider_user_id`,`recorded_at`),
  CONSTRAINT `fk_rider_locations_rider` FOREIGN KEY (`rider_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- H. REVIEWS & RATINGS
-- ---------------------------------------------------------------------

CREATE TABLE `reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `sub_order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `rating` TINYINT UNSIGNED NOT NULL,
  `title` VARCHAR(191) NULL DEFAULT NULL,
  `body` TEXT NULL,
  `is_verified_purchase` TINYINT(1) NOT NULL DEFAULT 0,
  `vendor_response` TEXT NULL,
  `status` ENUM('pending','published','rejected','flagged') NOT NULL DEFAULT 'pending',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reviews_uuid` (`uuid`),
  UNIQUE KEY `uq_reviews_unique` (`product_id`,`customer_id`,`sub_order_id`),
  KEY `idx_reviews_product` (`product_id`,`status`),
  KEY `idx_reviews_customer` (`customer_id`),
  CONSTRAINT `fk_reviews_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_reviews_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_reviews_suborder` FOREIGN KEY (`sub_order_id`) REFERENCES `sub_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `review_media` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `review_id` BIGINT UNSIGNED NOT NULL,
  `media_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_review_media_review` (`review_id`),
  KEY `idx_review_media_media` (`media_id`),
  CONSTRAINT `fk_review_media_review` FOREIGN KEY (`review_id`) REFERENCES `reviews` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_review_media_media` FOREIGN KEY (`media_id`) REFERENCES `media_assets` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `review_votes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `review_id` BIGINT UNSIGNED NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `vote` ENUM('up','down') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_review_votes` (`review_id`,`customer_id`),
  KEY `idx_review_votes_customer` (`customer_id`),
  CONSTRAINT `fk_review_votes_review` FOREIGN KEY (`review_id`) REFERENCES `reviews` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_review_votes_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `review_reports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `review_id` BIGINT UNSIGNED NOT NULL,
  `reporter_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `reason` VARCHAR(191) NULL DEFAULT NULL,
  `status` ENUM('open','reviewed','dismissed','actioned') NOT NULL DEFAULT 'open',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_review_reports_review` (`review_id`),
  CONSTRAINT `fk_review_reports_review` FOREIGN KEY (`review_id`) REFERENCES `reviews` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_review_reports_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rating_aggregates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `avg_rating` DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  `count_total` INT UNSIGNED NOT NULL DEFAULT 0,
  `count_1` INT UNSIGNED NOT NULL DEFAULT 0,
  `count_2` INT UNSIGNED NOT NULL DEFAULT 0,
  `count_3` INT UNSIGNED NOT NULL DEFAULT 0,
  `count_4` INT UNSIGNED NOT NULL DEFAULT 0,
  `count_5` INT UNSIGNED NOT NULL DEFAULT 0,
  `recalculated_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rating_agg_product` (`product_id`),
  CONSTRAINT `fk_rating_agg_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- I. FINANCIAL (commission & settlement)
-- ---------------------------------------------------------------------

CREATE TABLE `commission_ledger` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `sub_order_id` BIGINT UNSIGNED NOT NULL,
  `order_item_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `base_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `commission_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `funded_adjustment` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `settled_in_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('accrued','settled','reversed') NOT NULL DEFAULT 'accrued',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_commission_ledger_uuid` (`uuid`),
  KEY `idx_comm_ledger_vendor` (`vendor_id`,`status`),
  KEY `idx_comm_ledger_suborder` (`sub_order_id`),
  KEY `idx_comm_ledger_settlement` (`settled_in_id`),
  CONSTRAINT `fk_comm_ledger_suborder` FOREIGN KEY (`sub_order_id`) REFERENCES `sub_orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_comm_ledger_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_comm_ledger_settlement` FOREIGN KEY (`settled_in_id`) REFERENCES `settlements` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settlements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `gross` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `commission_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `refund_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `tcs` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `tds` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `fees` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `adjustments` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `net_payable` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `idempotency_key` VARCHAR(64) NOT NULL,
  `status` ENUM('draft','calculated','approved','paid','held','failed') NOT NULL DEFAULT 'draft',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settlements_uuid` (`uuid`),
  UNIQUE KEY `uq_settlements_idem` (`idempotency_key`),
  KEY `idx_settlements_vendor` (`vendor_id`,`period_end`),
  KEY `idx_settlements_status` (`status`),
  CONSTRAINT `fk_settlements_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settlement_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `settlement_id` BIGINT UNSIGNED NOT NULL,
  `ref_type` ENUM('sale','refund','commission','adjustment','tcs','tds') NOT NULL,
  `ref_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `sub_order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `direction` ENUM('credit','debit') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_settlement_lines_settlement` (`settlement_id`),
  KEY `idx_settlement_lines_suborder` (`sub_order_id`),
  CONSTRAINT `fk_settlement_lines_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_settlement_lines_suborder` FOREIGN KEY (`sub_order_id`) REFERENCES `sub_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payout_batches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `gateway` VARCHAR(40) NULL DEFAULT NULL,
  `total_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `count` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('created','processing','completed','partial','failed') NOT NULL DEFAULT 'created',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payout_batches_uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payouts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `settlement_id` BIGINT UNSIGNED NOT NULL,
  `payout_batch_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `vendor_bank_account_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `gateway` VARCHAR(40) NULL DEFAULT NULL,
  `gateway_ref` VARCHAR(120) NULL DEFAULT NULL,
  `idempotency_key` VARCHAR(64) NOT NULL,
  `approved_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `attempts` INT NOT NULL DEFAULT 0,
  `status` ENUM('pending','processing','paid','failed','held') NOT NULL DEFAULT 'pending',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payouts_uuid` (`uuid`),
  UNIQUE KEY `uq_payouts_idem` (`idempotency_key`),
  KEY `idx_payouts_settlement` (`settlement_id`,`status`),
  KEY `idx_payouts_batch` (`payout_batch_id`),
  KEY `idx_payouts_bank` (`vendor_bank_account_id`),
  KEY `idx_payouts_approver` (`approved_by`),
  CONSTRAINT `fk_payouts_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_payouts_batch` FOREIGN KEY (`payout_batch_id`) REFERENCES `payout_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payouts_bank` FOREIGN KEY (`vendor_bank_account_id`) REFERENCES `vendor_bank_accounts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_payouts_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settlement_adjustments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `settlement_id` BIGINT UNSIGNED NOT NULL,
  `type` ENUM('penalty','bonus','dispute_hold','manual') NOT NULL,
  `amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `reason` VARCHAR(191) NULL DEFAULT NULL,
  `status` ENUM('applied','reversed') NOT NULL DEFAULT 'applied',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_settlement_adj_settlement` (`settlement_id`),
  CONSTRAINT `fk_settlement_adj_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- J. LOYALTY & PROMO USAGE
-- ---------------------------------------------------------------------

CREATE TABLE `loyalty_points_ledger` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `type` ENUM('earn','burn','expire','reverse') NOT NULL,
  `points` INT NOT NULL,
  `balance_after` INT NOT NULL,
  `ref_type` VARCHAR(40) NULL DEFAULT NULL,
  `ref_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `expires_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loyalty_ledger_uuid` (`uuid`),
  KEY `idx_loyalty_ledger_customer` (`customer_id`,`created_at`),
  CONSTRAINT `fk_loyalty_ledger_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `referrals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `referrer_customer_id` BIGINT UNSIGNED NOT NULL,
  `referee_customer_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `code` VARCHAR(40) NOT NULL,
  `reward_points` INT NOT NULL DEFAULT 0,
  `reward_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `status` ENUM('pending','qualified','rewarded','expired') NOT NULL DEFAULT 'pending',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_referrals` (`code`,`referee_customer_id`),
  KEY `idx_referrals_referrer` (`referrer_customer_id`),
  CONSTRAINT `fk_referrals_referrer` FOREIGN KEY (`referrer_customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_referrals_referee` FOREIGN KEY (`referee_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `coupon_redemptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id` BIGINT UNSIGNED NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `discount_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `redeemed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_coupon_redemptions_coupon` (`coupon_id`),
  KEY `idx_coupon_redemptions_customer` (`customer_id`),
  KEY `idx_coupon_redemptions_order` (`order_id`),
  CONSTRAINT `fk_coupon_redemptions_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_coupon_redemptions_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_coupon_redemptions_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `promotion_usage` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `promotion_id` BIGINT UNSIGNED NOT NULL,
  `customer_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `benefit_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_promotion_usage_promo` (`promotion_id`),
  KEY `idx_promotion_usage_customer` (`customer_id`),
  CONSTRAINT `fk_promotion_usage_promo` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_promotion_usage_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- K. PLATFORM-SERVICE TRANSACTIONS (jobs, import/export, webhook & search logs)
-- ---------------------------------------------------------------------

CREATE TABLE `jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `queue` VARCHAR(40) NOT NULL DEFAULT 'default',
  `type` VARCHAR(80) NOT NULL,
  `payload` JSON NULL,
  `available_at` DATETIME NULL DEFAULT NULL,
  `reserved_at` DATETIME NULL DEFAULT NULL,
  `idempotency_key` VARCHAR(64) NULL DEFAULT NULL,
  `attempts` INT NOT NULL DEFAULT 0,
  `status` ENUM('queued','reserved','processing','done','failed') NOT NULL DEFAULT 'queued',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jobs_uuid` (`uuid`),
  UNIQUE KEY `uq_jobs_idem` (`idempotency_key`),
  KEY `idx_jobs_queue` (`queue`,`status`,`available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `job_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id` BIGINT UNSIGNED NOT NULL,
  `attempt_no` INT NOT NULL DEFAULT 1,
  `error` TEXT NULL,
  `started_at` DATETIME NULL DEFAULT NULL,
  `finished_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('ok','error') NOT NULL DEFAULT 'ok',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_job_attempts_job` (`job_id`),
  CONSTRAINT `fk_job_attempts_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `dead_letter_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `original_job_uuid` CHAR(36) NULL DEFAULT NULL,
  `queue` VARCHAR(40) NULL DEFAULT NULL,
  `type` VARCHAR(80) NULL DEFAULT NULL,
  `payload` JSON NULL,
  `last_error` TEXT NULL,
  `failed_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('open','retried','discarded') NOT NULL DEFAULT 'open',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dlj_queue` (`queue`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `import_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `type` ENUM('product','price','stock','customer') NOT NULL,
  `source_media_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `total_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `processed_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `error_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `requested_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('uploaded','validating','dry_run','processing','completed','failed') NOT NULL DEFAULT 'uploaded',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_import_jobs_uuid` (`uuid`),
  KEY `idx_import_jobs_status` (`status`),
  KEY `idx_import_jobs_media` (`source_media_id`),
  KEY `idx_import_jobs_requester` (`requested_by`),
  CONSTRAINT `fk_import_jobs_media` FOREIGN KEY (`source_media_id`) REFERENCES `media_assets` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_import_jobs_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `import_rows` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_job_id` BIGINT UNSIGNED NOT NULL,
  `row_no` INT UNSIGNED NOT NULL,
  `raw` JSON NULL,
  `errors` JSON NULL,
  `status` ENUM('valid','invalid','imported','skipped') NOT NULL DEFAULT 'valid',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_import_rows_job` (`import_job_id`,`status`),
  CONSTRAINT `fk_import_rows_job` FOREIGN KEY (`import_job_id`) REFERENCES `import_jobs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `export_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `type` VARCHAR(40) NOT NULL,
  `params` JSON NULL,
  `output_media_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `requested_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `row_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('queued','processing','completed','failed','expired') NOT NULL DEFAULT 'queued',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_export_jobs_uuid` (`uuid`),
  KEY `idx_export_jobs_status` (`status`),
  KEY `idx_export_jobs_media` (`output_media_id`),
  CONSTRAINT `fk_export_jobs_media` FOREIGN KEY (`output_media_id`) REFERENCES `media_assets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_export_jobs_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `webhook_deliveries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `webhook_subscription_id` BIGINT UNSIGNED NOT NULL,
  `event` VARCHAR(80) NOT NULL,
  `payload` JSON NULL,
  `signature` VARCHAR(255) NULL DEFAULT NULL,
  `response_code` INT NULL DEFAULT NULL,
  `attempt_no` INT NOT NULL DEFAULT 1,
  `delivered_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('pending','delivered','failed','dead') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_webhook_deliveries_sub` (`webhook_subscription_id`,`status`),
  CONSTRAINT `fk_webhook_deliveries_sub` FOREIGN KEY (`webhook_subscription_id`) REFERENCES `webhook_subscriptions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inbound_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` VARCHAR(40) NOT NULL,
  `event_id` VARCHAR(120) NOT NULL,
  `event_type` VARCHAR(80) NULL DEFAULT NULL,
  `payload` JSON NULL,
  `verified` TINYINT(1) NOT NULL DEFAULT 0,
  `processed_at` DATETIME NULL DEFAULT NULL,
  `status` ENUM('received','verified','processed','rejected','duplicate') NOT NULL DEFAULT 'received',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inbound_events` (`provider`,`event_id`),
  KEY `idx_inbound_events_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `search_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `query` VARCHAR(191) NOT NULL,
  `result_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `latitude` DECIMAL(10,7) NULL DEFAULT NULL,
  `longitude` DECIMAL(10,7) NULL DEFAULT NULL,
  `clicked_variant_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_search_logs_created` (`created_at`),
  KEY `idx_search_logs_customer` (`customer_id`),
  CONSTRAINT `fk_search_logs_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_search_logs_variant` FOREIGN KEY (`clicked_variant_id`) REFERENCES `product_variants` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `trending_terms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `term` VARCHAR(120) NOT NULL,
  `count` INT UNSIGNED NOT NULL DEFAULT 0,
  `window_start` DATETIME NULL DEFAULT NULL,
  `window_end` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trending_terms_term` (`term`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- End of 04_transaction.sql
