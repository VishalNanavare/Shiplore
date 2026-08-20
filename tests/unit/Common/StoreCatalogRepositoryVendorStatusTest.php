<?php

declare(strict_types=1);

use App\Models\StoreCatalogRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * StoreCatalogRepository, wired to VendorStatusGate — Phase 4 of the vendor status/
 * lifecycle build, the highest-volume surface in it.
 *
 * DELIBERATE DEVIATION from the log-then-count mechanics everywhere else in this
 * phase (StoreShopRepository, both apps): no per-request "would exclude" logging
 * here. baseQuery()/facetBase() back the main product browse/search/facet-count
 * queries — up to ~960K products, paginated with LIMIT/OFFSET. Computing an accurate
 * "would exclude" count without a second query (which would double the cost of the
 * single most performance-sensitive path in the app — see this file's own comments
 * on covering indexes and the product_shops-first optimizer plan) isn't achievable
 * here the way it is for a bounded ~30-500 row nearby() call. So while log-only, the
 * WHERE clause is simply not added at all, and no line is logged. The verification
 * step before flipping vendor.enforceStatusGate for THIS surface is a direct one-time
 * SQL audit (documented at the operator hand-off), not log-grepping.
 *
 * Tested via the raw/uncached methods (computeCount, computeCategoryFacets,
 * computeTree) rather than their cached public wrappers (countProducts,
 * categoryFacets) — those pull in FacetCache, an unrelated concern.
 */
final class StoreCatalogRepositoryVendorStatusTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('vendor.enforceStatusGate');
        $this->ensureCategoriesTable();
        $this->ensureVendorsTable();
        $this->ensureProductsTable();
        $this->ensureProductVariantsTable();
        $this->ensureProductShopsTable();

        $db = Database::connect();
        $db->table('categories')->insert(['id' => 5001, 'name' => 'Snacks', 'slug' => 'snacks', 'path' => 'snacks', 'level' => 0]);
        $db->table('vendors')->insert(['id' => 7001, 'legal_name' => 'x', 'display_name' => 'Active Vendor', 'status' => 'active']);
        $db->table('vendors')->insert(['id' => 7002, 'legal_name' => 'x', 'display_name' => 'Suspended Vendor', 'status' => 'suspended']);
        $db->table('products')->insert(['id' => 6001, 'vendor_id' => 7001, 'category_id' => 5001, 'title' => 'Chips (active vendor)', 'status' => 'published']);
        $db->table('products')->insert(['id' => 6002, 'vendor_id' => 7002, 'category_id' => 5001, 'title' => 'Cookies (suspended vendor)', 'status' => 'published']);
    }

    protected function tearDown(): void
    {
        $db = Database::connect();
        $db->table('products')->whereIn('id', [6001, 6002])->delete();
        $db->table('vendors')->whereIn('id', [7001, 7002])->delete();
        $db->table('categories')->where('id', 5001)->delete();
        putenv('vendor.enforceStatusGate');
        Services::reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ computeCount / baseQuery

    public function testByDefaultBothVendorsProductsAreCounted(): void
    {
        $count = (new StoreCatalogRepository())->computeCount([]);

        $this->assertSame(2, $count, 'log-only must not change behaviour yet');
    }

    public function testWithTheFlagSetOnlyTheActiveVendorsProductIsCounted(): void
    {
        putenv('vendor.enforceStatusGate=true');

        $this->assertSame(1, (new StoreCatalogRepository())->computeCount([]));
    }

    // ------------------------------------------------------------------ computeCategoryFacets / facetBase

    public function testByDefaultTheCategoryFacetCountsBothProducts(): void
    {
        $rows = (new StoreCatalogRepository())->computeCategoryFacets([]);

        $this->assertSame(2, $rows[0]['cnt'] ?? null);
    }

    public function testWithTheFlagSetTheCategoryFacetCountsOnlyTheActiveVendorsProduct(): void
    {
        putenv('vendor.enforceStatusGate=true');

        $rows = (new StoreCatalogRepository())->computeCategoryFacets([]);

        $this->assertSame(1, $rows[0]['cnt'] ?? null);
    }

    // ------------------------------------------------------------------ computeTree / publishedTitle
    //
    // Both stay JOIN-FREE for covering-index performance (see NOT_MANUFACTURER_EXISTS's
    // own comment) and use a raw "FROM vendors" SQL fragment — deliberately UNPREFIXED,
    // because database/sql/*.sql ships to production with no table prefix at all
    // (app/Config/Database.php:34) and only the 'tests' connection group prefixes with
    // "db_" (:172, "Needed to ensure we're working correctly with prefixes live"). A
    // raw string fragment is not table-name-aware, so the query builder cannot prefix
    // it — the exact same reason NOT_MANUFACTURER_EXISTS itself has never been
    // exercised against a live query in this suite. Source assertions, matching how
    // this codebase already handles that class of untestability elsewhere (e.g.
    // ManufacturerPermissionScopeTest reads migration SQL as text for the same reason).

    private function repositorySource(): string
    {
        return (string) file_get_contents(APPPATH . 'Models/StoreCatalogRepository.php');
    }

    /**
     * The body of a named method, bounded by the next method declaration — NOT just
     * "everything after this point in the file". A regex without that bound is
     * satisfied by a DIFFERENT method's check anywhere later in the class; a mutation
     * run demonstrated exactly that: deleting computeTree()'s own gate call still
     * matched, because publishedTitle()'s gate call (declared later in the file)
     * satisfied the same unbounded pattern.
     */
    private function methodBody(string $name): string
    {
        $src = $this->repositorySource();
        $this->assertMatchesRegularExpression('/function\s+' . preg_quote($name, '/') . '\s*\(/', $src, "method {$name}() not found — has it been renamed?");

        $start = strpos($src, 'function ' . $name . '(');
        $rest  = substr($src, $start);
        // Next method boundary: "public"/"private"/"protected" ... "function", skipping
        // the opening one itself.
        preg_match('/\b(?:public|private|protected)(?:\s+static)?\s+function\s+\w+\s*\(/', $rest, $m, PREG_OFFSET_CAPTURE, 1);

        return isset($m[0]) ? substr($rest, 0, $m[0][1]) : $rest;
    }

    /**
     * computeTree()'s global/unfiltered branch is the one gap the shop-scoped path
     * doesn't cover transitively: it stays join-free (USE INDEX covering-index scan)
     * specifically so it never touches `vendors` — the shop-scoped branch inherits
     * correctness from nearbyShopIds()'s already-filtered shop_ids instead (same
     * upstream source applyFilters()'s product_shops EXISTS relies on).
     */
    public function testComputeTreeGatesTheGlobalBranchOnVendorStatus(): void
    {
        $body = $this->methodBody('computeTree');

        $this->assertStringContainsString('vendorStatusGate', $body);
        $this->assertStringContainsString('isEnforcing', $body);
        $this->assertStringContainsString('VENDOR_ACTIVE_EXISTS', $body, 'and it must apply the vendor-active EXISTS clause specifically, not just consult the gate');
    }

    public function testPublishedTitleGatesOnVendorStatus(): void
    {
        $body = $this->methodBody('publishedTitle');

        $this->assertStringContainsString('vendorStatusGate', $body);
        $this->assertStringContainsString('isEnforcing', $body);
        $this->assertStringContainsString('VENDOR_ACTIVE_EXISTS', $body);
    }

    /**
     * The constant's own SQL, not just that it gets referenced. The EXISTS/NOT EXISTS
     * distinction is exactly the kind of one-word inversion neither test above can
     * catch on its own — a mutation run confirmed it: flipping this to NOT EXISTS
     * (silently inverting the whole check to hide ACTIVE vendors instead of inactive
     * ones) passed both method-body tests above unchanged.
     */
    public function testVendorActiveExistsIsNotInverted(): void
    {
        $this->assertMatchesRegularExpression(
            "/VENDOR_ACTIVE_EXISTS\s*=\s*\"EXISTS \(SELECT 1 FROM vendors \w+ WHERE \w+\.id = p\.vendor_id AND \w+\.status = 'active'\)\";/",
            $this->repositorySource(),
            'must be EXISTS, not NOT EXISTS — a plain string-contains check would pass either way since "EXISTS" is a substring of "NOT EXISTS"',
        );
    }
}
