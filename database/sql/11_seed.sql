-- =====================================================================
-- File 11: SEED DATA (Phase 3) — makes the schema runnable.
-- Idempotent: re-runnable via INSERT IGNORE on natural unique keys
-- (code / slug / uuid / email) and explicit stable ids for demo rows.
-- FOREIGN_KEY_CHECKS stays ON so seed referential integrity is validated.
--
-- Sections:
--   A. Reference masters (languages, permissions, roles, role_permissions)
--   B. Catalog/finance masters (units, tax, hsn, gst_config, payment, plans)
--   C. Platform config (settings, feature flags, ledger accounts)
--   D. Bootstrap super-admin
--   E. DEMO chain (vendor -> shop -> staff -> delivery boy -> product ...)
--
-- DEV CREDENTIAL: super admin  email=admin@platform.local  password=password
--   (bcrypt hash below is the canonical PHP example hash for "password").
--   >>> CHANGE THIS IMMEDIATELY in any non-local environment. <<<
-- =====================================================================
USE `test`;

-- ---------------------------------------------------------------------
-- A1. Languages
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `languages` (`code`,`name`,`is_rtl`,`is_default`,`status`) VALUES
 ('en','English',0,1,'active'),
 ('hi','Hindi',0,0,'active');

-- ---------------------------------------------------------------------
-- A2. Permissions catalog (module.action, scope_class)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`code`,`module`,`action`,`scope_class`,`description`) VALUES
 ('session.view','session','view','platform','View sessions'),
 ('session.revoke','session','revoke','platform','Revoke sessions'),
 ('user.view','user','view','platform','View users'),
 ('user.create','user','create','platform','Create users'),
 ('user.update','user','update','platform','Update users'),
 ('user.suspend','user','suspend','platform','Suspend users'),
 ('user.invite','user','invite','platform','Invite users'),
 ('user.impersonate','user','impersonate','platform','Impersonate users'),
 ('role.view','rbac','view','platform','View roles'),
 ('role.create','rbac','create','platform','Create roles'),
 ('role.update','rbac','update','platform','Update roles'),
 ('role.delete','rbac','delete','platform','Delete roles'),
 ('role.assign','rbac','assign','platform','Assign roles'),
 ('vendor.view','vendor','view','platform','View vendors'),
 ('vendor.create','vendor','create','platform','Create vendors'),
 ('vendor.update','vendor','update','vendor','Update vendor'),
 ('vendor.kyc.review','vendor','kyc.review','platform','Review vendor KYC'),
 ('vendor.approve','vendor','approve','platform','Approve vendor'),
 ('vendor.reject','vendor','reject','platform','Reject vendor'),
 ('vendor.suspend','vendor','suspend','platform','Suspend vendor'),
 ('vendor.settings.manage','vendor','settings.manage','vendor','Manage vendor settings'),
 ('vendor_staff.manage','vendor','staff.manage','vendor','Manage vendor staff & delivery boys'),
 ('shop.view','shop','view','vendor','View shops'),
 ('shop.create','shop','create','vendor','Create shops'),
 ('shop.update','shop','update','shop','Update shop'),
 ('shop.hours.manage','shop','hours.manage','shop','Manage shop hours'),
 ('shop.serviceability.manage','shop','serviceability.manage','shop','Manage serviceability'),
 ('settings.view','settings','view','platform','View settings'),
 ('settings.update','settings','update','platform','Update settings'),
 ('featureflag.manage','settings','featureflag.manage','platform','Manage feature flags'),
 ('category.view','category','view','platform','View categories'),
 ('category.create','category','create','platform','Create categories'),
 ('category.update','category','update','platform','Update categories'),
 ('category.delete','category','delete','platform','Delete categories'),
 ('brand.view','brand','view','platform','View brands'),
 ('brand.create','brand','create','vendor','Create/request brands'),
 ('brand.update','brand','update','platform','Update brands'),
 ('brand.approve','brand','approve','platform','Approve brands'),
 ('attribute.view','attribute','view','platform','View attributes'),
 ('attribute.manage','attribute','manage','platform','Manage attributes'),
 ('variant.manage','attribute','variant.manage','vendor','Manage variants'),
 ('unit.view','unit','view','platform','View units'),
 ('unit.manage','unit','manage','platform','Manage units'),
 ('tax.view','tax','view','platform','View tax'),
 ('tax.manage','tax','manage','platform','Manage tax classes/rates'),
 ('hsn.manage','tax','hsn.manage','platform','Manage HSN/SAC'),
 ('gst.report.view','tax','gst.report.view','vendor','View GST reports'),
 ('gst.report.export','tax','gst.report.export','platform','Export GSTR data'),
 ('product.view','product','view','vendor','View products'),
 ('product.create','product','create','vendor','Create products'),
 ('product.update','product','update','vendor','Update products'),
 ('product.delete','product','delete','vendor','Delete products'),
 ('product.submit','product','submit','vendor','Submit products for approval'),
 ('product.import','product','import','vendor','Bulk import products'),
 ('product.review','product','review','platform','Open moderation queue'),
 ('product.approve','product','approve','platform','Approve products'),
 ('product.reject','product','reject','platform','Reject products'),
 ('inventory.view','inventory','view','shop','View inventory'),
 ('inventory.adjust','inventory','adjust','shop','Adjust stock'),
 ('inventory.transfer','inventory','transfer','vendor','Transfer stock'),
 ('inventory.batch.manage','inventory','batch.manage','shop','Manage batches'),
 ('pricing.view','pricing','view','vendor','View pricing'),
 ('pricing.manage','pricing','manage','vendor','Manage pricing'),
 ('pricing.pos.override','pricing','pos.override','shop','POS price override'),
 ('promotion.view','promotion','view','vendor','View promotions'),
 ('promotion.manage','promotion','manage','vendor','Manage promotions'),
 ('coupon.manage','promotion','coupon.manage','vendor','Manage coupons'),
 ('media.upload','media','upload','vendor','Upload media'),
 ('media.view','media','view','vendor','View media'),
 ('media.delete','media','delete','vendor','Delete media'),
 ('checkout.place','checkout','place','self','Place order'),
 ('order.view','order','view','platform','View all orders'),
 ('order.view.own','order','view.own','vendor','View own orders'),
 ('order.update.status','order','update.status','shop','Update order status'),
 ('order.cancel','order','cancel','vendor','Cancel order'),
 ('order.refund.request','order','refund.request','vendor','Request refund'),
 ('invoice.view','order','invoice.view','vendor','View invoice'),
 ('pos.bootstrap','pos','bootstrap','shop','POS bootstrap download'),
 ('pos.sell','pos','sell','shop','POS sell'),
 ('pos.discount.apply','pos','discount.apply','shop','POS apply discount'),
 ('pos.price.override','pos','price.override','shop','POS price override'),
 ('pos.return.process','pos','return.process','shop','POS process return'),
 ('pos.shift.manage','pos','shift.manage','shop','POS manage shift'),
 ('pos.sync','pos','sync','shop','POS sync'),
 ('pos.terminal.activate','pos','terminal.activate','vendor','Activate POS terminal'),
 ('return.view','return','view','vendor','View returns'),
 ('return.approve','return','approve','vendor','Approve returns'),
 ('return.refund','return','refund','vendor','Refund returns'),
 ('creditnote.view','return','creditnote.view','vendor','View credit notes'),
 ('delivery.view','delivery','view','vendor','View deliveries'),
 ('delivery.assign','delivery','assign','shop','Assign deliveries'),
 ('delivery.update','delivery','update','self','Update assigned delivery'),
 ('rider.manage','delivery','rider.manage','vendor','Manage delivery boys'),
 ('rider.self.update','delivery','rider.self.update','self','Delivery boy self update'),
 ('geo.manage','geo','manage','platform','Manage geo'),
 ('zone.manage','geo','zone.manage','platform','Manage zones'),
 ('search.config.manage','search','config.manage','platform','Manage search config'),
 ('payment.view','payment','view','platform','View payments'),
 ('payment.refund','payment','refund','platform','Refund payments'),
 ('payment.reconcile','payment','reconcile','platform','Reconcile payments'),
 ('gateway.config.manage','payment','gateway.config.manage','platform','Manage gateway config'),
 ('commission.view','commission','view','platform','View commission'),
 ('commission.manage','commission','manage','platform','Manage commission'),
 ('commission.override','commission','override','platform','Override commission'),
 ('wallet.view','wallet','view','platform','View wallets'),
 ('wallet.view.own','wallet','view.own','self','View own wallet'),
 ('wallet.adjust','wallet','adjust','platform','Adjust wallet'),
 ('ledger.view','wallet','ledger.view','platform','View ledger'),
 ('settlement.view','settlement','view','vendor','View settlements'),
 ('settlement.run','settlement','run','platform','Run settlement'),
 ('payout.approve','settlement','payout.approve','platform','Approve payout'),
 ('payout.retry','settlement','payout.retry','platform','Retry payout'),
 ('payout.hold','settlement','payout.hold','platform','Hold payout'),
 ('statement.view.own','settlement','statement.view.own','vendor','View own statement'),
 ('customer.view','customer','view','platform','View customers'),
 ('customer.update','customer','update','platform','Update customers'),
 ('customer.segment.manage','customer','segment.manage','platform','Manage segments'),
 ('customer.merge','customer','merge','platform','Merge customers'),
 ('loyalty.view','loyalty','view','platform','View loyalty'),
 ('loyalty.manage','loyalty','manage','platform','Manage loyalty'),
 ('loyalty.redeem','loyalty','redeem','self','Redeem loyalty'),
 ('review.create','review','create','self','Create review'),
 ('review.moderate','review','moderate','platform','Moderate reviews'),
 ('review.respond','review','respond','vendor','Respond to reviews'),
 ('cms.view','cms','view','platform','View CMS'),
 ('cms.manage','cms','manage','platform','Manage CMS'),
 ('banner.manage','cms','banner.manage','platform','Manage banners'),
 ('notification.send','notification','send','platform','Send notifications'),
 ('campaign.manage','notification','campaign.manage','platform','Manage campaigns'),
 ('notification.view.own','notification','view.own','self','View own notifications'),
 ('report.view','report','view','vendor','View reports'),
 ('report.export','report','export','vendor','Export reports'),
 ('audit.view','audit','view','platform','View audit logs'),
 ('audit.view.own','audit','view.own','vendor','View own audit logs'),
 ('import.run','import','run','vendor','Run imports'),
 ('export.run','export','run','vendor','Run exports'),
 ('integration.manage','integration','manage','platform','Manage integrations'),
 ('webhook.manage','integration','webhook.manage','platform','Manage webhooks'),
 ('job.view','job','view','platform','View jobs'),
 ('job.retry','job','retry','platform','Retry jobs'),
 ('i18n.manage','i18n','manage','platform','Manage translations'),
 ('dashboard.view','dashboard','view','vendor','View dashboard');

