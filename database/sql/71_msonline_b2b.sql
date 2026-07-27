-- 71_msonline_b2b.sql  |  msonline B2B purchase orders (phase B).
--
-- The marketplace at msonline.shiplore.in where VENDORS and SHOPS buy from
-- MANUFACTURERS. Deliberately separate tables from the consumer `orders` /
-- `sub_orders` pipeline:
--
--   * orders.customer_id is NOT NULL with an FK to `customers`, so a business buyer
--     cannot be represented without either relaxing that FK or minting shadow customer
--     rows. Both put a new B2B flow inside the live consumer checkout path.
--   * the consumer pipeline carries delivery/rider/COD concerns a PO does not have.
--
-- What is REUSED rather than rebuilt:
--   * MOQ           -> products.min_purchase_qty + qty_step, validated by the existing
--                      App\Libraries\Catalog\PurchaseRules. No new MOQ column.
--   * stock-in      -> App\Libraries\Inventory\InventoryService::receive(), which is
--                      tenant-agnostic (it takes variantId + shopId and leaves ownership
--                      to the caller), writing inventory + inventory_ledger as usual.
--   * GST           -> App\Libraries\Gst\GstCalculator, via place_of_supply below.
--
-- Payment is intentionally absent: this phase is purchase-order only, settled offline.
--
-- Fully idempotent — safe to re-run.
--
-- Apply: php database/apply_sql.php database/sql/71_msonline_b2b.sql

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- A. Purchase order header
--
-- buyer_*  = the vendor/shop placing the order   (FK -> vendors, shops)
-- seller_* = the manufacturer fulfilling it      (FK -> vendors, mshops)
--
-- buyer_shop_id is NOT NULL: the goods must land somewhere, and it is what
-- InventoryService::receive() is given on receipt. It also lets a branch manager be
-- restricted to ordering into their own shop.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mfg_purchase_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `po_no` VARCHAR(40) NOT NULL,
  `buyer_vendor_id` BIGINT UNSIGNED NOT NULL,
  `buyer_shop_id` BIGINT UNSIGNED NOT NULL COMMENT 'destination for the goods',
  `seller_vendor_id` BIGINT UNSIGNED NOT NULL COMMENT 'the manufacturer (vendors.party_type=manufacturer)',
  `seller_mshop_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'fulfilling unit, set on accept',
  `buyer_gstin` VARCHAR(20) NULL DEFAULT NULL,
  `place_of_supply` CHAR(2) NULL DEFAULT NULL COMMENT 'buyer state; drives intra vs inter-state GST',
  `subtotal` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `discount_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `taxable_value` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `round_off` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `grand_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  -- Lifecycle mirrors the stock-transfer state machine, which already models
  -- "goods move between two locations with an approval and a receipt".
  `status` ENUM('draft','placed','accepted','rejected','packed','dispatched','received','partially_received','closed','cancelled') NOT NULL DEFAULT 'draft',
  `notes` VARCHAR(500) NULL DEFAULT NULL,
  `reject_reason` VARCHAR(255) NULL DEFAULT NULL,
  `placed_at` DATETIME NULL DEFAULT NULL,
  `accepted_at` DATETIME NULL DEFAULT NULL,
  `dispatched_at` DATETIME NULL DEFAULT NULL,
  `received_at` DATETIME NULL DEFAULT NULL,
  `placed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `accepted_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `received_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mpo_uuid` (`uuid`),
  UNIQUE KEY `uq_mpo_no` (`po_no`),
  KEY `idx_mpo_buyer` (`buyer_vendor_id`,`status`),
  KEY `idx_mpo_buyer_shop` (`buyer_shop_id`),
  KEY `idx_mpo_seller` (`seller_vendor_id`,`status`),
  KEY `idx_mpo_placed` (`placed_at`),
  CONSTRAINT `fk_mpo_buyer_vendor` FOREIGN KEY (`buyer_vendor_id`) REFERENCES `vendors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_mpo_buyer_shop` FOREIGN KEY (`buyer_shop_id`) REFERENCES `shops` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_mpo_seller_vendor` FOREIGN KEY (`seller_vendor_id`) REFERENCES `vendors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_mpo_seller_mshop` FOREIGN KEY (`seller_mshop_id`) REFERENCES `mshops` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- B. Purchase order lines
--
-- unit_price is SNAPSHOTTED at placement. It is the manufacturer's SELLING price
-- (product_variants.base_price) — never making_price, which is their internal
-- production cost and must not reach the buyer in any form, including a stored row.
--
-- qty_received supports partial receipt; the header goes to 'partially_received'
-- when some but not all lines are fully received.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mfg_purchase_order_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `po_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `product_title_snapshot` VARCHAR(191) NOT NULL,
  `sku_snapshot` VARCHAR(64) NULL DEFAULT NULL,
  `hsn_snapshot` VARCHAR(20) NULL DEFAULT NULL,
  `qty` DECIMAL(15,3) NOT NULL,
  `qty_received` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `unit_price` DECIMAL(15,4) NOT NULL COMMENT 'seller selling price at placement; NEVER making_price',
  `discount_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `taxable_value` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `cgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `sgst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `igst` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `cess` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `line_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `status` ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mpoi_po` (`po_id`),
  KEY `idx_mpoi_variant` (`variant_id`),
  CONSTRAINT `fk_mpoi_po` FOREIGN KEY (`po_id`) REFERENCES `mfg_purchase_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mpoi_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- C. Permissions
--
-- Buyer-side codes are scope_class 'vendor' so vendor_owner picks them up through
-- the existing bulk grant. Seller-side (mfg.po.*) already exist from 70_manufacturer.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`code`,`module`,`action`,`scope_class`,`description`) VALUES
 ('msonline.browse','msonline','browse','vendor','Browse the wholesale marketplace'),
 ('msonline.order','msonline','order','vendor','Place wholesale purchase orders'),
 ('msonline.po.view','msonline','po.view','vendor','View own purchase orders'),
 ('msonline.po.receive','msonline','po.receive','shop','Mark a purchase order received');

-- vendor_owner: bulk-granted by scope_class IN ('vendor','shop') — re-run so the new
-- codes above are picked up (11_seed.sql:232-239 does the same after each batch).
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.scope_class IN ('vendor','shop')
 WHERE r.code = 'vendor_owner';

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('msonline.browse','msonline.order','msonline.po.view','msonline.po.receive')
 WHERE r.code = 'vendor_manager';

-- A branch manager receives stock into their own shop but does not order.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('msonline.browse','msonline.po.view','msonline.po.receive')
 WHERE r.code = 'vendor_shop_manager';

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'msonline B2B purchase-order migration applied' AS result;
