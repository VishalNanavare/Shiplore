# Security, Performance & Architecture Audit — Shiplore

**Audit B — independent re-scan** · 2026-08-07
Scope: main application (`app/`, `public/`, `database/`) **+ `s3_storage/`**. `pma/` excluded at the operator's instruction.

This audit was run from source with no reference to any previous scan, at the operator's
request. Where it lands on the same code as an earlier review that is convergence, not
inheritance — every finding below was re-derived and re-verified here.

**Standing constraint, applied to every recommendation:** this is a live production
application with shipped mobile apps on `api/v1`. No business-logic redesign, no workflow
or UX change, no framework migration, no feature removal. Where a fix could not meet that
bar it was staged log-only or left for the operator, and is named as such in
§ *Recommendations that carry breakage risk*.

---

## 1. What was audited

| | |
|---|---|
| Framework | CodeIgniter 4.7.3, vendored in-tree at `system/` (not a Composer dependency) |
| Runtime | PHP 8.5 · MariaDB 10.11 (production) / MySQL 9.6 (local) |
| Application | 731 PHP files, 79,720 lines — 135 controllers, 112 repositories, 338 views |
| Routes | `app/Config/Routes.php`, 1,065 lines |
| Secondary app | `s3_storage/` — 60 PHP files, 8,251 lines |
| Tests | 149 files, 18,421 lines |
| Production scale | 1,000,531 published products · 5,393,036 `product_shops` rows · 10,001 active shops |

Verification used the real database throughout. Three throwaway databases were built and
dropped during this audit: one to reproduce the two money races under the server's actual
`REPEATABLE-READ` isolation, and two to build the schema from `run_all.sql` before and
after the fix. The existing local `test` database (272 tables) was never written to.

---

## 2. Findings

Severity: **Critical** — exploitable now, or currently breaking production ·
**High** — exploitable with a precondition · **Medium** — real but bounded ·
**Low** — hygiene.

---

### C1 · Unauthenticated S3 upload endpoint using live AWS credentials

- **Severity:** Critical
- **Location:** `s3_storage/public/s3test.php` (whole file, 555 lines) — `create_presigned()` :56, `init_multipart()` :105, `presign_part()` :140, `complete_multipart()` :178, `normal_upload()` → `putObject()` :224/:246; credentials read at :46-47
- **Description:** A git-tracked test harness sat inside the `s3_storage/public/` directory — the likely `DocumentRoot` for `s3.shiplore.in` — with no authentication check of any kind. It exposed presigned-URL minting, multipart initiation and direct `putObject`. The `Require all denied` intended to protect it lives in `s3_storage/.htaccess`, one level **above** that docroot, so Apache never reads it for that vhost.
- **Proof:** The file contains no session, token or signature check on any branch; every handler is reachable by `POST action=…`. Credentials come from process env at :46-47, which the vhost supplies.
- **Fix:** File deleted (recoverable from git history).
- **Regression risk:** None to the application. The endpoint was not referenced by any application code — confirmed by searching the whole tree for `s3test`.
- **Testing:** After deploy, `s3.shiplore.in/s3test.php` must return 404.
- **Status:** Fixed — `c29f739`.

### C2 · Live AWS credentials committed to `s3_storage/.env`

- **Severity:** Critical
- **Location:** `s3_storage/.env:16-17`
- **Description:** The access key and secret are live, confirmed by the operator. Exposure is by file reachability (C1, C3), not by logging — the application never echoes or logs them, which was checked.
- **Fix:** Rotation runbook in § 7. **Operator-executed** — cannot be done from here.
- **Regression risk:** Media breaks if the old key is revoked before the new one is in place. The runbook is ordered to make that impossible.
- **Testing:** Admin S3 self-test must pass on the new key *before* the old one is revoked.
- **Status:** **Open — operator action required.**

### C3 · Anonymous reads of the S3 server's own runtime directories

- **Severity:** Critical
- **Location:** `s3_storage/app/Controllers/S3Controller.php::isPublicReadRequest()` :259-312 (`'temp'` at :298); `s3_storage/app/Libraries/S3Storage.php::validateBucketName()` :840
- **Description:** `isPublicReadRequest()` bypassed `authenticate()` for GET/HEAD on 31 hard-coded key prefixes. It took a `$bucket` argument and never consulted it, so those prefixes — including `payments/invoice/`, `tickets/` and `reports/bulk/` — were anonymously readable in **every** bucket. `'temp'` had no trailing slash, so it also matched `tempsecrets.json`. Compounding it, `s3.storagePath=writable` made every subdirectory a bucket: `cache/`, `logs/`, `session/`, `debugbar/` all satisfied the bucket-name regex.
- **Fix:** `isPublicReadRequest()` now returns false when a configured public bucket does not match; `'temp'` → `'temp/'`; `S3Storage` gained `RESERVED_BUCKETS = ['cache','logs','session','debugbar']`, enforced in both `validateBucketName()` and `listBuckets()`.
- **Regression risk:** `publicReadBucket` defaults to **empty**, which preserves current behaviour exactly. Narrowing only takes effect once the operator sets it — deliberately, because naming the wrong bucket would 403 live public media.
- **Testing:** Reserved names are rejected by both methods.
- **Status:** Fixed — `c29f739`. **Requires operator config** to complete: set `s3.publicReadBucket` to the media bucket.

### C4 · Panel separation is not enforced

- **Severity:** Critical
- **Location:** `app/Config/Routes.php` (path-based groups) · `app/Config/Cookie.php:48` (`$domain='.shiplore.in'`) · `app/Filters/WebAuthFilter.php:131`
- **Description:** The operator demonstrated this directly: `https://shiplore.in/monline/browse?manufacturer=921` serves the same page as `https://monline.shiplore.in/...`. Subdomains are decorative. Route groups are path-based, the session cookie is issued for the whole `.shiplore.in` zone, and `WebAuthFilter` **logs and allows** on a principal-type mismatch unless `auth.enforcePrincipalType=true`. Customer and rider principals are already blocked unconditionally at :119; staff cross-panel access is not.
- **Fix:** Enable `auth.enforcePrincipalType` — **after** a traffic-log review confirms no legitimate account is mislabelled.
- **Regression risk:** **HIGH — flagged.** If any real staff account carries an unexpected `principal_type`, enabling this locks them out. This is why it ships log-only.
- **Testing:** Read the "principal type" notices for one full traffic day; every distinct combination seen must be a real, intended one before flipping.
- **Status:** **Open — operator decision.** The enforcement path is already built and one env var away.