-- ---------------------------------------------------------------------
-- A3. Roles (all system templates; vendor_id NULL; assigned with scope)
-- ---------------------------------------------------------------------
-- Explicit ids => idempotent via PRIMARY KEY (vendor_id is NULL for system
-- roles, and MySQL treats NULL as distinct in the (code,vendor_id) unique key,
-- so INSERT IGNORE alone would NOT dedupe on re-run).
INSERT IGNORE INTO `roles` (`id`,`code`,`name`,`scope_class`,`vendor_id`,`is_system`,`status`) VALUES
 (1,'super_admin','Super Admin','platform',NULL,1,'active'),
 (2,'platform_admin','Platform Admin','platform',NULL,1,'active'),
 (3,'ops_manager','Operations Manager','platform',NULL,1,'active'),
 (4,'catalog_manager','Catalog Manager','platform',NULL,1,'active'),
 (5,'finance','Finance / Accounts','platform',NULL,1,'active'),
 (6,'support','Support Agent','platform',NULL,1,'active'),
 (7,'content_manager','Content Manager','platform',NULL,1,'active'),
 (8,'risk_compliance','Risk / Compliance','platform',NULL,1,'active'),
 (9,'readonly_auditor','Read-Only Auditor','platform',NULL,1,'active'),
 (10,'vendor_owner','Vendor Owner','vendor',NULL,1,'active'),
 (11,'vendor_manager','Vendor Manager','vendor',NULL,1,'active'),
 (12,'vendor_catalog_operator','Catalog Operator (Vendor)','vendor',NULL,1,'active'),
 (13,'vendor_shop_manager','Branch Manager','shop',NULL,1,'active'),
 (14,'vendor_pos_cashier','POS Cashier','shop',NULL,1,'active'),
 (15,'vendor_packer','Packer','shop',NULL,1,'active'),
 (16,'vendor_helper','Helper','shop',NULL,1,'active'),
 (17,'vendor_delivery','Delivery Boy','self',NULL,1,'active'),
 (18,'vendor_finance_viewer','Finance Viewer (Vendor)','vendor',NULL,1,'active'),
 (19,'customer','Customer','self',NULL,1,'active'),
 (20,'rider','Delivery Rider','self',NULL,1,'active');

