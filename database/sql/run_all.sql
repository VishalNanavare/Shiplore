-- =====================================================================
-- run_all.sql  |  Loads the entire schema in dependency-safe order.
-- Usage (from THIS directory, so SOURCE paths resolve):
--   Windows:  mysql -u root < run_all.sql
--   (or interactively)  mysql -u root  then:  SOURCE run_all.sql;
-- Database `test` is created by 00_init.sql.
-- FOREIGN_KEY_CHECKS stays 0 across files 00-08, re-enabled in 09.
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
SOURCE 60_delivery_otp_and_assignments.sql;
SOURCE 61_pos_gst_customer.sql;

-- NOTE (audit 2026-08): files 44-59 and 62-73 exist on disk but are not SOURCEd
-- above — a pre-existing gap, not introduced here. 74/75 are appended at the very
-- end rather than inserted in numeric order so they still run (and still land
-- after every base table they ALTER exists) regardless of that gap.
SOURCE 74_report_indexes.sql;
SOURCE 75_catalog_indexes.sql;