### C5 · Storefront performance — 22 s per query, 7–8 queries per page

- **Severity:** Critical (production impact — pages took 50 s to 1 m 25 s)
- **Location:** `app/Models/StoreCatalogRepository.php` — index hints at :122, :197, :248, :410, :454, :479, :501, :512; `computeTree()`
- **Description:** Two compounding causes, both measured on production.
  1. `product_shops` (5.39 M rows) had no covering index for either access path. `uq_product_shop(product_id,shop_id)` lacks `status`; `idx_ps_shop(shop_id,status)` lacks `product_id` — so every probe cost a PK lookup per row.
  2. The repository pinned `USE INDEX`/`FORCE INDEX (idx_products_cat_status_del)` whenever a category filter was active. That is right for an unscoped browse, but with a location scope present it forces the optimiser to start at `products` and test the `product_shops` EXISTS per row, instead of starting from `product_shops` and `eq_ref`-ing into `products`.
- **Proof:** Operator-supplied `EXPLAIN` and timings. Two covering indexes took query **E from 15.3 s to 0.19 s**. Query **F remained 22.4 s** with the hint, and the operator's own `EXPLAIN` showed MySQL choosing the fast materialize → `eq_ref` plan when *not* hinted. Scope: a 200-shop location covers 95,650 distinct products, ~9.5% of the catalogue.
- **Fix:** `categoryIndexHint()` now returns the hint only when a category filter is present **and** no shop scope is set; all 7 conditional sites route through it. `computeTree()` changed from a single-shop test to `array_key_exists('shop_ids', $opts)` — the multi-shop case is the common one and previously kept the pin.
- **Regression risk:** Low. The hint is dropped only where it was demonstrably the wrong plan; unscoped browse behaviour is untouched. Same rows returned either way — only the access path changes.
- **Testing:** 7 unit tests (`CatalogIndexHintTest`). Re-run the production `EXPLAIN` for E and F after deploy; F should fall well under 1 s.
- **Status:** Fixed — `a8adde7`. **Indexes already applied to production by the operator.**

> **Measurement caveat, stated so the numbers are not over-read:** one of my own
> measurement queries (F) embedded the shop-selection subquery inside the `EXISTS`, making
> MySQL re-evaluate a haversine over 3,800 shops per candidate row. The real application
> passes a literal id list. The 22.4 s figure is therefore an upper bound on that shape,
> not a direct reproduction of the application's query.

### C6 · Hard-coded `root`/`root@123` database credentials in 14 web-root scripts

- **Severity:** Critical
- **Location:** `database/sql/perf1_indexes.php:9-12`, `perf2_indexes.php:14-19`, `perf3_facet_tables.php`, `mega1_truncate.php` … `mega8_remap_categories.php`, `seed_mumbai_demo.php`, `topup2.php`, `topup_products.php`
- **Description:** Fourteen maintenance scripts carried the production root password in plaintext, inside the web root. They are unreachable only because `database/.htaccess` denies the directory — an Apache-only guarantee that a server-config change or a different SAPI removes.
- **Fix:** New `database/sql/_db.php` providing `shiplore_env()` and `shiplore_pdo()`. It parses the project `.env` directly (these scripts never boot CI4), accepts both `database.default.*` and `DB_*` key forms, and fails loudly rather than falling back to a default. All 14 scripts reduced to `require __DIR__ . '/_db.php'; $pdo = shiplore_pdo();`.
- **Regression risk:** None to the application — these are operator-run maintenance scripts. They now fail with a clear message if `.env` lacks credentials, instead of silently using root.
- **Testing:** All 15 files lint clean.
- **Status:** Fixed — `7d63f9d`.

---

### H1 · Password login had no principal gate — admin credentials minted a mobile JWT

- **Severity:** High
- **Location:** `app/Controllers/Api/V1/AuthApiController.php::login()`
- **Description:** The OTP path (`otpVerify()`) and the Firebase path (`firebaseVerify()`) both pin `principal_type === 'customer'`. Password login pinned nothing. `apiAuthRepository::findByIdentifier()` matches email OR phone with no principal predicate, so a platform-admin credential authenticated here and minted a 30-day token (`TTL = 2592000`) carrying `typ = platform` — which `CapabilityResolver` then resolves to that admin's real permissions across `api/v1`. Vertical privilege escalation from one un-gated endpoint.
- **Fix:** `MOBILE_PRINCIPALS = ['customer','vendor','rider']` allow-list, checked **after** `password_verify()` and **before** minting. Placement matters: gating earlier would turn the endpoint into a staff-account oracle. The rejection is recorded in `login_attempts` under its own reason rather than looking like a bad password.
- **Regression risk:** Low, but real if any legitimate mobile user has an unexpected `principal_type`. An allow-list was chosen over a deny-list so a principal added later is excluded until someone decides otherwise.
- **Testing:** `MobileAuthPrincipalGateTest` — gate present, strict comparison pinned, list contents pinned, and ordering between `password_verify()` and the mint asserted. All mutants killed.
- **Status:** Fixed — `8cfc676`.

### H2 · The one JWT mint site that did not fail closed

- **Severity:** High
- **Location:** `app/Controllers/Api/V1/VendorApiController.php::refresh()`
- **Description:** This mint read the signing key with `env('JWT_SECRET', '')`, which yields the empty string when the variable is unset. Every other mint site uses `TokenService::secret()`, which throws and additionally rejects the committed `INSECURE_DEFAULT` placeholder. An empty HMAC key lets anyone forge a token for any user.
- **Fix:** Switched to `TokenService::secret()`. Also now carries the `pwd` password-binding claim the other mint sites set, so a password change revokes tokens issued here too.
- **Regression risk:** None while `jwt.secret` is set — which the operator must confirm (§ 7). If it is *not* set, this endpoint now fails loudly instead of issuing forgeable tokens. That is the correct behaviour and the reason for the change.
- **Testing:** Asserted against comment-stripped source, because the fix carries a comment naming the old construct.
- **Status:** Fixed — `8cfc676`.