-- ---------------------------------------------------------------------
-- A4. role_permissions
--   super_admin: ALL. Others: scoped/targeted sets.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r CROSS JOIN `permissions` p WHERE r.code='super_admin';

-- platform_admin: all platform-scope perms except root-only ones
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.scope_class='platform'
 WHERE r.code='platform_admin'
   AND p.code NOT IN ('role.create','role.update','role.delete','settings.update',
                      'featureflag.manage','gateway.config.manage','user.impersonate');

-- catalog_manager
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('category.view','category.create','category.update','category.delete','brand.view',
   'brand.update','brand.approve','attribute.view','attribute.manage','tax.view','tax.manage',
   'hsn.manage','product.review','product.approve','product.reject','product.view')
 WHERE r.code='catalog_manager';

-- finance
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('settlement.view','settlement.run','payout.approve','payout.retry','payout.hold',
   'commission.view','commission.manage','payment.view','payment.refund','payment.reconcile',
   'gst.report.view','gst.report.export','ledger.view','wallet.view','report.view','report.export')
 WHERE r.code='finance';

-- vendor_owner: every vendor- and shop-scoped permission + own-scope reads
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.scope_class IN ('vendor','shop')
 WHERE r.code='vendor_owner';
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('wallet.view.own','notification.view.own')
 WHERE r.code='vendor_owner';

