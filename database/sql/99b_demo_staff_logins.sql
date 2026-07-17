-- =====================================================================
-- 99b_demo_staff_logins.sql  |  DEMO ONLY — staff panel logins (S1-S4)
-- Gives the demo vendor_staff a password + shop assignment + a shop-scoped role
-- so you can sign in as a branch manager / cashier and see the shop-scoped panel.
-- NOT part of run_all.sql (demo data is kept separate from the prod seed).
--
-- Logins (all password: demo1234):
--   sunil@solemate.in   Branch Manager @ Sole Mate A   (vendor 1)
--   asha@solemate.in    POS Cashier    @ Sole Mate A   (vendor 1)
--   geeta@dailyfresh.in Packer         @ Daily Fresh Powai (vendor 2)
--
-- Idempotent (UPDATE + INSERT IGNORE on the unique keys).
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

-- password = demo1234
UPDATE `users`
SET `password_hash` = '$2y$12$HfSqa32VJvoLT8btOgj5zuzuF8KQlSdHSmZG/0i2u67X0hGMtLwIS',
    `principal_type` = 'vendor', `status` = 'active'
WHERE `id` IN (110, 111, 113);

-- shop assignments (vendor_staff_id -> shop_id), one primary each
INSERT IGNORE INTO `staff_shop_assignments` (`vendor_staff_id`, `shop_id`, `is_primary`, `assigned_at`, `status`, `created_by`) VALUES
  (1, 1, 1, NOW(), 'active', 1),   -- Sunil  (branch manager) @ Sole Mate A
  (2, 1, 1, NOW(), 'active', 1),   -- Asha   (cashier)        @ Sole Mate A
  (4, 3, 1, NOW(), 'active', 1);   -- Geeta  (packer)         @ Daily Fresh Powai

-- shop-scoped roles (user_id -> role, scoped to the shop)
INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`, `scope_type`, `scope_id`, `created_by`) VALUES
  (110, 13, 'shop', 1, 1),   -- vendor_shop_manager @ shop 1
  (111, 14, 'shop', 1, 1),   -- vendor_pos_cashier  @ shop 1
  (113, 15, 'shop', 3, 1);   -- vendor_packer       @ shop 3

SET FOREIGN_KEY_CHECKS = 1;