### H3 · Cross-tenant inventory write

- **Severity:** High
- **Location:** `app/Controllers/Api/V1/VendorApiController.php::inventoryLedger()` · `inShopScope()` :316-321 · `app/Libraries/Inventory/InventoryService::ensureRow()`
- **Description:** `shop_id` and `variant_id` come from the request body. `inShopScope()` returns `true` unconditionally for an owner, because `shopScope()` is `null` for them. `InventoryService::receive()` carries no vendor predicate and `ensureRow()` **inserts**. So a vendor owner could write stock rows against another tenant's shop and variant. The web equivalent (`Vendor/ProductInventoryController.php:53`) is correctly scoped.
- **Fix:** New `ownsVariantAndShop()` checking **both** `shops.vendor_id` and `product_variants.vendor_id`, applied after `inShopScope()`.
- **Regression risk:** Low. A vendor operating on their own shop and variant is unaffected; the check only rejects pairs the caller does not own.
- **Testing:** Gate presence, both table lookups, both vendor-id predicates, and ordering before the write. A mutant that stopped checking the variant is killed.
- **Status:** Fixed — `8cfc676`.

### H4 · Any authenticated principal could bind a POS terminal

- **Severity:** High
- **Location:** `app/Controllers/Api/V1/PosController.php::activate()` · `app/Models/PosSyncRepository.php::activate()`
- **Description:** Every other method in `PosController` resolves its terminal through `terminal()`, which binds it to `callerVendorIds()`. `activate()` did not — it matched on the activation code alone. Any principal with a valid JWT, including a self-registered storefront customer, could bind their device fingerprint to another tenant's terminal.
- **Fix:** The vendor predicate is applied **inside the lookup**, not after it. This is required, not stylistic: `PosSyncRepository::activate()` writes the device fingerprint as soon as a row matches, so a post-hoc rejection would return an error *after* the binding was persisted. The repository took an optional allow-list (`null` preserves prior behaviour for CLI use; `[]` matches nothing, because `whereIn` with an empty array is a no-op in some drivers). Its single caller was confirmed by grep before the signature changed.
- **Regression risk:** Low. A cashier activating their own vendor's terminal is unaffected. The error message is deliberately identical for "wrong code" and "not your terminal" so this cannot become an activation-code oracle.
- **Testing:** Predicate applied before the query, query before the write, empty-list short-circuit — each mutation-checked.
- **Status:** Fixed — `8cfc676`.

### H5 · Three stored-XSS sinks in the product form

- **Severity:** High
- **Location:** `public/assets/js/product-form.js` **and** `assets/js/product-form.js` (two copies) — category dropdown, shop dropdown, media-library tile
- **Description:** Markup built by string concatenation and assigned to `innerHTML`, interpolating free text typed into a panel: the category name, the vendor-controlled shop name, and `im.url` into a `src=""` attribute. The third is sharpest — a stored value containing a double quote closes the attribute, so `" onerror="…` executes with no script tag. All three render inside admin and vendor panels, making this a vendor-reaches-admin path.
- **Fix:** New `optionEl()` builds options with `createElement` + `textContent` — the pattern `rebuildBrandSelect()` in the same file already used. The thumbnail is created as an element with `.src` assigned as a property and inserted as the tile's first child, preserving render order and styling exactly.
- **Regression risk:** None. Rendered output is identical; only node construction changed. Verified with `node --check` on both copies.
- **Testing:** 12 tests, parameterised over **both** copies. The first commit fixed only the served copy, which broke the pre-existing `XssSinkTest::testJsAssetsAreMirrored` (33 → 34 failures) and left the sinks in the other file — caught and corrected in `7e2b8a9`.
- **Two sites examined and cleared:** the completeness checklist maps a hard-coded literal array; the local image preview interpolates a `FileReader` `readAsDataURL` result, which is base64 of the file's bytes and cannot contain a quote. A test pins the second so any *new* variable concatenated into a `src` fails.
- **Status:** Fixed — `1866c8d`, `7e2b8a9`.

### H6 · Lost update on the customer-credit (udhaar) ledger

- **Severity:** High
- **Location:** `app/Models/CreditRepository.php::repay()`
- **Description:** The balance was read **outside** the transaction with no lock, the new balance computed in PHP, and an **absolute** value written back inside it.
- **Proof — reproduced against MySQL 9.6 at the server's real `REPEATABLE-READ`:** two cashiers each recording a ₹400 repayment against the same ₹1,000 udhaar both read 1000 and both wrote 600. **Measured: balance 600 with 800 collected**, and two `credit_repayments` rows. The customer holds two valid receipts; the vendor's book is short ₹400.
- **Fix:** The read moved inside the transaction under `FOR UPDATE` — the same shape already used in `InventoryService::reserve()`. **Measured after: 200.** The two guards that now sit after the lock each roll back before returning, so neither path exits holding an open transaction and a row lock.
- **Regression risk:** Low. A single repayment behaves identically. Concurrent repayments now serialise briefly on the credit row.
- **Testing:** Lock present, taken inside the transaction, held before the write, and each guard's rollback matched **inside its own block**. The first version of that last assertion was vacuous — it searched backwards and found the *preceding* guard's rollback — caught by mutation and rewritten.
- **Status:** Fixed — `97f61ea`.

### H7 · Coupon `per_user_limit` bypass under concurrency

- **Severity:** High
- **Location:** `app/Models/StoreOrderRepository.php` (coupon block)
- **Description:** Subtler than it looks. The `UPDATE coupons SET used_count = used_count + 1` above the check *does* X-lock the coupon row, so two checkouts of the same coupon genuinely serialise. But under `REPEATABLE READ` a plain `SELECT` is a consistent read served from the snapshot the transaction took at its **first** read — which happened before that `UPDATE` blocked. So the second checkout counted redemptions as of before the first committed, saw zero, and inserted.
- **Proof:** Reproduced with `per_user_limit = 1`: **two redemptions**. After the fix: **one**.
- **Fix:** The count is now a locking read (`FOR UPDATE`), which sees the latest committed version, and its gap lock stops a concurrent insert slipping in behind it.
- **Regression risk:** Low. A unique key on `(coupon_id, customer_id)` was considered and **rejected** — it would break any coupon with `per_user_limit > 1`.
- **Testing:** Locking read pinned; the old `countAllResults()` asserted gone; ordering after the coupon `UPDATE` and before the insert asserted.
- **Status:** Fixed — `97f61ea`.

