<?php

declare(strict_types=1);

/**
 * Minimal SQLite-compatible mirror of the production tables a handful of
 * Feature tests reach through real repositories rather than full mocks.
 *
 * This project has no CI4 migrations (`app/Database/Migrations/` is empty —
 * schema lives entirely in `database/sql/*.sql`, applied directly to MariaDB),
 * so `CIUnitTestCase`'s SQLite `tests` DB group starts genuinely empty. Every
 * test using this trait was failing with "no such table: db_X" before it
 * existed — not a bug in the app, a gap in what the test database could ever
 * have contained.
 *
 * Columns mirror the real schema (see the cited file:line for each table)
 * closely enough for the specific queries these tests exercise: ENUM -> TEXT,
 * DECIMAL -> REAL, JSON -> TEXT, BIGINT UNSIGNED -> INTEGER (SQLite has no
 * unsigned type; it does not matter here). This is deliberately NOT a full
 * production-parity mirror — MariaDB-specific features (generated columns,
 * FULLTEXT, CHECK constraints) are dropped where SQLite can't express them
 * and the tests using this trait don't need them.
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS) so multiple test files sharing one
 * PHPUnit process are safe, and each test only calls the pieces it needs.
 */
trait MinimalSchema
{
    /** database/sql/01_master.sql:18 */
    protected function ensureUsersTable(): void
    {
        $this->schemaConn()->query('CREATE TABLE IF NOT EXISTS db_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, principal_type TEXT NOT NULL, name TEXT NOT NULL,
            email TEXT, phone TEXT, password_hash TEXT,
            status TEXT NOT NULL DEFAULT "active",
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
    }

    /**
     * database/sql/01_master.sql:165, gstin_status added by 12_gst_verification.sql:39,
     * business_type_id by 13_business_type_commission.sql, party_type by 70_manufacturer.sql.
     */
    protected function ensureVendorsTable(): void
    {
        $this->schemaConn()->query('CREATE TABLE IF NOT EXISTS db_vendors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, owner_user_id INTEGER, legal_name TEXT NOT NULL, display_name TEXT NOT NULL,
            slug TEXT, gstin TEXT, gstin_verified_at TEXT, gstin_status TEXT NOT NULL DEFAULT "unverified",
            pan TEXT, state_code TEXT, business_type_id INTEGER, party_type TEXT NOT NULL DEFAULT "vendor",
            status TEXT NOT NULL DEFAULT "draft", updated_by INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
    }

    /** database/sql/06_sync.sql:9 */
    protected function ensurePosTerminalsTable(): void
    {
        $this->schemaConn()->query('CREATE TABLE IF NOT EXISTS db_pos_terminals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, shop_id INTEGER NOT NULL, vendor_id INTEGER NOT NULL, name TEXT NOT NULL,
            device_fingerprint TEXT, invoice_prefix TEXT NOT NULL, activation_code TEXT,
            status TEXT NOT NULL DEFAULT "inactive",
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
    }

    /** database/sql/13_business_type_commission.sql:12 */
    protected function ensureBusinessTypesTable(): void
    {
        $this->schemaConn()->query('CREATE TABLE IF NOT EXISTS db_business_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, code TEXT, name TEXT NOT NULL, slug TEXT,
            status TEXT NOT NULL DEFAULT "active"
        )');
    }

    /**
     * database/sql/01_master.sql:380, gstin_status added by 12_gst_verification.sql:43,
     * min_order_value/delivery_fee/free_delivery_above by 44_shop_delivery_rules.sql:7-9,
     * approval_status/approved_by/approved_at/rejected_reason by 85_shop_approval.sql.
     */
    protected function ensureShopsTable(): void
    {
        $this->schemaConn()->query('CREATE TABLE IF NOT EXISTS db_shops (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, vendor_id INTEGER, name TEXT NOT NULL, code TEXT, address_json TEXT NOT NULL DEFAULT "{}",
            gstin TEXT, gstin_verified_at TEXT, gstin_status TEXT NOT NULL DEFAULT "unverified",
            pincode TEXT, state_code TEXT, latitude REAL, longitude REAL,
            delivery_radius_km REAL, pickup_enabled INTEGER NOT NULL DEFAULT 0, prep_time_min INTEGER,
            min_order_value REAL NOT NULL DEFAULT 0, delivery_fee REAL NOT NULL DEFAULT 0, free_delivery_above REAL,
            status TEXT NOT NULL DEFAULT "active",
            approval_status TEXT NOT NULL DEFAULT "not_required", approved_by INTEGER, approved_at TEXT, rejected_reason TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
    }

    /** database/sql/01_master.sql — staff -> vendor assignment, queried by callerVendorIds()-style helpers alongside ownership. */
    protected function ensureVendorStaffTable(): void
    {
        $this->schemaConn()->query('CREATE TABLE IF NOT EXISTS db_vendor_staff (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            vendor_id INTEGER NOT NULL, user_id INTEGER NOT NULL, staff_type TEXT,
            status TEXT NOT NULL DEFAULT "active",
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
    }

    /** database/sql/43_product_shops.sql:18 */
    protected function ensureProductShopsTable(): void
    {
        $this->schemaConn()->query('CREATE TABLE IF NOT EXISTS db_product_shops (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL, shop_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "active",
            listed_at TEXT, created_at TEXT, updated_at TEXT,
            UNIQUE(product_id, shop_id)
        )');
    }

    /** database/sql/04_transaction.sql:128 */
    protected function ensureSubOrdersTable(): void
    {
        $this->schemaConn()->query('CREATE TABLE IF NOT EXISTS db_sub_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, order_id INTEGER NOT NULL, vendor_id INTEGER NOT NULL, shop_id INTEGER NOT NULL,
            sub_order_no TEXT, subtotal REAL NOT NULL DEFAULT 0, discount_total REAL NOT NULL DEFAULT 0,
            taxable_value REAL NOT NULL DEFAULT 0, cgst REAL NOT NULL DEFAULT 0, sgst REAL NOT NULL DEFAULT 0,
            igst REAL NOT NULL DEFAULT 0, cess REAL NOT NULL DEFAULT 0, delivery_total REAL NOT NULL DEFAULT 0,
            round_off REAL NOT NULL DEFAULT 0, grand_total REAL NOT NULL DEFAULT 0,
            commission_amount REAL NOT NULL DEFAULT 0,
            accept_deadline_at TEXT, delivered_at TEXT, place_of_supply TEXT,
            claimed_by_role TEXT, claimed_by_user_id INTEGER, claimed_at TEXT,
            claim_expires_at TEXT, claim_heartbeat_at TEXT, escalation_level TEXT, priority_level TEXT,
            status TEXT NOT NULL DEFAULT "pending",
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
    }

    /**
     * database/sql/... sub_order_claim_logs — queried directly by
     * AdminOrderRepository::claimLogs() via `new AdminOrderRepository()`, which
     * bypasses service() DI entirely and so cannot be mocked.
     */
    protected function ensureSubOrderClaimLogsTable(): void
    {
        $this->schemaConn()->query('CREATE TABLE IF NOT EXISTS db_sub_order_claim_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sub_order_id INTEGER NOT NULL, event TEXT, from_role TEXT, to_role TEXT,
            from_user_id INTEGER, to_user_id INTEGER, reason TEXT, created_at TEXT
        )');
    }

    /**
     * database/sql/10_staff.sql:80, database/sql/04_transaction.sql:748/776 — three
     * raw Database::connect() queries in Vendor\OrderController::show() (not through
     * a mockable repository) join across these to find available riders and whether
     * a pool rider has accepted the delivery.
     */
    protected function ensureDeliveryTables(): void
    {
        $db = $this->schemaConn();
        // The ONE definition of db_delivery_boys. It must carry every column any test
        // uses, because these helpers are CREATE TABLE IF NOT EXISTS against a database
        // shared by the whole PHPUnit process: a second, narrower definition elsewhere
        // would be silently skipped whenever this one ran first, and the failure would
        // surface as "no such column" in an unrelated file depending on test order.
        $db->query('CREATE TABLE IF NOT EXISTS db_delivery_boys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, user_id INTEGER, vendor_id INTEGER,
            vehicle_type TEXT, vehicle_no TEXT,
            availability TEXT NOT NULL DEFAULT "offline", current_lat REAL, current_lng REAL,
            max_active_orders INTEGER NOT NULL DEFAULT 5,
            status TEXT NOT NULL DEFAULT "active",
            created_by INTEGER, created_at TEXT, deleted_at TEXT
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_deliveries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, sub_order_id INTEGER NOT NULL, shop_id INTEGER NOT NULL,
            mode TEXT NOT NULL DEFAULT "self", eta_at TEXT, delivered_at TEXT,
            pod_type TEXT, delivery_fee REAL NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT "pending", deleted_at TEXT
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_delivery_assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            delivery_id INTEGER NOT NULL, rider_user_id INTEGER NOT NULL,
            expires_at TEXT, status TEXT NOT NULL DEFAULT "offered"
        )');
    }

    /** database/sql/04_transaction.sql:1051 */
    protected function ensureSettlementAdjustmentsTable(): void
    {
        $this->schemaConn()->query('CREATE TABLE IF NOT EXISTS db_settlement_adjustments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            settlement_id INTEGER NOT NULL, type TEXT NOT NULL, amount REAL NOT NULL DEFAULT 0,
            reason TEXT, status TEXT NOT NULL DEFAULT "applied",
            created_at TEXT, updated_at TEXT
        )');
    }

    /**
     * database/sql/77_manufacturer_delivery.sql section C, plus the two tables it
     * joins against. `delivery_boys` is the VENDOR table, unchanged: a manufacturer is
     * a `vendors` row, so its riders are ordinary delivery_boys rows — that reuse is
     * the point, so the test exercises the real table rather than a manufacturer-shaped
     * stand-in.
     */
    protected function ensureMfgDeliveryTables(): void
    {
        $db = $this->schemaConn();
        $db->query('CREATE TABLE IF NOT EXISTS db_mfg_deliveries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, po_id INTEGER NOT NULL, mshop_id INTEGER NOT NULL, rider_user_id INTEGER,
            mode TEXT NOT NULL DEFAULT "self", delivery_fee REAL NOT NULL DEFAULT 0,
            eta_at TEXT, assigned_at TEXT, picked_up_at TEXT, delivered_at TEXT,
            failure_reason TEXT, status TEXT NOT NULL DEFAULT "pending",
            created_by INTEGER, updated_by INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT,
            UNIQUE(po_id)
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_mfg_purchase_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, po_no TEXT, buyer_vendor_id INTEGER, buyer_shop_id INTEGER,
            seller_vendor_id INTEGER, seller_mshop_id INTEGER,
            grand_total REAL NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT "placed",
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
        // delivery_boys comes from ensureDeliveryTables(), which owns the single
        // definition — see the note there about why there must only be one.
        $this->ensureDeliveryTables();
    }

    /** Drop the delivery tables — see dropUsersTable() for why leaks matter here. */
    protected function dropMfgDeliveryTables(): void
    {
        $db = $this->schemaConn();
        foreach (['db_mfg_deliveries', 'db_mfg_purchase_orders', 'db_delivery_boys'] as $t) {
            $db->query('DROP TABLE IF EXISTS ' . $t);
        }
    }

    /**
     * database/sql/70_manufacturer.sql section E, plus the serviceability columns
     * 77_manufacturer_delivery.sql adds. Deliberately NO delivery_polygon — `shops`
     * has one and `mshops` does not; a unit's range is a radius here.
     */
    protected function ensureMshopsTable(): void
    {
        $this->schemaConn()->query('CREATE TABLE IF NOT EXISTS db_mshops (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, vendor_id INTEGER NOT NULL, name TEXT NOT NULL, code TEXT,
            gstin TEXT, gstin_verified_at TEXT, gstin_status TEXT NOT NULL DEFAULT "unverified",
            address_json TEXT, pincode TEXT, state_code TEXT, latitude REAL, longitude REAL,
            delivery_enabled INTEGER NOT NULL DEFAULT 0, delivery_radius_km REAL,
            pickup_enabled INTEGER NOT NULL DEFAULT 1, prep_time_min INTEGER,
            min_order_value REAL, delivery_fee REAL, free_delivery_above REAL,
            status TEXT NOT NULL DEFAULT "active",
            created_by INTEGER, updated_by INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
    }

    /** Drop mshops — see dropUsersTable() for why leaked tables matter here. */
    protected function dropMshopsTable(): void
    {
        $this->schemaConn()->query('DROP TABLE IF EXISTS db_mshops');
    }

    /**
     * database/sql/70_manufacturer.sql sections E–F — manufacturer stock.
     *
     * `available` is a STORED GENERATED column in MariaDB; SQLite cannot express that,
     * so it is a plain column here and the service's own writes are what the tests
     * assert on. The two column names that differ from the vendor tables are kept
     * exactly as production has them, because getting them wrong is the specific bug
     * these tables invite: the ledger quantity is `qty` (not `qty_delta`) and the batch
     * cost is `making_cost` (not `cost_price`).
     */
    protected function ensureMfgInventoryTables(): void
    {
        $db = $this->schemaConn();
        $db->query('CREATE TABLE IF NOT EXISTS db_mfg_inventory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, variant_id INTEGER NOT NULL, mshop_id INTEGER NOT NULL,
            on_hand REAL NOT NULL DEFAULT 0, reserved REAL NOT NULL DEFAULT 0,
            available REAL NOT NULL DEFAULT 0, reorder_level REAL,
            status TEXT NOT NULL DEFAULT "out_of_stock",
            created_at TEXT, updated_at TEXT,
            UNIQUE(variant_id, mshop_id)
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_mfg_inventory_ledger (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, variant_id INTEGER NOT NULL, mshop_id INTEGER NOT NULL,
            movement_type TEXT NOT NULL, qty REAL NOT NULL, balance_after REAL NOT NULL DEFAULT 0,
            ref_type TEXT, ref_id INTEGER, note TEXT,
            created_by INTEGER, created_at TEXT
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_mfg_stock_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, variant_id INTEGER NOT NULL, mshop_id INTEGER NOT NULL,
            batch_no TEXT, mfg_date TEXT, exp_date TEXT,
            qty REAL NOT NULL DEFAULT 0, making_cost REAL,
            status TEXT NOT NULL DEFAULT "active",
            created_by INTEGER, created_at TEXT, updated_at TEXT
        )');
    }

    /** database/sql/79_manufacturer_pos.sql — the factory-outlet counter tables. */
    protected function ensureMfgPosTables(): void
    {
        $db = $this->schemaConn();
        $db->query('CREATE TABLE IF NOT EXISTS db_mfg_pos_sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, terminal_id INTEGER, shift_id INTEGER,
            mshop_id INTEGER NOT NULL, vendor_id INTEGER NOT NULL, cashier_user_id INTEGER,
            customer_name TEXT, customer_phone TEXT, client_uuid TEXT,
            invoice_no TEXT, financial_year TEXT NOT NULL, seq_no INTEGER NOT NULL,
            subtotal REAL NOT NULL DEFAULT 0, discount_total REAL NOT NULL DEFAULT 0,
            taxable_value REAL NOT NULL DEFAULT 0, cgst REAL NOT NULL DEFAULT 0,
            sgst REAL NOT NULL DEFAULT 0, igst REAL NOT NULL DEFAULT 0, cess REAL NOT NULL DEFAULT 0,
            round_off REAL NOT NULL DEFAULT 0, grand_total REAL NOT NULL DEFAULT 0,
            payment_method TEXT NOT NULL DEFAULT "cash", notes TEXT,
            sold_at TEXT NOT NULL, status TEXT NOT NULL DEFAULT "completed",
            created_at TEXT, updated_at TEXT,
            UNIQUE(vendor_id, client_uuid),
            UNIQUE(mshop_id, financial_year, seq_no)
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_mfg_pos_sale_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, mfg_pos_sale_id INTEGER NOT NULL, variant_id INTEGER,
            product_title_snapshot TEXT NOT NULL, sku_snapshot TEXT, hsn_snapshot TEXT,
            qty REAL NOT NULL, unit_price REAL NOT NULL, making_price_snapshot REAL,
            discount_amount REAL NOT NULL DEFAULT 0, taxable_value REAL NOT NULL DEFAULT 0,
            tax_rate REAL NOT NULL DEFAULT 0, cgst REAL NOT NULL DEFAULT 0, sgst REAL NOT NULL DEFAULT 0,
            igst REAL NOT NULL DEFAULT 0, cess REAL NOT NULL DEFAULT 0,
            is_gst_inclusive INTEGER NOT NULL DEFAULT 0, line_total REAL NOT NULL DEFAULT 0,
            is_temporary INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT "active",
            created_at TEXT, updated_at TEXT
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_mfg_pos_sale_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, mfg_pos_sale_id INTEGER NOT NULL, tender_type TEXT NOT NULL,
            amount REAL NOT NULL, reference TEXT, status TEXT NOT NULL DEFAULT "captured",
            created_at TEXT, updated_at TEXT
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_mfg_pos_sequence (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mshop_id INTEGER NOT NULL, financial_year TEXT NOT NULL,
            last_invoice_no INTEGER NOT NULL DEFAULT 0,
            created_at TEXT, updated_at TEXT,
            UNIQUE(mshop_id, financial_year)
        )');
    }

    /** Drop the POS tables — see dropUsersTable() for why leaks matter here. */
    /** Warehouses + stock transfers (81_mfg_warehouses.sql). */
    protected function ensureMfgTransferTables(): void
    {
        $db = $this->schemaConn();
        $db->query('CREATE TABLE IF NOT EXISTS db_mfg_stock_transfers (
            id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, transfer_no TEXT,
            vendor_id INTEGER NOT NULL, from_mshop_id INTEGER NOT NULL, to_mshop_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "draft", notes TEXT,
            dispatched_at TEXT, received_at TEXT, created_by INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_mfg_stock_transfer_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT, transfer_id INTEGER NOT NULL,
            variant_id INTEGER NOT NULL, qty REAL NOT NULL DEFAULT 0, qty_received REAL,
            created_at TEXT, updated_at TEXT, UNIQUE(transfer_id, variant_id)
        )');
        $this->ensureMfgInventoryTables();
        $this->ensureMshopsTable();
    }

    /** settlements + settlement_lines, reused by manufacturers (83_mfg_settlements.sql). */
    protected function ensureSettlementTables(): void
    {
        $db = $this->schemaConn();
        $db->query('CREATE TABLE IF NOT EXISTS db_settlements (
            id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, vendor_id INTEGER NOT NULL,
            period_start TEXT, period_end TEXT,
            gross REAL DEFAULT 0, commission_total REAL DEFAULT 0, refund_total REAL DEFAULT 0,
            tcs REAL DEFAULT 0, tds REAL DEFAULT 0, fees REAL DEFAULT 0, adjustments REAL DEFAULT 0,
            net_payable REAL DEFAULT 0, idempotency_key TEXT,
            status TEXT NOT NULL DEFAULT "draft", created_by INTEGER,
            created_at TEXT, updated_at TEXT, UNIQUE(idempotency_key)
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_settlement_lines (
            id INTEGER PRIMARY KEY AUTOINCREMENT, settlement_id INTEGER NOT NULL,
            ref_type TEXT NOT NULL, ref_id INTEGER, sub_order_id INTEGER,
            amount REAL DEFAULT 0, direction TEXT NOT NULL, created_at TEXT
        )');
    }

    protected function dropSettlementTables(): void
    {
        foreach (['db_settlement_lines', 'db_settlements'] as $t) {
            $this->schemaConn()->query('DROP TABLE IF EXISTS ' . $t);
        }
    }

    protected function dropMfgTransferTables(): void
    {
        foreach (['db_mfg_stock_transfer_items', 'db_mfg_stock_transfers'] as $t) {
            $this->schemaConn()->query('DROP TABLE IF EXISTS ' . $t);
        }
        $this->dropMfgInventoryTables();
        $this->dropMshopsTable();
    }

    /** Counter returns + credit notes (82_mfg_pos_returns.sql). */
    protected function ensureMfgReturnTables(): void
    {
        $this->ensureMfgPosTables();
        // Returns put stock BACK, so the inventory tables are part of this fixture —
        // ensureMfgPosTables() does not bring them.
        $this->ensureMfgInventoryTables();
        $this->ensureMshopsTable();
        // Returns put stock BACK, so the inventory tables are part of this fixture —
        // ensureMfgPosTables() does not bring them.
        $this->ensureMfgInventoryTables();
        $this->ensureMshopsTable();
        $db = $this->schemaConn();
        $db->query('CREATE TABLE IF NOT EXISTS db_mfg_pos_returns (
            id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, credit_note_no TEXT,
            financial_year TEXT, seq_no INTEGER, mfg_pos_sale_id INTEGER NOT NULL,
            mshop_id INTEGER NOT NULL, vendor_id INTEGER NOT NULL, reason TEXT,
            refund_method TEXT NOT NULL DEFAULT "cash",
            taxable_value REAL DEFAULT 0, cgst REAL DEFAULT 0, sgst REAL DEFAULT 0,
            igst REAL DEFAULT 0, total REAL DEFAULT 0,
            status TEXT NOT NULL DEFAULT "completed", created_by INTEGER,
            created_at TEXT, updated_at TEXT
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_mfg_pos_return_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT, return_id INTEGER NOT NULL,
            mfg_pos_sale_item_id INTEGER NOT NULL, variant_id INTEGER,
            qty REAL DEFAULT 0, unit_price REAL DEFAULT 0, taxable_value REAL DEFAULT 0,
            tax_rate REAL DEFAULT 0, cgst REAL DEFAULT 0, sgst REAL DEFAULT 0,
            line_total REAL DEFAULT 0, created_at TEXT
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_mfg_pos_cn_sequence (
            id INTEGER PRIMARY KEY AUTOINCREMENT, mshop_id INTEGER NOT NULL,
            financial_year TEXT NOT NULL, last_cn_no INTEGER NOT NULL DEFAULT 0,
            created_at TEXT, updated_at TEXT, UNIQUE(mshop_id, financial_year)
        )');
    }

    protected function dropMfgReturnTables(): void
    {
        foreach (['db_mfg_pos_return_items', 'db_mfg_pos_returns', 'db_mfg_pos_cn_sequence'] as $t) {
            $this->schemaConn()->query('DROP TABLE IF EXISTS ' . $t);
        }
        $this->dropMfgInventoryTables();
        $this->dropMfgPosTables();
    }

    protected function dropMfgPosTables(): void
    {
        $db = $this->schemaConn();
        foreach (['db_mfg_pos_sales', 'db_mfg_pos_sale_items', 'db_mfg_pos_sale_payments', 'db_mfg_pos_sequence'] as $t) {
            $db->query('DROP TABLE IF EXISTS ' . $t);
        }
    }

    /** Drop the manufacturer stock tables — see dropUsersTable() for why this matters. */
    protected function dropMfgInventoryTables(): void
    {
        $db = $this->schemaConn();
        foreach (['db_mfg_inventory', 'db_mfg_inventory_ledger', 'db_mfg_stock_batches'] as $t) {
            $db->query('DROP TABLE IF EXISTS ' . $t);
        }
    }

    /** database/sql/01_master.sql:522 */
    protected function ensureCategoriesTable(): void
    {
        $this->schemaConn()->query('CREATE TABLE IF NOT EXISTS db_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, parent_id INTEGER, name TEXT NOT NULL, slug TEXT NOT NULL,
            path TEXT NOT NULL, level INTEGER NOT NULL DEFAULT 0, sort_order INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT "active",
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
    }

    /**
     * database/sql/01_master.sql:684, product_type/visibility/available_from/
     * available_to added by 16_product_module.sql:40-64.
     */
    /**
     * `products`/`product_variants` are shared across SIX test files that each used to
     * carry their own bespoke, mutually-incompatible CREATE TABLE IF NOT EXISTS —
     * StoreCatalogRepositoryVendorStatusTest, ManufacturerPosSaleTest,
     * ManufacturerComboTest, ManufacturerInventoryServiceTest,
     * ManufacturerProductRepositoryTest, ManufacturerProductMshopTest. Whichever ran
     * first under --order-by=random won the race and silently locked every OTHER file
     * into its own narrower schema for the rest of the process — this is not
     * hypothetical, it broke three different files across three separate randomised
     * runs while this phase was being built. Columns here are the UNION of every
     * column ANY of the six references by name in a raw INSERT (missing a column a
     * file's INSERT names is fatal — "no such column" — even though the row it fills
     * with is otherwise unrelated to that file's own assertions), and every column is
     * nullable or DEFAULTed unless every caller always supplies it (vendor_id,
     * product_id): NOT NULL with no default is the one thing that turns "another
     * file's fixture ran first" into a hard failure for a column THIS file never
     * touches. The four Manufacturer* files above still carry their own local
     * CREATE TABLE IF NOT EXISTS calls (harmless — a no-op once this version exists);
     * only ManufacturerPosSaleTest and this repository's own test call these directly.
     * base_unit_id and unit_id are BOTH kept as separate columns rather than
     * reconciled to one name — Combo's fixture calls it unit_id, Mshop's calls it
     * base_unit_id (the real schema's actual name, 01_master.sql:692); resolving which
     * one is a naming slip in a file this work did not otherwise touch is out of scope.
     */
    protected function ensureProductsTable(): void
    {
        $this->schemaConn()->query('CREATE TABLE IF NOT EXISTS db_products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, vendor_id INTEGER NOT NULL, category_id INTEGER, brand_id INTEGER,
            tax_class_id INTEGER, hsn_id INTEGER, unit_id INTEGER, base_unit_id INTEGER,
            title TEXT, slug TEXT, description TEXT,
            is_online_enabled INTEGER NOT NULL DEFAULT 1,
            is_pos_enabled INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT "draft",
            product_type TEXT NOT NULL DEFAULT "simple",
            combo_inventory_mode TEXT,
            visibility TEXT NOT NULL DEFAULT "public",
            available_from TEXT, available_to TEXT,
            created_by INTEGER, updated_by INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
    }

    /** database/sql/01_master.sql:730; making_price added by 70_manufacturer.sql for the factory-outlet POS. */
    protected function ensureProductVariantsTable(): void
    {
        $this->schemaConn()->query('CREATE TABLE IF NOT EXISTS db_product_variants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, product_id INTEGER NOT NULL, vendor_id INTEGER, unit_id INTEGER,
            sku TEXT, barcode TEXT, mrp REAL NOT NULL DEFAULT 0, base_price REAL NOT NULL DEFAULT 0,
            making_price REAL,
            is_default INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT "active",
            created_by INTEGER, updated_by INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
    }

    /**
     * database/sql/perf3_facet_tables.php — created by a maintenance script outside
     * the numbered migration chain, and queried WITHOUT the CI4 DBPrefix (confirmed:
     * the failing error is "no such table: category_facet_summary", not
     * "db_category_facet_summary" like every other table here) — so this one must
     * NOT be prefixed to match.
     */
    protected function ensureCategoryFacetSummaryTable(): void
    {
        $this->schemaConn()->query('CREATE TABLE IF NOT EXISTS category_facet_summary (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scope_key TEXT NOT NULL, category_slug TEXT NOT NULL DEFAULT "",
            payload TEXT NOT NULL, computed_at INTEGER NOT NULL,
            UNIQUE(scope_key, category_slug)
        )');
    }

    /**
     * Insert (or reactivate) a row `ApiAuthRepository::isActive()` will accept — every
     * JWT-authenticated request re-checks this on every hit (JwtAuthFilter.php), so a
     * bearer token alone is never enough against a real (non-mocked) apiAuthRepository.
     */
    protected function seedActiveUser(int $id, string $principalType, string $name = 'Test User'): void
    {
        $db = $this->schemaConn();
        $db->table('users')->where('id', $id)->delete();
        $db->query(
            'INSERT INTO db_users (id, uuid, principal_type, name, status) VALUES (?, ?, ?, ?, ?)',
            [$id, 'test-uuid-' . $id, $principalType, $name, 'active'],
        );
    }

    /**
     * WebAuthFilter re-checks apiAuthRepository->isActive() on EVERY admin/vendor
     * request (fail-open only when the query throws — see ensureUsersTable()'s
     * docblock). SQLite `:memory:` is one connection shared by the whole PHPUnit
     * process, so a table created here outlives this file and silently flips every
     * OTHER test's unmocked session check from fail-open to fail-closed. Call this
     * in tearDown() (any file that calls ensureUsersTable() must) so the table
     * doesn't leak past this file.
     */
    protected function dropUsersTable(): void
    {
        $this->schemaConn()->query('DROP TABLE IF EXISTS db_users');
    }

    /** Everything this trait knows how to create, for a test that just wants it all. */
    protected function ensureMinimalSchema(): void
    {
        $this->ensureUsersTable();
        $this->ensureVendorsTable();
        $this->ensureShopsTable();
        $this->ensureProductShopsTable();
        $this->ensureSubOrdersTable();
        $this->ensureSettlementAdjustmentsTable();
        $this->ensureCategoryFacetSummaryTable();
    }

    protected function schemaConn(): object
    {
        return \Config\Database::connect();
    }
}
