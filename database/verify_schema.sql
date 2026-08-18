-- ===================================================================
-- SHIPLORE SCHEMA VERIFICATION -- rootshiplore_test -- MariaDB 10.11
-- READ-ONLY. Q2..Q8 must all return ZERO rows.
-- ===================================================================

-- Q1 (informational): what exists, and did a past import leave a stray `test`?
SELECT s.SCHEMA_NAME AS db,
       (SELECT COUNT(*) FROM information_schema.TABLES t
         WHERE t.TABLE_SCHEMA = s.SCHEMA_NAME AND t.TABLE_TYPE = 'BASE TABLE') AS base_tables
FROM information_schema.SCHEMATA s
WHERE s.SCHEMA_NAME IN ('rootshiplore_test','test');
-- Expect rootshiplore_test ~283. A `test` row with tables in it = leftover from
-- an earlier import attempt; a `test` row with 0 tables is often just a MySQL
-- default and means nothing. Do not delete anything without checking first.


-- Q2: tables that should exist and don't.  ZERO ROWS EXPECTED.
SELECT e.tbl AS missing_table, e.src AS from_file
FROM (
            SELECT 'mshops'                   AS tbl, '70_manufacturer'          AS src
  UNION ALL SELECT 'product_mshops',                  '70_manufacturer'
  UNION ALL SELECT 'mfg_purchase_orders',             '71_monline_b2b'
  UNION ALL SELECT 'mfg_purchase_order_items',        '71_monline_b2b'
  UNION ALL SELECT 'mshop_hours',                     '77_manufacturer_delivery'
  UNION ALL SELECT 'mshop_holidays',                  '77_manufacturer_delivery'
  UNION ALL SELECT 'mfg_deliveries',                  '77_manufacturer_delivery'
  UNION ALL SELECT 'mfg_pos_terminals',               '79_manufacturer_pos'
  UNION ALL SELECT 'mfg_pos_shifts',                  '79_manufacturer_pos'
  UNION ALL SELECT 'mfg_pos_sales',                   '79_manufacturer_pos'
  UNION ALL SELECT 'mfg_pos_sale_items',              '79_manufacturer_pos'
  UNION ALL SELECT 'mfg_pos_sale_payments',           '79_manufacturer_pos'
  UNION ALL SELECT 'mfg_pos_sequence',                '79_manufacturer_pos'
  UNION ALL SELECT 'api_idempotency_keys',            'earlier'
  UNION ALL SELECT 'customer_payment_instruments',    'earlier'
) e
LEFT JOIN information_schema.TABLES i
       ON i.TABLE_SCHEMA = 'rootshiplore_test' AND i.TABLE_NAME = e.tbl
WHERE i.TABLE_NAME IS NULL;


-- Q3: the 7 delivery columns file 77 adds to `mshops`, and their defaults.
--     delivery_enabled MUST default to 0; pickup_enabled MUST default to 1.
--     ZERO ROWS EXPECTED.
SELECT e.col AS problem_column,
       CASE WHEN c.COLUMN_NAME IS NULL THEN 'MISSING' ELSE 'WRONG DEFAULT' END AS problem,
       COALESCE(c.COLUMN_DEFAULT,'NULL') AS actual_default,
       e.expected_default
FROM (
            SELECT 'delivery_enabled'   AS col, '0'    AS expected_default
  UNION ALL SELECT 'delivery_radius_km',        'NULL'
  UNION ALL SELECT 'pickup_enabled',            '1'
  UNION ALL SELECT 'prep_time_min',             'NULL'
  UNION ALL SELECT 'min_order_value',           'NULL'
  UNION ALL SELECT 'delivery_fee',              'NULL'
  UNION ALL SELECT 'free_delivery_above',       'NULL'
) e
LEFT JOIN information_schema.COLUMNS c
       ON c.TABLE_SCHEMA = 'rootshiplore_test'
      AND c.TABLE_NAME   = 'mshops'
      AND c.COLUMN_NAME  = e.col
WHERE c.COLUMN_NAME IS NULL
   OR COALESCE(c.COLUMN_DEFAULT,'NULL') <> e.expected_default;


-- Q4: the 14 permission codes added by files 76/77/78/79, and their scope_class.
--     ZERO ROWS EXPECTED.
SELECT e.code AS problem_permission,
       CASE WHEN p.code IS NULL THEN 'MISSING' ELSE 'WRONG scope_class' END AS problem,
       COALESCE(p.scope_class,'-') AS actual_scope_class, e.scope_class AS expected