-- vendor_manager
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('product.view','product.create','product.update','product.submit','product.import',
   'variant.manage','inventory.view','inventory.adjust','inventory.transfer','inventory.batch.manage',
   'pricing.view','pricing.manage','promotion.view','promotion.manage','coupon.manage',
   'order.view.own','order.update.status','order.cancel','invoice.view','media.upload','media.view',
   'report.view','dashboard.view')
 WHERE r.code='vendor_manager';

-- vendor_shop_manager (branch manager)
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('shop.update','shop.hours.manage','shop.serviceability.manage','inventory.view','inventory.adjust',
   'inventory.batch.manage','order.view.own','order.update.status','pos.shift.manage',
   'delivery.assign','rider.manage','report.view','dashboard.view')
 WHERE r.code='vendor_shop_manager';

-- vendor_pos_cashier
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('pos.bootstrap','pos.sell','pos.discount.apply','pos.return.process','pos.shift.manage','pos.sync',
   'inventory.view','order.view.own','invoice.view')
 WHERE r.code='vendor_pos_cashier';

-- vendor_packer
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('order.view.own','order.update.status','inventory.view')
 WHERE r.code='vendor_packer';

-- vendor_helper
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('inventory.view','order.view.own')
 WHERE r.code='vendor_helper';

-- vendor_delivery (delivery boy)
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('delivery.view','delivery.update','rider.self.update','notification.view.own')
 WHERE r.code='vendor_delivery';

-- vendor_finance_viewer
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('settlement.view','statement.view.own','gst.report.view','report.view','invoice.view')
 WHERE r.code='vendor_finance_viewer';

-- customer
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('checkout.place','wallet.view.own','loyalty.redeem','review.create','notification.view.own')
 WHERE r.code='customer';

-- rider (platform delivery pool, self-scope)
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('delivery.view','delivery.update','rider.self.update','notification.view.own')
 WHERE r.code='rider';

-- ---------------------------------------------------------------------
-- B1. Units + conversions
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `units` (`id`,`code`,`name`,`base_unit_id`,`factor`,`allow_fraction`,`status`) VALUES
 (1,'pcs','Piece',NULL,1.000000,0,'active'),
 (2,'kg','Kilogram',NULL,1.000000,1,'active'),
 (3,'g','Gram',2,0.001000,1,'active'),
 (4,'ltr','Litre',NULL,1.000000,1,'active'),
 (5,'ml','Millilitre',4,0.001000,1,'active'),
 (6,'dozen','Dozen',1,12.000000,0,'active'),
 (7,'box','Box',1,1.000000,0,'active');
INSERT IGNORE INTO `unit_conversions` (`from_unit_id`,`to_unit_id`,`factor`) VALUES
 (2,3,1000.000000),(4,5,1000.000000),(6,1,12.000000);

