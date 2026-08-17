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
            status TEXT NOT NULL DEFAULT "draft",
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
    }

    /** database/sql/01_master.sql:380, gstin_status added by 12_gst_verification.sql:43 */
    protected function ensureShopsTable(): void
    {
        $this->schemaConn()->query('CREATE TABLE IF NOT EXISTS db_shops (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, vendor_id INTEGER, name TEXT NOT NULL, code TEXT,
            gstin TEXT, gstin_verified_at TEXT, gstin_status TEXT NOT NULL DEFAULT "unverified",
            pincode TEXT, state_code TEXT, latitude REAL, longitude REAL,
            delivery_radius_km REAL, pickup_enabled INTEGER NOT NULL DEFAULT 0, prep_time_min INTEGER,
            status TEXT NOT NULL DEFAULT "active",
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
        $db->query('CREATE TABLE IF NOT EXISTS db_delivery_boys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, user_id INTEGER, vendor_id INTEGER,
            availability TEXT NOT NULL DEFAULT "offline", current_lat REAL, current_lng REAL,
            status TEXT NOT NULL DEFAULT "active"
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
            path TEXT NOT NULL, level INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT "active",
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