### H8 · SigV4 signed the *claimed* payload hash, never the payload

- **Severity:** High
- **Location:** `s3_storage/app/Libraries/SigV4Auth.php::resolvePayloadHash()` :320-324
- **Description:** `x-amz-content-sha256` goes into the canonical request, so the signature covers the **claim**. Nothing ever hashed the body to test it. Capture a signed PUT — this service does not force HTTPS (H10) — swap the body, leave the header alone, and the signature still verifies against attacker-chosen bytes.
- **Fix:** Signature maths untouched. A new `signedPayloadHash()` exposes the claim to the write path, returning `''` for anything that is not a bare 64-char hex digest — so `UNSIGNED-PAYLOAD` (presigned URLs) and `STREAMING-*` (aws-chunked, whose wire bytes legitimately do not hash to the object content) are *skipped*, not failed. Verification happens after the object is written, because `php://input` is consumed by the streaming copy and cannot be rewound, and buffering to hash first would pull whole uploads into memory. `putObject()` already runs `md5_file()` for the ETag, so this is the same order of cost, bounded further by `s3.verifyPayloadMaxBytes` (256 MiB default; an object over the cap is logged as *unchecked* rather than silently passed).
- **Regression risk:** **Ships log-only.** A mismatch is recorded and the upload still succeeds. An unrecognised config value falls back to `log`, never `off`, so a typo cannot silently disable it. `putObject()`'s new fifth parameter defaults to `''`, so every existing caller keeps its exact prior behaviour.
- **Testing:** Real behavioural tests against real files (`S3Storage` has no framework dependencies): matching body verifies, swapped body does not, no claim costs nothing, the cap skips without dropping the upload, and omitting the new argument reproduces the old ETag/size/mtime contract. All 10 mutants killed.
- **Status:** Fixed log-only — `34f5d26`. **Operator action:** watch for a traffic day, then set `s3.verifyPayloadHash = enforce`.

### H9 · Private media is readable by any logged-in session

- **Severity:** High
- **Location:** `app/Controllers/MediaController.php::checkPrivateOwner()`
- **Description:** The session check proves only that *a* session exists, and storefront customers self-register by phone OTP in seconds. The effective ACL on rider/vendor KYC scans, invoice PDFs and report exports is therefore "anyone with a mobile number". The UUID is unguessable, which caps severity — but UUIDs are rendered into admin and rider views and land in access logs, browser history and the database.
- **Fix:** The owner predicate is **already implemented** and gated behind `media.enforcePrivateOwner`, currently log-only.
- **Regression risk:** **HIGH — flagged.** Legitimate readers span the admin, rider and vendor panels, and `owner_type` values the predicate does not enumerate (`invoice`, `credit_note`, `export`…) would 404 a document somebody legitimately opens. This is precisely why it must not be flipped blind.
- **Testing:** Read the "private media access" notices for a full traffic day, widen the predicate for every real combination observed, then enforce.
- **Status:** **Open — operator decision.** Deliberately not flipped here.

### H10 · Nothing forces HTTPS

- **Severity:** High
- **Location:** `app/Config/App.php:172` (`$forceGlobalSecureRequests = false`) · `app/Config/Filters.php:36` (`forcehttps` aliased, applied to **zero** routes) · `public/.htaccess:24-27` (www→apex redirect targets `http://`)
- **Description:** No HSTS anywhere. The session cookie is `.shiplore.in`-wide (C4). This is also the precondition that makes H8 exploitable.
- **Fix:** Enable `$forceGlobalSecureRequests`, add HSTS, correct the `.htaccess` redirect scheme.
- **Regression risk:** **HIGH — flagged, and NOT applied.** `$proxyIPs` is empty. If TLS terminates at a proxy or CDN, CodeIgniter will see plain HTTP on every request and redirect-loop the entire site. This must not be enabled until the operator confirms the termination point and populates `$proxyIPs`.
- **Testing:** After confirming termination: enable on a staging vhost first, verify no loop, then production.
- **Status:** **Open — blocked on operator confirmation.** See § 7.

---

### M1 · Six transactions reported success without checking it

- **Severity:** Medium
- **Location:** `Admin/BrandController.php` · `Admin/OrderController.php` (×2) · `Admin/VendorTypeMismatchController.php` (×2) · `Models/BusinessTypeRepository.php`
- **Description:** `transComplete()` rolls back automatically when a query in the block fails, so the **data** stayed consistent. The **report** did not. Two were worse than cosmetic: `forceClaim()` wrote a `force_claimed` row to the claim audit log after a rolled-back `UPDATE`, so the audit trail asserted an admin held an order they did not; and both `VendorTypeMismatchController` bulk handlers count `$moved` in PHP as the loop runs, so they reported "N product(s) moved" for work that never committed.
- **Fix:** `transStatus()` checked at all six. `syncCategories()` changed from `void` to `bool` and its single caller now stops before its own settings update rather than reporting one success for two writes.
- **Regression risk:** None on the success path — only the failure path changes, from a false success to an accurate error.
- **Testing:** The test **sweeps every `transStart()` under `app/`** and requires a `transStatus()` in the same function body, so this is a standing guard rather than a fixed list. Note: the finding was catalogued as four sites; the sweep found six.
- **Status:** Fixed — `bf4ae0c`.

### M2 · Failing SQL surfaced to admins in flash messages

- **Severity:** Medium
- **Location:** `app/Models/PayoutRepository.php::createBatch()` · `app/Models/RiderSettlementRepository.php::createBatch()`
- **Description:** Both returned `$e->getMessage()` as `reason`, which `PayoutController:62` and `RiderFinanceController:94` flash directly to the admin. A CodeIgniter `DatabaseException` carries the failing SQL — schema and column names handed to whoever triggered the error.
- **Fix:** Log the real message; return operator-facing text. Hiding it from the user must not mean losing it.
- **Regression risk:** None. Only the message text changes.
- **Two related sites examined and deliberately left alone:** `JwtAuthFilter` passes `AuthException` messages through, but those are app-authored strings the mobile apps may branch on, not raw internals. `ReportExportService`'s reason reaches only `SyncWorker`, which stores it in the job queue's `last_error` — a column **no view renders**, so it is operator diagnostics, and removing it would cost background-job debuggability.
- **Status:** Fixed — `bf4ae0c`.

