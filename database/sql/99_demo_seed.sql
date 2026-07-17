-- =====================================================================
-- 99_demo_seed.sql  —  DEMO / SAMPLE DATA (NOT production)
-- ---------------------------------------------------------------------
-- Populates the transactional tables so the admin panels show realistic
-- content. Safe to re-run (clears its own id ranges first) and safe to
-- drop later:  mysql -uroot test < database/sql/99_demo_seed.sql
-- To remove:   run the DELETE block at the top on its own.
-- Demin id ranges: users 100-300, everything else 1-100.
-- References existing masters: business_types 1-5, tax_classes 1-7,
-- units 1-7, hsn_sac_codes 1-5.
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM settlement_lines       WHERE settlement_id BETWEEN 1 AND 100;
DELETE FROM settlements            WHERE id BETWEEN 1 AND 100;
DELETE FROM refunds                WHERE id BETWEEN 1 AND 100;
DELETE FROM payments               WHERE id BETWEEN 1 AND 100;
DELETE FROM order_items            WHERE order_id BETWEEN 1 AND 100;
DELETE FROM sub_orders             WHERE order_id BETWEEN 1 AND 100;
DELETE FROM order_addresses        WHERE order_id BETWEEN 1 AND 100;
DELETE FROM orders                 WHERE id BETWEEN 1 AND 100;
DELETE FROM product_approval_history WHERE product_id BETWEEN 1 AND 100;
DELETE FROM product_approvals      WHERE product_id BETWEEN 1 AND 100;
DELETE FROM product_variants       WHERE product_id BETWEEN 1 AND 100;
DELETE FROM products               WHERE id BETWEEN 1 AND 100;
DELETE FROM business_type_category_map WHERE category_id BETWEEN 1 AND 100;
DELETE FROM categories             WHERE id BETWEEN 1 AND 100;
DELETE FROM attributes             WHERE id BETWEEN 1 AND 100;
DELETE FROM coupons                WHERE id BETWEEN 1 AND 100;
DELETE FROM promotions             WHERE id BETWEEN 1 AND 100;
DELETE FROM reviews                WHERE id BETWEEN 1 AND 100;
DELETE FROM brands                 WHERE id BETWEEN 1 AND 100;
DELETE FROM deliveries             WHERE id BETWEEN 1 AND 100;
DELETE FROM stock_transfers        WHERE id BETWEEN 1 AND 100;
DELETE FROM warehouses             WHERE id BETWEEN 1 AND 100;
DELETE FROM notifications          WHERE id BETWEEN 1 AND 100;
DELETE FROM import_jobs            WHERE id BETWEEN 1 AND 100;
DELETE FROM audit_logs             WHERE id BETWEEN 1 AND 100;
DELETE FROM inventory              WHERE id BETWEEN 1 AND 100;
DELETE FROM ticket_messages        WHERE ticket_id BETWEEN 1 AND 100;
DELETE FROM support_tickets        WHERE id BETWEEN 1 AND 100;
DELETE FROM backup_logs            WHERE id BETWEEN 1 AND 100;
DELETE FROM pos_sale_payments      WHERE pos_sale_id BETWEEN 1 AND 1000;
DELETE FROM pos_sale_items         WHERE pos_sale_id BETWEEN 1 AND 1000;
DELETE FROM pos_sales              WHERE id BETWEEN 1 AND 1000;
DELETE FROM pos_sequence           WHERE terminal_id BETWEEN 1 AND 100;
DELETE FROM pos_shifts             WHERE id BETWEEN 1 AND 1000;
DELETE FROM sync_cursors           WHERE terminal_id BETWEEN 1 AND 100;
DELETE FROM pos_terminals          WHERE id BETWEEN 1 AND 100;
DELETE FROM gateway_accounts       WHERE id BETWEEN 1 AND 100;
DELETE FROM integration_accounts   WHERE id BETWEEN 1 AND 100;
DELETE FROM customers              WHERE id BETWEEN 1 AND 100;
DELETE FROM shops                  WHERE id BETWEEN 1 AND 100;
DELETE FROM vendors                WHERE id BETWEEN 1 AND 100;
DELETE FROM user_roles             WHERE user_id BETWEEN 100 AND 300;
DELETE FROM vendor_staff           WHERE id BETWEEN 1 AND 100;
DELETE FROM delivery_boys          WHERE id BETWEEN 1 AND 100;
DELETE FROM users                  WHERE id BETWEEN 100 AND 300;