-- ---------------------------------------------------------------------
-- B2. Tax classes + effective-dated GST rates (GST in force 2017-07-01)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `tax_classes` (`id`,`code`,`name`,`is_exempt`,`status`) VALUES
 (1,'GST_0','GST 0%',0,'active'),
 (2,'GST_5','GST 5%',0,'active'),
 (3,'GST_12','GST 12%',0,'active'),
 (4,'GST_18','GST 18%',0,'active'),
 (5,'GST_28','GST 28%',0,'active'),
 (6,'EXEMPT','Exempt',1,'active'),
 (7,'NIL','Nil Rated',1,'active');
INSERT IGNORE INTO `tax_rates` (`tax_class_id`,`cgst`,`sgst`,`igst`,`cess`,`valid_from`,`valid_to`,`status`) VALUES
 (1,0.00,0.00,0.00,0.00,'2017-07-01',NULL,'active'),
 (2,2.50,2.50,5.00,0.00,'2017-07-01',NULL,'active'),
 (3,6.00,6.00,12.00,0.00,'2017-07-01',NULL,'active'),
 (4,9.00,9.00,18.00,0.00,'2017-07-01',NULL,'active'),
 (5,14.00,14.00,28.00,0.00,'2017-07-01',NULL,'active'),
 (6,0.00,0.00,0.00,0.00,'2017-07-01',NULL,'active'),
 (7,0.00,0.00,0.00,0.00,'2017-07-01',NULL,'active');

-- ---------------------------------------------------------------------
-- B3. Sample HSN/SAC + shipping SAC
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `hsn_sac_codes` (`code`,`type`,`description`,`default_tax_class_id`,`status`) VALUES
 ('2106','HSN','Food preparations n.e.c.',4,'active'),
 ('0401','HSN','Milk and cream',1,'active'),
 ('1905','HSN','Bread, pastry, biscuits',2,'active'),
 ('3304','HSN','Beauty/cosmetic preparations',4,'active'),
 ('996812','SAC','Local delivery services',4,'active');

-- ---------------------------------------------------------------------
-- B4. GST config (system) + payment methods + plans
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `gst_config` (`id`,`scope_type`,`scope_id`,`rounding_mode`,`composition`,`rcm_default`,`einvoice_enabled`,`invoice_series_format`,`fy_start_month`,`status`)
 VALUES (1,'system',NULL,'line',0,0,0,'INV/{FY}/{SEQ}',4,'active');

INSERT IGNORE INTO `payment_methods` (`code`,`name`,`channel`,`is_prepaid`,`status`) VALUES
 ('cash','Cash','pos',1,'active'),
 ('upi','UPI','all',1,'active'),
 ('card','Card','all',1,'active'),
 ('netbanking','Net Banking','online',1,'active'),
 ('cod','Cash on Delivery','online',0,'active'),
 ('wallet','Wallet','all',1,'active');

INSERT IGNORE INTO `commission_plans` (`id`,`code`,`name`,`type`,`default_rate`,`base`,`valid_from`,`status`) VALUES
 (1,'STD10','Standard 10%','flat',10.00,'pre_tax','2024-01-01','active');
INSERT IGNORE INTO `vendor_plans` (`id`,`code`,`name`,`max_shops`,`max_skus`,`max_staff`,`price`,`billing_cycle`,`status`) VALUES
 (1,'FREE','Free Plan',1,100,10,0.0000,'free','active'),
 (2,'PRO','Pro Plan',10,5000,100,999.0000,'monthly','active');

-- ---------------------------------------------------------------------
-- C. Platform config + minimal chart of accounts
-- ---------------------------------------------------------------------
-- Explicit ids: scope_id is NULL for system settings (NULL-distinct in unique key).
INSERT IGNORE INTO `settings` (`id`,`scope_type`,`scope_id`,`namespace`,`key`,`value`,`value_type`,`status`) VALUES
 (1,'system',NULL,'general','currency','"INR"','json','active'),
 (2,'system',NULL,'general','timezone','"Asia/Kolkata"','json','active'),
 (3,'system',NULL,'delivery','default_radius_km','5','int','active'),
 (4,'system',NULL,'sync','pull_interval_min','10','int','active');