### M3 · `run_all.sql` loaded 48 of 76 schema files

- **Severity:** Medium (High for anyone rebuilding a database)
- **Location:** `database/sql/run_all.sql`
- **Description:** The file says it "loads the entire schema in dependency-safe order" and skipped 28 files. The gap was already noted in a comment at the bottom of the file and left unfixed.
- **Proof — both versions expanded and loaded into a throwaway database:** old file **231 tables**, corrected file **251**. Absent from the old build: the entire manufacturer/monline set (`mshops`, `product_mshops`, `mfg_inventory`, `mfg_purchase_orders`…), rider documents and finance, API idempotency keys, sub-order claim logs, customer payment instruments — and `idx_ps_product_status`, the covering index the storefront depends on (C5).
- **Fix:** Files 44–55 and 62–73 sourced in numeric order at points where their dependencies exist (56–59 do not exist on disk). The load reported **zero** missing-table, foreign-key or ordering errors. Four files stay out on purpose and are listed in the header: `14_vendor_business_type_assign` (a backfill over existing rows) and the three demo-data files.
- **Regression risk:** None to a running system — this file is only used to build a database.
- **Testing:** A standing guard comparing the sourced list against disk, rejecting duplicates and dangling references, and pinning that `product_shops` is created before it is indexed.
- **Status:** Fixed — `42893ad`.

### M4 · The schema requires MariaDB, undocumented until now

- **Severity:** Medium
- **Location:** `database/sql/54_product_shops_index.sql`, `74_report_indexes.sql`, `75_catalog_indexes.sql`, `43_product_shops.sql`
- **Description:** Discovered while verifying M3. Four files use `ALTER TABLE … ADD INDEX IF NOT EXISTS`, which is **MariaDB-only**. Production is MariaDB 10.11, so they apply there. On MySQL 9.6 all **nine** such statements are syntax errors: the load halts, or with `--force` silently continues and leaves the database short nine indexes — including `idx_products_cat_status_del` and `idx_ps_product_status`, the two the storefront's plan depends on. A MySQL-built copy comes up looking complete and performs nothing like production. Separately, `SOURCE` is a *client* command the MySQL 9.6 client does not accept at all, so the documented `mysql -u root < run_all.sql` does not work on that client.
- **Fix:** Documented prominently in the `run_all.sql` header. **Deliberately not rewritten** — production is the target, and converting nine conditional index adds to portable syntax is more risk than the portability buys.
- **Regression risk:** None (documentation only).
- **Testing:** A test asserts the warning is present *and* that the MariaDB-only syntax still exists — so if someone does make it portable, the test tells them to remove the now-stale warning.
- **Status:** Fixed (documented) — `42893ad`.

### M5 · CSRF protection is opt-in, not global

- **Severity:** Medium
- **Location:** `app/Config/Filters.php:87` — `'csrf'` commented out of the global list
- **Description:** CSRF is applied per route group, so **any new POST route is unprotected by default**. The current routes are covered; the failure mode is the next one somebody adds.
- **Fix:** Move `csrf` into the global filter list with explicit exceptions for `api/v1` (token-authenticated, no cookie) and any webhook endpoints.
- **Regression risk:** **Medium-HIGH — flagged, NOT applied.** Turning it on globally would break any POST route that does not currently render a token — including AJAX callers and the mobile API if the exception list is wrong. This needs a route-by-route inventory first, which is a larger piece of work than this audit's remit.
- **Status:** **Open — recommended, deliberately not applied.**

### M6 · Content-Security-Policy is report-only

- **Severity:** Medium
- **Location:** `app/Config/ContentSecurityPolicy.php:41` — `$reportOnly = true`
- **Description:** Only `frame-ancestors 'self'` is actually enforced. The file's own comment notes there is no report URI configured, so report-only currently collects nothing — it is neither enforcing nor observing.
- **Fix:** Configure a report URI, collect a traffic day, build the allow-list, then enforce. The file already documents this exact sequence.
- **Regression risk:** **HIGH if enforced blind** — the panels use inline styles and several CDN-loaded libraries (Quill, Select2, Flatpickr). Enforcing without an allow-list would break the admin UI.
- **Status:** **Open — recommended, correctly staged already.**

### M7 · `.user.ini` allows one request to hold 5 GB for 50 minutes

- **Severity:** Medium
- **Location:** `.user.ini:11,14` — `max_execution_time = 3000`, `memory_limit = 5000M`
- **Description:** A single slow or malicious request can occupy 5 GB of RAM for 50 minutes. With C5 unfixed this was arguably load-bearing; with the storefront queries back under a second it is pure exposure. A handful of concurrent such requests exhausts the box.
- **Fix:** Reduce to a normal ceiling (e.g. 512 M / 120 s) and raise it *per script* for the genuine long-running jobs (imports, exports, `spark` commands) rather than globally.
- **Regression risk:** **Medium — flagged, NOT applied.** Lowering these blind would break whichever import or export job currently depends on the headroom. Identifying those is a measurement exercise the operator is better placed to run.
- **Status:** **Open — recommended, deliberately not applied.**

### M8 · APCu throttle and cache locks are per-worker

- **Severity:** Medium
- **Location:** `app/Filters/ThrottleFilter.php` · `app/Libraries/FacetCache.php`
- **Description:** APCu memory is per-FPM-worker, not shared. So `ThrottleFilter`'s limits are effectively multiplied by the worker count, and `FacetCache`'s single-flight locks do not coordinate — several workers can stampede the same rebuild.
- **Fix:** Move both to Redis (or the database) for a shared view.
- **Regression risk:** Medium — introduces a new infrastructure dependency. Sizing and failover behaviour need deciding first.
- **Status:** **Open — architectural, out of remit.**

### M9 · N+1 query patterns, notably invoice generation on GET pages