-- ---- Users (vendor owners + customers) ----
-- Vendor owners share the demo password "password" (bcrypt) so the vendor panel
-- is loginable, e.g. rahul@solemate.in / password.
INSERT INTO users (id, uuid, principal_type, name, email, phone, status, password_hash) VALUES
 (101, UUID(), 'vendor',   'Rahul Mehta',  'rahul@solemate.in',     '+919800000101', 'active', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
 (102, UUID(), 'vendor',   'Priya Shah',   'priya@dailyfresh.in',   '+919800000102', 'active', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
 (103, UUID(), 'vendor',   'Arjun Verma',  'arjun@gadgetgalaxy.in', '+919800000103', 'active', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
 (201, UUID(), 'customer', 'Aarav Sharma', 'aarav@example.com',     '+919811100201', 'active', NULL),
 (202, UUID(), 'customer', 'Diya Patel',   'diya@example.com',      '+919811100202', 'active', NULL),
 (203, UUID(), 'customer', 'Vivaan Mehta', 'vivaan@example.com',    '+919811100203', 'active', NULL),
 (204, UUID(), 'customer', 'Anaya Rao',    'anaya@example.com',     '+919811100204', 'active', NULL),
 (205, UUID(), 'customer', 'Kabir Singh',  'kabir@example.com',     '+919811100205', 'active', NULL);

-- ---- Vendors ----
INSERT INTO vendors (id, uuid, owner_user_id, business_type_id, legal_name, display_name, slug, gstin, gstin_status, status) VALUES
 (1, UUID(), 101, 1, 'Sole Mate Footwear Pvt Ltd', 'Sole Mate Footwear', 'sole-mate-footwear', '27AAACS1234F1Z5', 'verified', 'active'),
 (2, UUID(), 102, 2, 'Daily Fresh Retail LLP',     'Daily Fresh Grocery','daily-fresh-grocery','27AAACD5678G1Z2', 'verified', 'active'),
 (3, UUID(), 103, 3, 'Gadget Galaxy LLP',          'Gadget Galaxy',      'gadget-galaxy',      '29AAACG9012H1Z8', 'pending',  'submitted');

-- ---- Shops ----
INSERT INTO shops (id, uuid, vendor_id, name, code, address_json, pincode, state_code, latitude, longitude, delivery_radius_km, prep_time_min, pickup_enabled, gstin, gstin_status, status) VALUES
 (1, UUID(), 1, 'Sole Mate — Andheri',       'SM-AND', '{"line1":"Shop 12, Link Road","area":"Andheri West","city":"Mumbai","state":"MH"}', '400058', '27', 19.1360000, 72.8260000, 10.0, 30, 1, '27AAACS1234F1Z5', 'verified', 'active'),
 (2, UUID(), 1, 'Sole Mate — Bandra',        'SM-BAN', '{"line1":"Shop 4, Hill Road","area":"Bandra West","city":"Mumbai","state":"MH"}',  '400050', '27', 19.0540000, 72.8400000,  8.0, 30, 1, '27AAACS1234F1Z5', 'verified', 'inactive'),
 (3, UUID(), 2, 'Daily Fresh — Powai',       'DF-POW', '{"line1":"G-3, Hiranandani","area":"Powai","city":"Mumbai","state":"MH"}',          '400076', '27', 19.1170000, 72.9050000,  6.0, 20, 1, '27AAACD5678G1Z2', 'verified', 'active'),
 (4, UUID(), 2, 'Daily Fresh — Thane',       'DF-THN', '{"line1":"Unit 8, Ghodbunder Rd","area":"Thane West","city":"Thane","state":"MH"}', '400601', '27', 19.2180000, 72.9780000, 12.0, 25, 1, '27AAACD5678G1Z2', 'pending',  'active'),
 (5, UUID(), 3, 'Gadget Galaxy — Koramangala','GG-KOR','{"line1":"80 Ft Road","area":"Koramangala","city":"Bengaluru","state":"KA"}',       '560034', '29', 12.9350000, 77.6240000,  9.0, 15, 0, '29AAACG9012H1Z8', 'pending',  'closed_temp');

-- ---- Categories (business-type scoped) ----
INSERT INTO categories (id, uuid, parent_id, name, slug, path, level, default_tax_class_id, default_commission_rate, status) VALUES
 (1, UUID(), NULL, 'Footwear',      'footwear',      'footwear',                 0, 4, 12.00, 'active'),
 (2, UUID(), 1,    "Men's Shoes",   'mens-shoes',    'footwear/mens-shoes',      1, 4, 12.00, 'active'),
 (3, UUID(), 1,    "Women's Shoes", 'womens-shoes',  'footwear/womens-shoes',    1, 4, 12.00, 'active'),
 (4, UUID(), NULL, 'Grocery',       'grocery',       'grocery',                  0, 2,  5.00, 'active'),
 (5, UUID(), 4,    'Staples',       'staples',       'grocery/staples',          1, 2,  4.00, 'active'),
 (6, UUID(), 4,    'Beverages',     'beverages',     'grocery/beverages',        1, 3,  6.00, 'active'),
 (7, UUID(), NULL, 'Electronics',   'electronics',   'electronics',              0, 5, 10.00, 'active'),
 (8, UUID(), 7,    'Mobiles',       'mobiles',       'electronics/mobiles',      1, 5,  8.00, 'active');

INSERT INTO business_type_category_map (business_type_id, category_id) VALUES
 (1,1),(1,2),(1,3),(2,4),(2,5),(2,6),(3,7),(3,8);

-- ---- Attributes (variant-defining + descriptive) ----
INSERT INTO attributes (id, code, name, type, is_variant_defining, status) VALUES
 (1, 'size',     'Size',     'select',      1, 'active'),
 (2, 'color',    'Color',    'select',      1, 'active'),
 (3, 'material', 'Material', 'text',        0, 'active'),
 (4, 'storage',  'Storage',  'select',      1, 'active'),
 (5, 'warranty', 'Warranty', 'text',        0, 'inactive');

-- ---- Customers ----
INSERT INTO customers (id, uuid, user_id, lifetime_value, status) VALUES
 (1, UUID(), 201, 12450.00, 'active'),
 (2, UUID(), 202,  3200.00, 'active'),
 (3, UUID(), 203,  8900.00, 'active'),
 (4, UUID(), 204,   540.00, 'blocked'),
 (5, UUID(), 205, 15600.00, 'active');

-- ---- Products (mix of statuses; some awaiting review) ----
INSERT INTO products (id, uuid, vendor_id, category_id, tax_class_id, base_unit_id, hsn_sac_id, title, slug, description, status) VALUES
 (1, UUID(), 1, 2, 4, 1, 1, 'Classic Leather Derby',     'classic-leather-derby',     'Hand-finished leather derby shoes.',      'submitted'),
 (2, UUID(), 1, 3, 4, 1, 1, 'Women Block Heels',         'women-block-heels',         'Comfortable 2-inch block heels.',         'under_review'),
 (3, UUID(), 1, 2, 4, 1, 1, 'Running Sneakers',          'running-sneakers',          'Lightweight everyday running shoes.',     'published'),
 (4, UUID(), 2, 5, 2, 1, 2, 'Basmati Rice 5kg',          'basmati-rice-5kg',          'Aged premium basmati rice.',              'submitted'),
 (5, UUID(), 2, 6, 3, 4, 3, 'Cold Brew Coffee 1L',       'cold-brew-coffee-1l',       'Slow-steeped cold brew concentrate.',     'published'),
 (6, UUID(), 3, 8, 5, 1, 5, 'Smartphone X10',            'smartphone-x10',            '6.5-inch AMOLED, 5000mAh.',               'submitted'),
 (7, UUID(), 3, 8, 5, 1, 5, 'Wireless Earbuds Pro',      'wireless-earbuds-pro',      'ANC earbuds, 32h battery.',               'published'),
 (8, UUID(), 2, 5, 2, 3, 2, 'Organic Almonds 500g',      'organic-almonds-500g',      'California organic almonds.',             'submitted');

INSERT INTO product_variants (id, uuid, product_id, vendor_id, sku, unit_id, mrp, base_price, is_default) VALUES
 (1, UUID(), 1, 1, 'SM-DERBY-BLK-9',   1, 4999.00, 3499.00, 1),
 (2, UUID(), 2, 1, 'SM-HEEL-BRN-7',    1, 3499.00, 2299.00, 1),
 (3, UUID(), 3, 1, 'SM-RUN-GRY-8',     1, 3999.00, 2499.00, 1),
 (4, UUID(), 4, 2, 'DF-RICE-5KG',      1,  899.00,  720.00, 1),
 (5, UUID(), 5, 2, 'DF-COLDBREW-1L',   4,  349.00,  299.00, 1),
 (6, UUID(), 6, 3, 'GG-X10-128',       1,16999.00,14999.00, 1),
 (7, UUID(), 7, 3, 'GG-EARBUDS-PRO',   1, 3499.00, 1999.00, 1),
 (8, UUID(), 8, 2, 'DF-ALMOND-500',    3,  799.00,  649.00, 1);

-- Approval rows for products awaiting review, then link back
INSERT INTO product_approvals (id, uuid, product_id, submitted_by, status, notes) VALUES
 (1, UUID(), 1, 101, 'submitted',    NULL),
 (2, UUID(), 2, 101, 'under_review', 'Checking material claims.'),
 (3, UUID(), 4, 102, 'submitted',    NULL),
 (4, UUID(), 6, 103, 'submitted',    NULL),
 (5, UUID(), 8, 102, 'submitted',    NULL);
UPDATE products SET approval_id = 1 WHERE id = 1;
UPDATE products SET approval_id = 2 WHERE id = 2;
UPDATE products SET approval_id = 3 WHERE id = 4;
UPDATE products SET approval_id = 4 WHERE id = 6;
UPDATE products SET approval_id = 5 WHERE id = 8;

-- ---- Orders + addresses ----
INSERT INTO orders (id, uuid, order_no, customer_id, channel, currency, subtotal, discount_total, tax_total, delivery_total, grand_total, billing_address_json, payment_status, placed_at, status) VALUES
 (1, UUID(), 'ORD-2026-1001', 1, 'online', 'INR', 3499.00, 0.00, 419.88,  0.00, 3918.88, '{"name":"Aarav Sharma","line1":"12 Marine Drive","city":"Mumbai","state_code":"27","pincode":"400020"}', 'paid',     '2026-06-05 10:02:00', 'completed'),
 (2, UUID(), 'ORD-2026-1002', 2, 'app',    'INR',  720.00, 0.00,  36.00, 40.00,  796.00, '{"name":"Diya Patel","line1":"8 Powai Lake Rd","city":"Mumbai","state_code":"27","pincode":"400076"}',  'pending',  '2026-06-07 09:15:00', 'created'),
 (3, UUID(), 'ORD-2026-1003', 3, 'online', 'INR',14999.00, 500.00,1449.82, 0.00,15948.82, '{"name":"Vivaan Mehta","line1":"4 MG Road","city":"Bengaluru","state_code":"29","pincode":"560001"}',   'paid',     '2026-06-07 14:40:00', 'confirmed'),
 (4, UUID(), 'ORD-2026-1004', 1, 'online', 'INR', 2499.00, 0.00, 299.88,  0.00, 2798.88, '{"name":"Aarav Sharma","line1":"12 Marine Drive","city":"Mumbai","state_code":"27","pincode":"400020"}','refunded', '2026-06-06 18:20:00', 'cancelled'),
 (5, UUID(), 'ORD-2026-1005', 4, 'pos',    'INR',  948.00, 0.00,  47.40,  0.00,  995.40, '{"name":"Anaya Rao","line1":"Counter Sale","city":"Mumbai","state_code":"27","pincode":"400076"}',     'paid',     '2026-06-08 12:05:00', 'completed'),
 (6, UUID(), 'ORD-2026-1006', 5, 'app',    'INR', 5998.00, 200.00, 695.76,40.00, 6533.76, '{"name":"Kabir Singh","line1":"22 Hill Road","city":"Mumbai","state_code":"27","pincode":"400050"}',    'paid',     '2026-06-08 16:30:00', 'partially_fulfilled');

INSERT INTO order_addresses (order_id, name, line1, city, state_code, pincode) VALUES
 (1, 'Aarav Sharma', '12 Marine Drive',  'Mumbai',    '27', '400020'),
 (2, 'Diya Patel',   '8 Powai Lake Rd',  'Mumbai',    '27', '400076'),
 (3, 'Vivaan Mehta', '4 MG Road',        'Bengaluru', '29', '560001'),
 (4, 'Aarav Sharma', '12 Marine Drive',  'Mumbai',    '27', '400020'),
 (5, 'Anaya Rao',    'Counter Sale',     'Mumbai',    '27', '400076'),
 (6, 'Kabir Singh',  '22 Hill Road',     'Mumbai',    '27', '400050');

-- ---- Sub-orders (per vendor/shop) ----
INSERT INTO sub_orders (id, uuid, order_id, vendor_id, shop_id, sub_order_no, subtotal, taxable_value, cgst, sgst, igst, delivery_total, grand_total, commission_amount, place_of_supply, status) VALUES
 (1, UUID(), 1, 1, 1, 'SO-1001-1', 3499.00, 3499.00, 209.94, 209.94,   0.00,  0.00, 3918.88, 419.88, '27', 'completed'),
 (2, UUID(), 2, 2, 3, 'SO-1002-1',  720.00,  720.00,  18.00,  18.00,   0.00, 40.00,  796.00,  28.80, '27', 'pending'),
 (3, UUID(), 3, 3, 5, 'SO-1003-1',14499.00,14499.00,   0.00,   0.00,1449.82,  0.00,15948.82,1159.92, '27', 'confirmed'),
 (4, UUID(), 4, 1, 2, 'SO-1004-1', 2499.00, 2499.00, 149.94, 149.94,   0.00,  0.00, 2798.88, 299.88, '27', 'cancelled'),
 (5, UUID(), 5, 2, 3, 'SO-1005-1',  948.00,  948.00,  23.70,  23.70,   0.00,  0.00,  995.40,  37.92, '27', 'delivered'),
 (6, UUID(), 6, 1, 1, 'SO-1006-1', 5798.00, 5798.00, 347.88, 347.88,   0.00, 40.00, 6533.76, 695.76, '27', 'out_for_delivery');

INSERT INTO order_items (id, uuid, sub_order_id, order_id, variant_id, product_title_snapshot, sku_snapshot, hsn_snapshot, qty, unit_price, mrp, taxable_value, tax_rate, cgst, sgst, igst) VALUES
 (1, UUID(), 1, 1, 3, 'Running Sneakers',     'SM-RUN-GRY-8',   '6403', 1.000, 3499.00, 3999.00, 3499.00, 12.00, 209.94, 209.94,    0.00),
 (2, UUID(), 2, 2, 4, 'Basmati Rice 5kg',     'DF-RICE-5KG',    '1006', 1.000,  720.00,  899.00,  720.00,  5.00,  18.00,  18.00,    0.00),
 (3, UUID(), 3, 3, 6, 'Smartphone X10',       'GG-X10-128',     '8517', 1.000,14499.00,16999.00,14499.00, 10.00,   0.00,   0.00, 1449.82),
 (4, UUID(), 4, 4, 1, 'Classic Leather Derby','SM-DERBY-BLK-9', '6403', 1.000, 2499.00, 4999.00, 2499.00, 12.00, 149.94, 149.94,    0.00),
 (5, UUID(), 5, 5, 5, 'Cold Brew Coffee 1L',  'DF-COLDBREW-1L', '2202', 2.000,  299.00,  349.00,  598.00,  5.00,  14.95,  14.95,    0.00),
 (6, UUID(), 6, 6, 3, 'Running Sneakers',     'SM-RUN-GRY-8',   '6403', 2.000, 2499.00, 3999.00, 4998.00, 12.00, 299.88, 299.88,    0.00);

-- ---- Payments ----
INSERT INTO payments (id, uuid, order_id, method, gateway, gateway_ref, amount, currency, idempotency_key, captured_at, status) VALUES
 (1, UUID(), 1, 'upi',        'razorpay', 'pay_A1001', 3918.88, 'INR', 'idem-1001', '2026-06-05 10:03:00', 'captured'),
 (2, UUID(), 3, 'card',       'razorpay', 'pay_A1003',15948.82, 'INR', 'idem-1003', '2026-06-07 14:41:00', 'captured'),
 (3, UUID(), 4, 'upi',        'razorpay', 'pay_A1004', 2798.88, 'INR', 'idem-1004', '2026-06-06 18:21:00', 'refunded'),
 (4, UUID(), 5, 'cash',       NULL,        NULL,        995.40, 'INR', 'idem-1005', '2026-06-08 12:05:00', 'captured'),
 (5, UUID(), 6, 'netbanking', 'razorpay', 'pay_A1006', 6533.76, 'INR', 'idem-1006', '2026-06-08 16:31:00', 'captured'),
 (6, UUID(), 2, 'upi',        'razorpay', 'pay_A1002',  796.00, 'INR', 'idem-1002', NULL,                  'created');

-- ---- Refunds ----
INSERT INTO refunds (id, uuid, payment_id, amount, destination, gateway_ref, idempotency_key, status) VALUES
 (1, UUID(), 3, 2798.88, 'source', 'rfnd_R1', 'ridem-1', 'completed'),
 (2, UUID(), 1,  500.00, 'wallet', NULL,       'ridem-2', 'initiated');

-- ---- Settlements + lines ----
INSERT INTO settlements (id, uuid, vendor_id, period_start, period_end, gross, commission_total, refund_total, tcs, tds, fees, adjustments, net_payable, idempotency_key, status) VALUES
 (1, UUID(), 1, '2026-05-01', '2026-05-31', 142500.00, 17100.00,  2798.88, 1425.00, 712.50, 250.00, 0.00, 120213.62, 'sidem-1', 'approved'),
 (2, UUID(), 2, '2026-05-01', '2026-05-31',  58400.00,  2920.00,     0.00,  584.00, 292.00, 100.00, 0.00,  54504.00, 'sidem-2', 'calculated'),
 (3, UUID(), 3, '2026-05-01', '2026-05-31', 215000.00, 21500.00,     0.00, 2150.00,1075.00, 400.00, 0.00, 189875.00, 'sidem-3', 'paid');

INSERT INTO settlement_lines (settlement_id, ref_type, ref_id, sub_order_id, amount, direction) VALUES
 (1, 'sale',       1, 1, 142500.00, 'credit'),
 (1, 'commission', 1, 1,  17100.00, 'debit'),
 (1, 'refund',     1, 4,   2798.88, 'debit'),
 (1, 'tcs',        NULL, NULL, 1425.00, 'debit'),
 (2, 'sale',       5, 5,  58400.00, 'credit'),
 (2, 'commission', 5, 5,   2920.00, 'debit'),
 (3, 'sale',       3, 3, 215000.00, 'credit'),
 (3, 'commission', 3, 3,  21500.00, 'debit');

-- ---- Brands ----
INSERT INTO brands (id, uuid, name, slug, status) VALUES
 (1, UUID(), 'Sole Mate',  'sole-mate',  'active'),
 (2, UUID(), 'Daily Fresh','daily-fresh','active'),
 (3, UUID(), 'GadgetPro',  'gadgetpro',  'pending'),
 (4, UUID(), 'Generic',    'generic',    'active');

-- ---- Promotions + coupons ----
INSERT INTO promotions (id, uuid, vendor_id, name, type, value, funded_by, valid_from, valid_to, status) VALUES
 (1, UUID(), 1,    'Festive 20% Off',  'percentage',    20.00, 'vendor',   '2026-06-01', '2026-06-30', 'active'),
 (2, UUID(), NULL, 'Free Shipping Wk', 'free_shipping',  0.00, 'platform', '2026-06-05', '2026-06-12', 'scheduled'),
 (3, UUID(), 2,    'Flat ₹100 Off',    'flat',         100.00, 'vendor',   '2026-05-01', '2026-05-31', 'paused');

INSERT INTO coupons (id, promotion_id, code, usage_limit, per_user_limit, used_count, valid_from, valid_to, status) VALUES
 (1, 1, 'FEST20',  1000, 1,  84, '2026-06-01', '2026-06-30', 'active'),
 (2, 3, 'FLAT100',  500, 2, 500, '2026-05-01', '2026-05-31', 'exhausted');

-- ---- Reviews ----
INSERT INTO reviews (id, uuid, product_id, customer_id, sub_order_id, rating, title, body, is_verified_purchase, status) VALUES
 (1, UUID(), 3, 1, 1, 5, 'Super comfortable', 'Wore them all day, zero blisters.', 1, 'published'),
 (2, UUID(), 5, 3, NULL, 4, 'Good brew',        'Strong and smooth, slightly pricey.', 1, 'pending'),
 (3, UUID(), 7, 5, 6, 5, 'Great ANC',          'Blocks out the metro noise nicely.', 1, 'pending'),
 (4, UUID(), 3, 2, NULL, 2, 'Runs small',       'Order a size up. Buy elsewhere!!!', 0, 'flagged');

-- ---- Deliveries ----
INSERT INTO deliveries (id, uuid, sub_order_id, shop_id, mode, delivery_fee, eta_at, status) VALUES
 (1, UUID(), 1, 1, 'self',  0.00,  '2026-06-05 13:00:00', 'delivered'),
 (2, UUID(), 5, 3, 'pool',  0.00,  '2026-06-08 14:00:00', 'delivered'),
 (3, UUID(), 6, 1, 'self', 40.00,  '2026-06-08 18:30:00', 'out_for_delivery');

-- ---- Warehouses ----
INSERT INTO warehouses (id, uuid, vendor_id, name, code, address_json, pincode, state_code, latitude, longitude, status) VALUES
 (1, UUID(), 1, 'Sole Mate Central WH', 'WH-SM-1', '{"line1":"Plot 7, MIDC","city":"Mumbai","state":"MH"}', '400093', '27', 19.1100000, 72.8690000, 'active'),
 (2, UUID(), 2, 'Daily Fresh DC',       'WH-DF-1', '{"line1":"Cold Storage Park","city":"Thane","state":"MH"}', '400604', '27', 19.2000000, 72.9700000, 'inactive');

-- ---- Stock transfers ----
INSERT INTO stock_transfers (id, uuid, from_shop_id, to_shop_id, variant_id, qty, status) VALUES
 (1, UUID(), 1, 2, 3, 20.000, 'requested'),
 (2, UUID(), 3, 4, 4, 50.000, 'approved');

-- ---- Notifications ----
INSERT INTO notifications (id, uuid, user_id, event_code, title, body, category, status, created_at) VALUES
 (1, UUID(), 201, 'order.placed',   'Order confirmed',  'Your order ORD-2026-1001 is confirmed.', 'transactional', 'sent',   '2026-06-05 10:03:00'),
 (2, UUID(), 202, 'order.shipped',  'Order shipped',    'Your order is on the way.',              'transactional', 'queued', '2026-06-07 09:20:00'),
 (3, UUID(), 203, 'promo.launch',   'Festive Sale!',    'Up to 20% off this week.',               'promotional',   'sent',   '2026-06-06 08:00:00'),
 (4, UUID(), 204, 'payment.failed', 'Payment failed',   'We could not process your payment.',     'transactional', 'failed', '2026-06-06 18:25:00'),
 (5, UUID(), 101, 'settlement.ready','Settlement approved','Your May settlement of ₹1,20,213 is approved.','transactional','sent','2026-06-07 09:00:00'),
 (6, UUID(), 101, 'order.new',      'New order received','Order ORD-2026-1006 needs fulfilment.',  'transactional', 'sent',   '2026-06-08 16:31:00');

-- ---- Import jobs ----
INSERT INTO import_jobs (id, uuid, type, total_rows, processed_rows, error_rows, requested_by, status) VALUES
 (1, UUID(), 'product', 120, 120,  0, 102, 'completed'),
 (2, UUID(), 'price',    80,  40,  5, 103, 'processing');

-- ---- Audit logs ----
INSERT INTO audit_logs (id, uuid, actor_user_id, actor_principal_type, action, entity_type, entity_id, result, ip, channel, row_hash, created_at) VALUES
 (1, UUID(), 1, 'platform', 'vendor.approve',   'vendor',  1, 'success', '127.0.0.1', 'web', SHA2('a1',256), '2026-06-01 09:00:00'),
 (2, UUID(), 1, 'platform', 'product.approve',  'product', 3, 'success', '127.0.0.1', 'web', SHA2('a2',256), '2026-06-02 11:00:00'),
 (3, UUID(), 1, 'platform', 'settlement.approve','settlement', 1, 'success', '127.0.0.1', 'web', SHA2('a3',256), '2026-06-03 15:00:00'),
 (4, UUID(), 1, 'platform', 'customer.block',   'customer', 4, 'success', '127.0.0.1', 'web', SHA2('a4',256), '2026-06-06 12:00:00');

-- ---- Support tickets + messages ----
INSERT INTO support_tickets (id, uuid, ticket_no, requester_user_id, requester_type, vendor_id, subject, body, category, priority, status, last_reply_at) VALUES
 (1, UUID(), 'TKT-1001', 201, 'customer', NULL, 'Order not delivered', 'My order ORD-2026-1006 still shows out for delivery after 2 days.', 'delivery', 'high',   'open',     NULL),
 (2, UUID(), 'TKT-1002', 101, 'vendor',   1,    'Payout delay query',  'My May settlement is still marked approved but not paid.',        'payments', 'medium', 'pending',  '2026-06-07 10:00:00'),
 (3, UUID(), 'TKT-1003', 203, 'customer', NULL, 'Wrong item received', 'I ordered the X10 but got earbuds instead.',                       'orders',   'urgent', 'resolved', '2026-06-06 16:00:00');

INSERT INTO ticket_messages (ticket_id, author_user_id, body, is_internal) VALUES
 (1, 201, 'Any update on my delivery? It is urgent.', 0),
 (2, 102, 'Hi, payouts run on the 10th — your settlement is queued.', 0),
 (2, 1,   'Internal: verify bank mandate before payout.', 1),
 (3, 1,   'Apologies — a replacement has been dispatched and a refund issued.', 0);

-- ---- Backup / restore logs ----
INSERT INTO backup_logs (id, uuid, type, scope, status, size_bytes, location, started_at, finished_at, notes) VALUES
 (1, UUID(), 'full',        'database', 'completed', 268435456, 's3://ch-backups/2026-06-08-full.sql.gz',  '2026-06-08 02:00:00', '2026-06-08 02:14:00', 'Nightly full backup'),
 (2, UUID(), 'incremental', 'database', 'completed',  18874368, 's3://ch-backups/2026-06-08-1200-inc.gz',  '2026-06-08 12:00:00', '2026-06-08 12:02:00', NULL),
 (3, UUID(), 'snapshot',    'media',    'failed',            0, NULL,                                       '2026-06-08 03:00:00', '2026-06-08 03:01:00', 'Storage credentials expired');

-- ---- Vendor role assignments (scope = own vendor) ----
INSERT INTO user_roles (user_id, role_id, scope_type, scope_id) VALUES
 (101, 10, 'vendor', 1),
 (102, 10, 'vendor', 2),
 (103, 10, 'vendor', 3);

-- ---- Staff users + vendor_staff + delivery_boys (vendor 1 + 2) ----
INSERT INTO users (id, uuid, principal_type, name, email, phone, status) VALUES
 (110, UUID(), 'vendor', 'Sunil More',   'sunil@solemate.in',   '+919800000110', 'active'),
 (111, UUID(), 'vendor', 'Asha Iyer',    'asha@solemate.in',    '+919800000111', 'active'),
 (112, UUID(), 'rider',  'Ramesh Yadav', 'ramesh@solemate.in',  '+919800000112', 'active'),
 (113, UUID(), 'vendor', 'Geeta Nair',   'geeta@dailyfresh.in', '+919800000113', 'active');

INSERT INTO vendor_staff (id, uuid, user_id, vendor_id, staff_type, employee_code, designation, status) VALUES
 (1, UUID(), 110, 1, 'branch_manager', 'EMP-001', 'Store Manager', 'active'),
 (2, UUID(), 111, 1, 'cashier',        'EMP-002', 'Cashier',       'active'),
 (3, UUID(), 112, 1, 'delivery_boy',   'EMP-003', 'Delivery Rider','active'),
 (4, UUID(), 113, 2, 'packer',         'EMP-004', 'Packer',        'active');

INSERT INTO delivery_boys (id, uuid, user_id, vendor_id, vendor_staff_id, vehicle_type, vehicle_no, availability, status) VALUES
 (1, UUID(), 112, 1, 3, 'bike', 'MH01AB1234', 'available', 'active');

-- ---- Inventory (vendor 1 variants @ shop 1; vendor 2 @ shop 3) ----
-- (available is a generated column: on_hand - reserved)
INSERT INTO inventory (id, uuid, variant_id, shop_id, on_hand, reserved, reorder_level) VALUES
 (1, UUID(), 1, 1,  48.000, 3.000, 10.000),
 (2, UUID(), 2, 1,  22.000, 0.000,  8.000),
 (3, UUID(), 3, 1,   7.000, 1.000, 12.000),
 (4, UUID(), 4, 3, 120.000, 0.000, 20.000);

-- ---- Payment gateways (PayU live default + Razorpay sandbox) ----
INSERT INTO gateway_accounts (id, provider, owner_type, channel, key_ref, webhook_secret_ref, config, priority, status) VALUES
 (1, 'payu',     'platform', 'online', 'PAYU_MERCHANT_DEMO', 'stored-in-config', '{"merchant_key":"PAYU_MERCHANT_DEMO","merchant_salt":"DEMOSALT123","auth_header":"demo-auth"}', 1, 'active'),
 (2, 'razorpay', 'platform', 'online', 'rzp_test_demo',      'stored-in-config', '{"key_id":"rzp_test_demo","key_secret":"demosecret","webhook_secret":"whsec_demo"}',        2, 'sandbox');

-- ---- Integration accounts (Firebase / SMTP / GST API) ----
INSERT INTO integration_accounts (id, provider, owner_type, config, status) VALUES
 (1, 'firebase', 'platform', '{"project_id":"commercehub-demo","web_api_key":"AIzaDEMO","sender_id":"123456789","app_id":"1:123:web:demo"}', 'connected'),
 (2, 'smtp',     'platform', '{"host":"smtp.mailtrap.io","port":"587","username":"demo","password":"demo","encryption":"tls","from_email":"no-reply@shiplore.io","from_name":"Shiplore"}', 'connected'),
 (3, 'gst_api',  'platform', '{"provider_name":"MasterGST","base_url":"https://api.mastergst.com","client_id":"demo-client","client_secret":"demo-secret"}', 'connected');

-- ---- POS terminal (Sole Mate Andheri) for the Windows POS demo ----
INSERT INTO pos_terminals (id, uuid, shop_id, vendor_id, name, invoice_prefix, activation_code, status) VALUES
 (1, UUID(), 1, 1, 'Sole Mate Andheri — Counter 1', 'SMA', 'POS-DEMO-0001', 'active');

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'demo seed loaded' AS info,
 (SELECT COUNT(*) FROM vendors)  AS vendors,
 (SELECT COUNT(*) FROM shops)    AS shops,
 (SELECT COUNT(*) FROM products) AS products,
 (SELECT COUNT(*) FROM orders)   AS orders,
 (SELECT COUNT(*) FROM customers)AS customers,
 (SELECT COUNT(*) FROM payments) AS payments,
 (SELECT COUNT(*) FROM settlements) AS settlements;
