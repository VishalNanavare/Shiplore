-- =====================================================================
-- run_all.sql  |  Loads the entire schema in dependency-safe order.
-- Usage (from THIS directory, so SOURCE paths resolve):
--   Windows:  mysql -u root < run_all.sql
--   (or interactively)  mysql -u root  then:  SOURCE run_all.sql;
-- Database `test` is created by 00_init.sql.
-- FOREIGN_KEY_CHECKS stays 0 across files 00-08, re-enabled in 09.
--
-- REQUIRES MariaDB. Four files (54_product_shops_index, 74_report_indexes,
-- 75_catalog_indexes, 43_product_shops) use `ALTER TABLE ... ADD INDEX IF NOT
-- EXISTS`, which is MariaDB-only. Production is MariaDB 10.11, so they apply
-- there. On MySQL every one of those statements is a syntax error: the load
-- either halts, or with --force silently continues and leaves the database
-- missing NINE indexes — including idx_products_cat_status_del and
-- idx_ps_product_status, the two the storefront's query plan depends on. A
-- MySQL-built copy comes up looking complete and performs nothing like
-- production. Verified against MySQL 9.6 on 2026-08-07.
--
-- `SOURCE` is a mysql/mariadb CLIENT command. The MySQL 9.6 client does not
-- accept it at all (not in batch, not via -e), so on that client this file
-- cannot be used as written; expand the includes or use the MariaDB client.
-- =====================================================================

SOURCE 00_init.sql;
SOURCE 01_master.sql;
SOURCE 02_configuration.sql;
SOURCE 03_gst.sql;
SOURCE 04_transaction.sql;
SOURCE 05_payment.sql;
SOURCE 06_sync.sql;
SOURCE 07_notification.sql;
SOURCE 08_audit.sql;
SOURCE 10_staff.sql;
SOURCE 12_gst_verification.sql;
SOURCE 13_business_type_commission.sql;
SOURCE 14_warehouse_franchise_ops.sql;
SOURCE 09_finalize.sql;
SOURCE 11_seed.sql;
SOURCE 15_support_backup.sql;
SOURCE 16_product_module.sql;
SOURCE 17_product_variants.sql;
SOURCE 18_product_pricing.sql;
SOURCE 19_product_types.sql;
SOURCE 20_product_ai_rental.sql;
SOURCE 21_product_barcodes.sql;
SOURCE 22_product_autosave.sql;
SOURCE 23_stock_transfers.sql;
SOURCE 24_pos_web.sql;
SOURCE 25_pos_billing_modes.sql;
SOURCE 26_barcode_conflict.sql;
SOURCE 27_pos_permissions.sql;
SOURCE 28_pos_returns.sql;
SOURCE 29_pos_delivery.sql;
SOURCE 30_governance.sql;
SOURCE 31_commission_hold.sql;
SOURCE 32_invoicing.sql;
SOURCE 33_documents_access.sql;
SOURCE 34_governance_perms.sql;
SOURCE 35_returns_perms.sql;
SOURCE 36_x5_ops.sql;
SOURCE 37_shop_console_perms.sql;
SOURCE 38_shop_product_perms.sql;
SOURCE 39_shop_console_extras.sql;
SOURCE 40_pos_billing.sql;
SOURCE 41_media_library.sql;
SOURCE 42_staff_request_perm.sql;
SOURCE 43_product_shops.sql;
SOURCE 44_shop_delivery_rules.sql;
SOURCE 45_vertical_lifecycle.sql;
SOURCE 46_quickcommerce.sql;
SOURCE 46_rider_documents.sql;
SOURCE 47_address_map.sql;
SOURCE 47_delivery_reviews_disputes.sql;
SOURCE 48_address_contact.sql;
SOURCE 48_rider_finance.sql;
SOURCE 49_rider_live.sql;
SOURCE 50_banners_extend.sql;
SOURCE 50_rider_assign_unique.sql;
SOURCE 51_api_idempotency.sql;
SOURCE 52_auth_otp_pod.sql;
SOURCE 53_purchase_intake.sql;
SOURCE 54_product_shops_index.sql;
SOURCE 55_customer_app_round2.sql;
SOURCE 60_delivery_otp_and_assignments.sql;
SOURCE 61_pos_gst_customer.sql;
SOURCE 62_order_claim.sql;
SOURCE 63_escalation_reminder_count.sql;
SOURCE 64_claim_log_events.sql;
SOURCE 65_delivery_arrived.sql;
SOURCE 66_order_manage_permission.sql;
SOURCE 67_claim_log_de_escalated.sql;
SOURCE 68_customer_payment_instruments.sql;
SOURCE 69_auth_otp_register_purposes.sql;
SOURCE 70_manufacturer.sql;
SOURCE 71_monline_b2b.sql;
SOURCE 72_monline_permission_rename.sql;
SOURCE 73_admin_manufacturer_oversight.sql;

-- Files 44-55 and 62-73 are SOURCEd above, in numeric order at the points where
-- their dependencies exist. They were missing until audit 2026-08-B: a database
-- built from this file had no idx_ps_product_status (54) — the covering index the
-- storefront depends on — and none of the manufacturer/monline tables (70-73).
-- 56-59 do not exist on disk; the numbering simply skips them.
--
-- Deliberately NOT sourced:
--   14_vendor_business_type_assign.sql  one-off backfill of EXISTING vendor rows;
--                                       a fresh database has nothing to backfill.
--   99_demo_seed.sql, 99b_demo_staff_logins.sql, manufacturer_products_demo.sql
--                                       demo data, not schema. Load by hand.
SOURCE 74_report_indexes.sql;
SOURCE 75_catalog_indexes.sql;