- **Severity:** Medium (performance)
- **Location:** `app/Controllers/.../AccountController` :224, :302, :400 · `StoreController.php:30-35`, `:284-287` · `StoreOrderRepository.php:178-254`
- **Description:** `AccountController` runs `invoiceService->ensureForSubOrder()` **in a loop on three plain GET pages** — each call a full transaction with a `FOR UPDATE`. Rendering an order list therefore opens one write transaction per line. `StoreController` issues 7 catalog queries per home render plus a per-variant stock query; `StoreOrderRepository` issues roughly 8 queries per cart line. Around 40 further sites follow similar shapes.
- **Fix:** Invoice generation belongs in the queue worker, not a page render — `sync:work` already exists for exactly this. The catalog queries should be batched.
- **Regression risk:** **Medium-HIGH.** Moving invoice creation off the render path changes *when* an invoice exists, which is a workflow change — explicitly outside this audit's constraint. It needs the operator's agreement on whether an invoice may lag a page view.
- **Status:** **Open — recommended, deliberately not applied** (workflow change).

### M10 · SigV4 replay window

- **Severity:** Medium
- **Location:** `s3_storage/app/Libraries/SigV4Auth.php` — `MAX_PRESIGN_EXPIRES = 604800`, `DEFAULT_CLOCK_SKEW_SECONDS = 900`
- **Description:** There is no nonce or replay store. A captured signed request stays valid for up to 900 s by header, and up to 7 days presigned.
- **Fix:** A short-TTL nonce store keyed on the signature.
- **Regression risk:** **Medium — flagged, NOT applied.** Rejecting a repeated signature also rejects a client retrying an idempotent PUT after a network timeout, which is normal SDK behaviour. This needs the operator's decision on retry semantics before it can be built safely.
- **Status:** **Open — needs a product decision.**

### M11 · Unvalidated `uploadId` concatenated into filesystem paths

- **Severity:** Medium
- **Location:** `s3_storage/app/Controllers/S3Controller.php` (multipart handlers) · `abortMultipartUpload()`
- **Description:** `uploadId` arrives from the query string and is concatenated into multipart paths without validation, and `abortMultipartUpload()` deletes recursively without first loading the manifest.
- **Fix:** Validate `uploadId` against the same hex/UUID shape it is generated with, and have abort operate only on paths named by the manifest.
- **Regression risk:** Low. Worth doing; not done here only because P0/P1 took priority.
- **Status:** **Open.**

### M12 · Unbounded queries

- **Severity:** Medium (performance / DoS)
- **Location:** `api/v1/customer/categories` (unauthenticated, no limit, no throttle) · `ReportRepository` / `DashboardRepository` (5–7 unbounded `COUNT`s per page)
- **Fix:** Paginate and throttle the public endpoint; cache the dashboard counts.
- **Regression risk:** Low-Medium — adding pagination to a public endpoint changes its response shape, which shipped mobile clients may not handle. Needs a versioned rollout.
- **Status:** **Open.**

### L1 · Zero CI4 validation and zero CI4 Models

- **Severity:** Low (architectural)
- **Description:** Across 135 controllers and 112 repositories, none extends `CodeIgniter\Model` and none uses the framework's validation service. There is no `$allowedFields` anywhere, so mass-assignment protection is hand-rolled per repository. This is consistent and evidently deliberate, but it means the framework's safety nets are all switched off — every new repository has to remember them independently.
- **Status:** **Open — noted, not a defect.**

### L2 · Duplicated domain logic

- **Severity:** Low
- **Description:** The status→badge map is repeated in 4 views. "Open sub-order statuses" is defined in 3 places and never via `StatusMachine`. `CartService.php:214` hard-codes `interState = false`, so the cart preview always shows CGST+SGST even when the order books IGST — a visible inconsistency between preview and invoice.
- **Status:** **Open.** The `CartService` item is a genuine user-visible bug worth fixing next.

---

## 3. Verified clean

Stated explicitly so this ground is not re-covered:

- **No SQL injection.** All **31** raw `->query()` sites under `app/` are parameterised. This was re-checked here rather than carried over: a scan flagged 8 sites as possibly interpolating a variable into the SQL string, and reading all 8 showed every one uses `?` placeholders with a bindings array — the scan was matching the bindings array itself. Every `like()` is escaped; dynamic `ORDER BY` is allow-listed. The raw SQL this audit added (the two `FOR UPDATE` reads) is parameterised.
- **No `eval` / `new Function` in JavaScript.**
- **No cache key built from unvalidated input.**
- **Upload handling:** filenames are UUIDs from `random_bytes`, MIME is server-detected, `DocumentStorage::safeKey()` rejects traversal.
- **Credentials are never logged or echoed** by either application.
- **Money handling** uses the integer-backed `Money` class; no float arithmetic on amounts.

---

## 4. Area scores

Scored /10 **after** the fixes in this audit. "Before" reflects the state at the start.

| # | Area | Before | After | Note |
|---|---|---|---|---|
| 1 | Authentication | 4 | 8 | H1/H2 closed; principal enforcement still staged (C4) |
| 2 | Authorization | 4 | 7 | H3/H4 closed; `perm:`/`tenantScope` still on zero routes |
| 3 | SQL injection | 9 | 9 | Verified clean |
| 4 | XSS | 4 | 8 | H5 closed both copies; CSP still report-only (M6) |
| 5 | File upload security | 5 | 7 | H8 log-only; M11 open |
| 6 | CSRF | 5 | 5 | Covered per-route; not global (M5) |
| 7 | Session management | 5 | 5 | Cookie is zone-wide (C4) |
| 8 | Secrets management | 2 | 6 | C6 fixed; C2 rotation outstanding |
| 9 | Performance | 2 | 7 | C5 fixed and measured; M9 N+1s open |
| 10 | Database | 4 | 8 | M3/M4 fixed; races closed |
| 11 | CI4 best practice | 4 | 4 | No Models, no validation service (L1) |
| 12 | API security | 4 | 8 | Three `api/v1` gaps closed |
| 13 | Logging & audit | 6 | 8 | M1 stopped a false audit entry |
| 14 | Error handling | 4 | 7 | M2 closed; M1 sweep in place |
| 15 | Caching | 5 | 5 | APCu is per-worker (M8) |
| 16 | HTTP headers | 3 | 3 | No HTTPS enforcement, no HSTS (H10) |
| 17 | Architecture | 6 | 6 | Panel separation is path-based only (C4) |
| 18 | Code quality | 6 | 7 | Duplication remains (L2) |
| 19 | Dependency security | 7 | 7 | CI4 vendored in-tree; no automated CVE watch |
| 20 | Infrastructure | 3 | 5 | C1/C3 closed; `.user.ini` limits open (M7) |