INSERT IGNORE INTO `feature_flags` (`key`,`description`,`is_enabled`,`status`) VALUES
 ('einvoice','GST e-invoicing / IRN',0,'active'),
 ('whatsapp_notifications','WhatsApp channel',0,'active');
INSERT IGNORE INTO `ledger_accounts` (`code`,`name`,`type`,`status`) VALUES
 ('CASH','Cash','asset','active'),
 ('BANK','Bank','asset','active'),
 ('CUST_WALLET','Customer Wallet','liability','active'),
 ('VENDOR_PAYABLE','Vendor Payable','liability','active'),
 ('SALES','Sales Income','income','active'),
 ('COMMISSION','Commission Income','income','active'),
 ('GST_PAYABLE','GST Payable','liability','active');
INSERT IGNORE INTO `rejection_reasons` (`code`,`label`,`applies_to`,`status`) VALUES
 ('IMG_QUALITY','Poor image quality','product','active'),
 ('WRONG_HSN','Incorrect HSN/tax','product','active'),
 ('PROHIBITED','Prohibited item','product','active'),
 ('KYC_BLUR','KYC document unclear','kyc','active');

-- ---------------------------------------------------------------------
-- D. Bootstrap super-admin  (DEV: admin@platform.local / password)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `users`
 (`id`,`uuid`,`principal_type`,`name`,`email`,`phone`,`password_hash`,`email_verified_at`,`status`)
 VALUES
 (1,'00000000-0000-0000-0000-000000000001','platform','Super Admin','admin@platform.local','9000000001',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',NOW(),'active');
-- Explicit id: super-admin assignment has scope_id NULL (NULL-distinct in unique key).
INSERT IGNORE INTO `user_roles` (`id`,`user_id`,`role_id`,`scope_type`,`scope_id`)
 SELECT 1, 1, r.id, 'platform', NULL FROM `roles` r WHERE r.code='super_admin';

-- =====================================================================
-- (DEMO CHAIN REMOVED) — no dummy vendors/shops/staff/products/customers.
-- Real vendors, shops, products, etc. are created through the admin/vendor
-- panels. Only system masters (above) + the bootstrap super-admin are seeded.
-- =====================================================================

-- ---------------------------------------------------------------------
-- === Warehouse/franchise/ops RBAC additions (idempotent; perms added by migration era) ===
INSERT IGNORE INTO `permissions` (`code`,`module`,`action`,`scope_class`,`description`) VALUES
 ('businesstype.manage','category','businesstype.manage','platform','Manage business types & category maps'),
 ('warehouse.view','warehouse','view','vendor','View warehouses'),
 ('warehouse.manage','warehouse','manage','vendor','Manage warehouses & stock'),
 ('transfer.view','warehouse','transfer.view','vendor','View stock transfers'),
 ('transfer.approve','warehouse','transfer.approve','vendor','Approve stock transfers'),
 ('franchise.manage','vendor','franchise.manage','vendor','Manage franchise/branch hierarchy'),
 ('backup.view','platform','backup.view','platform','View backup/restore logs'),
 ('monitoring.view','platform','monitoring.view','platform','View monitoring/observability');
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r CROSS JOIN `permissions` p WHERE r.code='super_admin';
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.scope_class IN ('vendor','shop')
 WHERE r.code='vendor_owner';
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN ('businesstype.manage','backup.view','monitoring.view')
 WHERE r.code='platform_admin';
INSERT IGNORE INTO `roles` (`id`,`code`,`name`,`scope_class`,`vendor_id`,`is_system`,`status`) VALUES
 (21,'warehouse_operator','Warehouse Operator','vendor',NULL,1,'active');
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
 SELECT r.id, p.id FROM `roles` r JOIN `permissions` p ON p.code IN
  ('warehouse.view','warehouse.manage','transfer.view','transfer.approve','inventory.view')
 WHERE r.code='warehouse_operator';

SELECT CONCAT('Seed complete. permissions=',(SELECT COUNT(*) FROM permissions),
              ' roles=',(SELECT COUNT(*) FROM roles),
              ' role_perms=',(SELECT COUNT(*) FROM role_permissions),
              ' users=',(SELECT COUNT(*) FROM users),
              ' vendors=',(SELECT COUNT(*) FROM vendors),
              ' staff=',(SELECT COUNT(*) FROM vendor_staff),
              ' delivery_boys=',(SELECT COUNT(*) FROM delivery_boys)) AS result;
-- End of 11_seed.sql
