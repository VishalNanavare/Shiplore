# MySQL DDL — Database `test`

Executable MySQL schema generated from the design in [`docs/database/`](../../docs/database/00-DATABASE-DESIGN.md).
**Validated against MySQL 9.6.0** — loads clean: **188 tables (184 base + 4 staff/delivery), 265 foreign keys, 0 orphan FKs.**
Seed (`11_seed.sql`) is **idempotent** (re-run safe) and loads RBAC catalog (142 permissions, 20 roles, 372 mappings), GST masters, a bootstrap super-admin, and a demo vendor→shop→staff→delivery→product chain.

## Files (run in order)

| # | File | Contents |
|---|---|---|
| 00 | `00_init.sql` | `CREATE DATABASE test`, charset/session, `FOREIGN_KEY_CHECKS=0` |
| 01 | `01_master.sql` | Identity, vendor, shop/geo, catalog, product, pricing, media, content, loyalty masters |
| 02 | `02_configuration.sql` | Settings, plans, commission/promotion rules, approval/search config, integrations |
| 03 | `03_gst.sql` | HSN/SAC, tax classes/rates, gst_config, tax breakups, TCS/TDS, GSTR exports |
| 04 | `04_transaction.sql` | Cart, orders/invoices, inventory ledger, POS sales, returns, delivery, reviews, commission/settlement, jobs & logs |
| 05 | `05_payment.sql` | Payments, attempts, webhooks, refunds, reconciliation, wallets, double-entry ledger |
| 06 | `06_sync.sql` | POS terminals, sequences, sync batches/entities/cursors/conflicts, sessions/tokens |
| 07 | `07_notification.sql` | Templates, in-app inbox, deliveries, FCM tokens, preferences, campaigns |
| 08 | `08_audit.sql` | Append-only audit logs (hash-chained), login attempts, checkpoints, access logs |
| 09 | `09_finalize.sql` | `FOREIGN_KEY_CHECKS=1`, table-count report |
| 10 | `10_staff.sql` | Vendor staff taxonomy, staff↔shop assignment, delivery-boy profile, attendance (delta) |
| 11 | `11_seed.sql` | Idempotent seed: RBAC catalog, GST/units/payment masters, bootstrap admin, demo chain |
| — | `run_all.sql` | `SOURCE`s 00→10→09→11 in order |

## How to run

`FOREIGN_KEY_CHECKS` is disabled across files 00–08 so cross-file FK references resolve regardless of physical create order; 09 re-enables it.

**Option A — interactive client (`SOURCE` works here):**
```
D:\webserver\mysql\bin\mysql.exe -u root
mysql> SOURCE run_all.sql;
```

**Option B — batch via stdin (`SOURCE` is NOT supported over `<`; concatenate instead).**
PowerShell:
```powershell
cd d:\webserver\www\test\database\sql
$files = '00_init.sql','01_master.sql','02_configuration.sql','03_gst.sql','04_transaction.sql','05_payment.sql','06_sync.sql','07_notification.sql','08_audit.sql','09_finalize.sql'
($files | ForEach-Object { Get-Content $_ -Raw }) -join "`r`n" | Set-Content _combined.sql -Encoding utf8 -NoNewline
cmd /c '"D:\webserver\mysql\bin\mysql.exe" -u root < _combined.sql'
```

## Conventions baked into the DDL

- Engine **InnoDB**, charset **utf8mb4 / utf8mb4_unicode_ci**, connection `root` (no password, local/dev).
- PK `id BIGINT UNSIGNED AUTO_INCREMENT`; sync identity `uuid CHAR(36)` UNIQUE on replicable tables.
- Money `DECIMAL(15,4)`, quantity `DECIMAL(15,3)`, rate `DECIMAL(5,2)` — never FLOAT.
- `created_at`/`updated_at` on every mutable table (`AUDIT_TS`); `deleted_at` (`SOFT_DELETE`), `created_by`/`updated_by` (`BLAME`), and entity `status` on business entities.
- **Append-only tables** (audit logs, ledgers, *_history, *_attempts, logs) deliberately keep **only `created_at`** — immutable by design; corrections are new rows.
- Generated column: `inventory.available = on_hand - reserved` (STORED).
- FK actions: `CASCADE` on owned children, `RESTRICT` on masters, `SET NULL` on optional/reviewer/blame refs. Polymorphic owners (`media_assets.owner_*`, `audit_logs.entity_*`) carry **no FK** (app-enforced).
- `created_by`/`updated_by` are indexed but **not** FK-constrained to `users` (avoids making `users` a hub of ~300 FKs; integrity enforced in the app layer). Meaningful user references (owner, reviewer, cashier, approver, rider, resolver) **do** carry FKs.

## Notes

- This is **DDL only** (structure). Seed data (permissions, roles, tax classes/rates, units, categories) is a separate step — see seeding order in [`docs/database/00 §4`](../../docs/database/00-DATABASE-DESIGN.md) / [architecture Doc 20 §4](../../docs/architecture/20-TABLE-DEPENDENCY-HIERARCHY.md).
- High-volume append-only tables (`inventory_ledger`, `audit_logs`, `notification_deliveries`, `ledger_entries`, `pos_sales`, `rider_locations`, `search_logs`) are candidates for monthly RANGE partitioning at scale — not applied here to keep the base DDL portable.
- To re-load from scratch: `DROP DATABASE test; ` then run again (⚠ destroys data).