---

## 5. Priority lists

### Top critical issues

| # | Issue | Status |
|---|---|---|
| 1 | Unauthenticated S3 upload endpoint (C1) | **Fixed** |
| 2 | Live AWS keys exposed (C2) | **Operator — rotate** |
| 3 | Anonymous reads of runtime dirs (C3) | **Fixed** (+ config) |
| 4 | Panel separation not enforced (C4) | **Operator — staged** |
| 5 | Storefront unusable at 50–85 s/page (C5) | **Fixed** |
| 6 | Root DB password in 14 web-root scripts (C6) | **Fixed** |
| 7 | Admin credentials mint a mobile JWT (H1) | **Fixed** |
| 8 | JWT mint that did not fail closed (H2) | **Fixed** |
| 9 | Cross-tenant inventory write (H3) | **Fixed** |
| 10 | POS terminal hijack (H4) | **Fixed** |
| 11 | Stored XSS ×3, vendor→admin (H5) | **Fixed** |
| 12 | Udhaar lost update (H6) | **Fixed** |
| 13 | Coupon per-user bypass (H7) | **Fixed** |
| 14 | SigV4 body never verified (H8) | **Fixed** log-only |
| 15 | Private media readable by any session (H9) | **Operator — staged** |
| 16 | No HTTPS enforcement (H10) | **Blocked — needs TLS confirmation** |
| 17 | Schema build missing 28 files (M3) | **Fixed** |
| 18 | Transactions reporting false success (M1) | **Fixed** |
| 19 | SQL in admin flash messages (M2) | **Fixed** |
| 20 | CSRF not global (M5) | **Open — needs route inventory** |

### Top performance issues

1. **Missing covering indexes on `product_shops`** — 15.3 s → 0.19 s. *Applied.*
2. **Index-hint pinning the wrong plan** (C5) — the 22 s query. *Fixed.*
3. **Invoice generation in a loop on 3 GET pages** (M9) — a write transaction per order line. *Open.*
4. `LIKE '%q%'` across 1 M products while an unused `ft_products` FULLTEXT index exists.
5. `ORDER BY pv.base_price` unindexed.
6. 7 catalog queries per storefront home render.
7. ~8 queries per cart line.
8. Unbounded `COUNT`s on report and dashboard pages.
9. Unauthenticated, unpaginated, unthrottled category tree.
10. APCu cache locks not shared across workers (M8).
11. `.user.ini` permitting 5 GB / 50 min per request (M7).
12. Nine indexes silently absent from any MySQL-built database (M4).

### Top security issues

Items 1–16 of the critical table above, plus: SigV4 replay window (M10), unvalidated `uploadId` (M11), report-only CSP (M6), and the absence of `perm:`/`tenantScope` on any route despite both filters being aliased.

---

## 6. Effort-sorted remaining work

### Quick wins (< 30 minutes)

| Item | Action |
|---|---|
| C3 completion | Set `s3.publicReadBucket` to the media bucket in `s3_storage/.env` |
| H8 enforcement | After a traffic day: `s3.verifyPayloadHash = enforce` |
| M11 | Validate `uploadId` against its generated shape |
| L2 | Fix `CartService.php:214` `interState` so the cart preview matches the invoice |
| M6 (step 1) | Configure a CSP report URI so report-only actually collects |

### Medium (1–4 hours)

| Item | Action |
|---|---|
| C4 | Review a traffic day of principal-type notices, then enforce |
| H9 | Review a traffic day of private-media notices, widen the predicate, then enforce |
| M5 | Route-by-route CSRF inventory, then move `csrf` global with explicit exceptions |
| M7 | Measure which jobs need the headroom; lower the global limit and raise per script |
| M12 | Paginate and throttle the public category endpoint (versioned) |
| Perf 4/5 | Use the existing `ft_products` FULLTEXT index; index the price sort |

### Large (> 1 day)

| Item | Action |
|---|---|
| H10 | Confirm TLS termination, populate `$proxyIPs`, enable HTTPS + HSTS, fix the `.htaccess` scheme |
| C4 (properly) | Real subdomain isolation: per-panel cookie domains and host-based routing |
| M9 | Move invoice generation to `sync:work`; batch the catalog queries |
| M8 | Move throttle counters and cache locks to Redis |
| M6 | Build the CSP allow-list from collected reports, then enforce |
| M10 | Design and build SigV4 replay protection around agreed retry semantics |

---

## 7. Operator actions — cannot be done from here

**1. Rotate the AWS key pair (C2).** Ordered so media never breaks:

1. Create a **new** key pair at the provider. Do not touch the old one yet.
2. Write the new key and secret into `s3_storage/.env` (`s3.accessKey`, `s3.secretKey`).
3. Run the admin S3 self-test. It must pass.
4. Upload one file and read it back through the application.
5. **Only now** revoke the old key at the provider.
6. Confirm media still loads.

If step 3 or 4 fails, put the old values back — the old key is still live until step 5.

**2. Supply these production `.env` values** — they decide the severity of C4, H2 and H9, and they have been requested but not yet provided:

- `auth.enforcePrincipalType`
- `media.enforcePrivateOwner`
- `jwt.secret` (masked — confirm only that it is set and is not the committed placeholder)
- `CI_ENVIRONMENT`

**3. Confirm where TLS terminates** (H10). If a proxy or CDN terminates it, `$proxyIPs` must be populated *before* HTTPS enforcement is enabled, or the site will redirect-loop.

**4. Deploy.** Every fix in this audit is committed **locally only**.

**5. Rotate `jwt.secret`** — `.env` is tracked in git and the secret is in the initial commit.

---

## 8. Recommendations that carry breakage risk

Named without softening, as requested.