FROM (
            SELECT 'mfg.notification.view'  AS code, 'manufacturer' AS scope_class
  UNION ALL SELECT 'mfg.transfer.view',            'manufacturer'
  UNION ALL SELECT 'mfg.transfer.manage',          'mshop'
  UNION ALL SELECT 'mfg.gst.view',                 'manufacturer'
  UNION ALL SELECT 'mfg.invoice.view',             'manufacturer'
  UNION ALL SELECT 'mfg.barcode.print',            'manufacturer'
  UNION ALL SELECT 'mfg.report.export',            'manufacturer'
  UNION ALL SELECT 'mfg.request.approve',          'manufacturer'
  UNION ALL SELECT 'mfg.delivery.assign',          'mshop'
  UNION ALL SELECT 'mfg.rider.manage',             'manufacturer'
  UNION ALL SELECT 'mfg.pos.sell',                 'mshop'
  UNION ALL SELECT 'mfg.unit.serviceability',      'mshop'
  UNION ALL SELECT 'mfg.staff.request',            'manufacturer'
  UNION ALL SELECT 'mfg.pos.view',                 'mshop'
) e
LEFT JOIN `rootshiplore_test`.`permissions` p ON p.code = e.code
WHERE p.code IS NULL OR p.scope_class <> e.scope_class;


-- Q5: the 5 admin-oversight permissions from 73_admin_manufacturer_oversight.sql.
--     This is the one a table count can NEVER reveal. ZERO ROWS EXPECTED.
SELECT e.code AS problem_permission,
       CASE WHEN p.code IS NULL THEN 'MISSING -- file 73 was never applied'
            ELSE 'exists but granted to NO role' END AS problem
FROM (
            SELECT 'manufacturer.view' AS code
  UNION ALL SELECT 'manufacturer.approve'
  UNION ALL SELECT 'manufacturer.reject'
  UNION ALL SELECT 'monline.po.oversight.view'
  UNION ALL SELECT 'monline.po.oversight.cancel'
) e
LEFT JOIN `rootshiplore_test`.`permissions` p ON p.code = e.code
WHERE p.code IS NULL
   OR NOT EXISTS (SELECT 1 FROM `rootshiplore_test`.`role_permissions` rp
                   WHERE rp.permission_id = p.id);


-- Q6: security / ordering guards. ZERO ROWS EXPECTED.
SELECT 'mfg.* mis-scoped to vendor/shop -- LEAKED TO EVERY VENDOR OWNER' AS problem,
       p.code, p.scope_class
FROM `rootshiplore_test`.`permissions` p
WHERE p.code LIKE 'mfg.%' AND p.scope_class IN ('vendor','shop')
UNION ALL
SELECT 'blank scope_class -- 76-79 were applied BEFORE 70 widened the column',
       p.code, p.scope_class
FROM `rootshiplore_test`.`permissions` p
WHERE p.code LIKE 'mfg.%' AND p.scope_class = ''
UNION ALL
SELECT CONCAT('mfg.* granted to non-manufacturer role: ', r.code), p.code, p.scope_class
FROM `rootshiplore_test`.`role_permissions` rp
JOIN `rootshiplore_test`.`permissions` p ON p.id = rp.permission_id
JOIN `rootshiplore_test`.`roles`       r ON r.id = rp.role_id
WHERE p.code LIKE 'mfg.%'
  AND r.code NOT LIKE 'manufacturer\_%'
  AND r.scope_class <> 'platform';


-- Q7: the msonline -> monline rename (file 72). ZERO ROWS EXPECTED.
SELECT code, 'stale msonline.* code -- file 72 not applied' AS problem
FROM `rootshiplore_test`.`permissions` WHERE code LIKE 'msonline.%'
UNION ALL
SELECT e.c, 'renamed code missing -- vendor staff lose sidebar links'
FROM (SELECT 'monline.browse' AS c UNION ALL SELECT 'monline.order'
      UNION ALL SELECT 'monline.po.view' UNION ALL SELECT 'monline.po.receive') e
LEFT JOIN `rootshiplore_test`.`permissions` p ON p.code = e.c
WHERE p.code IS NULL;


-- Q8: the 9 indexes from files 54, 74 and 75. ZERO ROWS EXPECTED.
--     idx_products_cat_status_del and idx_ps_product_status are not optional --
--     the storefront names them directly in query hints.
SELECT e.tbl AS table_name, e.idx AS missing_index
FROM (
            SELECT 'product_shops'    AS tbl, 'idx_ps_product_status'        AS idx
  UNION ALL SELECT 'products',              'idx_products_cat_status_del'
  UNION ALL SELECT 'products',              'idx_products_status_created'
  UNION ALL SELECT 'products',              'idx_products_del_created'
  UNION ALL SELECT 'product_variants',      'idx_variants_product_default'
  UNION ALL SELECT 'inventory',             'idx_inventory_shop_avail'
  UNION ALL SELECT 'sub_orders',            'idx_suborders_del_created'
  UNION ALL SELECT 'orders',                'idx_orders_del_created'
  UNION ALL SELECT 'mshops',                'idx_mshops_status_del'
) e
LEFT JOIN information_schema.STATISTICS s
       ON s.TABLE_SCHEMA = 'rootshiplore_test'
      AND s.TABLE_NAME   = e.tbl
      AND s.INDEX_NAME   = e.idx
WHERE s.INDEX_NAME IS NULL;