| Recommendation | Risk | Applied? |
|---|---|---|
| **H10 — enable HTTPS enforcement** | **Highest risk here.** `$proxyIPs` is empty. If TLS terminates at a proxy, CI4 sees plain HTTP on every request and **redirect-loops the whole site**. | **No** — blocked on operator confirmation |
| **C4 — `auth.enforcePrincipalType = true`** | Any staff account with an unexpected `principal_type` is **locked out** immediately. | **No** — staged log-only |
| **H9 — `media.enforcePrivateOwner = true`** | `owner_type` values the predicate does not enumerate (`invoice`, `credit_note`, `export`) will **404 documents people legitimately open**. | **No** — staged log-only |
| **H8 — `s3.verifyPayloadHash = enforce`** | A client sending a body that legitimately does not hash to the stored bytes would have **uploads rejected**. | **No** — ships log-only |
| **M5 — global CSRF** | Any POST route not currently rendering a token **breaks**, including AJAX callers and possibly the mobile API. | **No** |
| **M6 — enforce CSP** | Panels use inline styles and CDN libraries; enforcing without an allow-list **breaks the admin UI**. | **No** |
| **M7 — lower `.user.ini` limits** | Whichever import/export currently relies on the headroom **starts failing**. | **No** |
| **M9 — invoices to the queue** | Changes *when* an invoice exists. That is a **workflow change**, outside the stated constraint. | **No** |
| **M10 — SigV4 replay protection** | Would reject a client **retrying an idempotent PUT** after a timeout. | **No** |
| **M12 — paginate the public category endpoint** | Changes a response shape **shipped mobile clients** consume. | **No** |
| C3 — `s3.publicReadBucket` | Naming the wrong bucket **403s live public media**. Defaults to empty, so current behaviour is preserved until set. | Partially — code shipped, config is the operator's |
| H2 — fail-closed JWT secret | If `jwt.secret` is **not** set in production, this endpoint now **fails** instead of issuing forgeable tokens. Correct, but it is a behaviour change. | **Yes** |
| H1 — mobile principal allow-list | A legitimate mobile user with an unexpected `principal_type` **cannot log in**. | **Yes** |
| C5 — conditional index hint | Changes a query **plan**, not results. Same rows either way. | **Yes** |
| M1 — `syncCategories()` `void` → `bool` | Signature change; single caller verified first. | **Yes** |
| H8 — `putObject()` fifth parameter | Additive with a default; all existing callers unchanged, proven by test. | **Yes** |

Everything else applied is behaviour-preserving for correctly-scoped callers: the fixes
change only what happens on a path that was already wrong.

---

## 9. Verification

- **Full suite: 1,118 tests, 26 errors, 33 failures** — identical to the pre-audit baseline of 1,069/26/33. The errors and failures are pre-existing environment gaps (unmigrated test database, no `jwt.secret` in the test env); neither count increased. 49 tests were added.
- **Every security fix has a test that fails when the fix is reverted.** This was demonstrated, not assumed: **49 mutants** were applied — each one reverting a specific fix — and all 49 killed the matching test. Two vacuous assertions were caught this way and rewritten: one searched backwards for a rollback and found the *neighbouring* guard's, passing even with its own removed; the other was satisfied by an explanatory comment rather than by code.
- **Assertions run against comment-stripped source** wherever a fix carries a comment naming the construct it replaced, so prose cannot satisfy a check.
- **Both money races were reproduced against a real MySQL** at the server's actual `REPEATABLE-READ` isolation, and both fixes measured against the same interleaving.
- **The schema fix was verified by building the database twice** — 231 tables before, 251 after.
- Throwaway databases were dropped; the existing local `test` database (272 tables) was never written to.

### Still to verify after deploy

- Re-run the production `EXPLAIN` and timings for queries E and F; F should be well under 1 s and must return the same rows.
- `s3.shiplore.in/s3test.php` must return 404.
- Smoke: staff login · one admin page · one vendor page · a storefront browse with a location set · one `api/v1` endpoint · one POS sale.

---

## 10. Commits

| Commit | Content |
|---|---|
| `c29f739` | C1, C3 — delete the S3 test endpoint; stop runtime dirs being buckets |
| `a8adde7` | C5 — stop pinning the products index when a location scope is active |
| `7d63f9d` | C6 — maintenance-script DB credentials from the environment |
| `8cfc676` | H1–H4 — four `api/v1` authorization gaps |
| `1866c8d` | H5 — three stored-XSS sinks |
| `7e2b8a9` | H5 — mirror the XSS fix to the second copy of the asset |
| `97f61ea` | H6, H7 — udhaar lost update, coupon per-user limit |
| `34f5d26` | H8 — verify uploaded bodies against the signed SHA-256, log-only |
| `bf4ae0c` | M1, M2 — `transStatus()` on six transactions; stop leaking SQL |
| `42893ad` | M3, M4 — load the whole schema; document the MariaDB requirement |

44 files changed, 2,052 insertions, 667 deletions.

---

## 11. Overall scores

| | Score | Basis |
|---|---|---|
| **Security** | **7 / 10** | Was 3. Every exploitable-today finding is closed or staged. Held back by: HTTPS unenforced (H10), panel separation still path-based (C4), CSRF not global (M5), CSP not enforced (M6). Three of those four are blocked on operator input, not on work. |
| **Performance** | **7 / 10** | Was 2 — the site was effectively unusable. The measured 22 s query is fixed and the indexes are live. Held back by the N+1s (M9), most of all invoice generation inside a page render. |
| **Maintainability** | **6 / 10** | Consistent conventions and a real test suite (1,118 tests), now with standing guards against transaction, schema and XSS regressions. Held back by the framework's safety nets being switched off wholesale (L1) and by duplicated domain logic (L2). |
| **Production readiness** | **6 / 10** | Materially better than at the start of this audit, but **not yet deployed** — every fix here is local. Readiness is gated on the five operator actions in § 7, and specifically on rotating the AWS keys and confirming TLS termination. |

**The single most important next step is not a code change.** It is § 7 item 1 — rotate the
AWS credentials. They are live, and they were reachable from an unauthenticated endpoint
until this audit deleted it. Deleting the file closes the door; it does not undo any access
that already happened.
