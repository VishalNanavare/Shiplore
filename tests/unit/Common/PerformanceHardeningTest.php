<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Caching, memoisation, batching and index findings (audit H6, H9, M20-M26, M30, I1).
 *
 * Every fix here is either (a) a per-request memo — safe by construction, since the
 * underlying data cannot change mid-request — or (b) a query-count reduction that
 * must produce byte-identical results, or (c) an index-only migration. None of these
 * touch a security boundary, so most assertions are structural; H6 and M20 get
 * mutation-tested because an attacker can reach them directly (an arbitrary
 * ?category= value; an unbounded export).
 */
final class PerformanceHardeningTest extends CIUnitTestCase
{
    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

    private function readSql(string $rel): string
    {
        return (string) file_get_contents(ROOTPATH . 'database/sql/' . $rel);
    }

    /** Same brace-matching extractor used elsewhere this session. */
    private function methodBody(string $src, string $method): string
    {
        if (! preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $brace = strpos($src, '{', (int) $m[0][1]);
        if ($brace === false) {
            return '';
        }
        $depth = 0;
        for ($i = $brace, $len = strlen($src); $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }

        return '';
    }

    // ------------------------------------------------------------------ H6

    /**
     * The cache key that gates whether a request computes inline is built directly
     * from $opts['category'] — client-controlled on the public browse endpoints.
     * Every method that can reach FacetCache with a category must canonicalise first.
     */
    public function testCategoryCanonicalisationGuardsEveryFacetCacheEntryPoint(): void
    {
        $src = $this->read('Models/StoreCatalogRepository.php');
        $this->assertStringContainsString('function _canonicalOpts(', $src);
        $this->assertStringContainsString('catSlugMemo', $src, 'the slug->exists lookup must be memoised per request');

        foreach (['countProducts', 'brandFacets', 'typeFacets', 'priceBounds', 'categoryFacets', 'categoryTreeWithCounts'] as $m) {
            $body = $this->methodBody($src, $m);
            $this->assertStringContainsString(
                '$this->_canonicalOpts(',
                $body,
                $m . '() must canonicalise the category slug before it can become a FacetCache key',
            );
        }
    }

    /** An unresolvable slug must collapse to '' — never pass through to the cache key unchanged. */
    public function testCanonicalOptsCollapsesUnresolvableSlug(): void
    {
        $body = $this->methodBody($this->read('Models/StoreCatalogRepository.php'), '_canonicalOpts');
        $this->assertStringContainsString("opts['category'] = ''", $body);
        $this->assertStringContainsString('countAllResults() > 0', $body, 'must actually check the slug resolves to a live category');
    }

    /** The cold path (a live request) must not stampede — it had no lock; the worker path always did. */
    public function testColdFacetRefreshIsSingleFlightLocked(): void
    {
        $body = $this->methodBody($this->read('Libraries/Store/FacetCache.php'), 'refreshFor');
        $this->assertStringContainsString('facetlock_', $body);
        $this->assertStringContainsString('cache()->save(', $body);
        $this->assertStringContainsString('finally', $body, 'the lock must release even if the compute throws');
    }

    // ------------------------------------------------------------------ H9

    public function testBrandsEndpointUsesTheCachedWrapper(): void
    {
        $body = $this->methodBody($this->read('Controllers/Api/V1/CustomerApiController.php'), 'brands');
        $this->assertStringContainsString(
            '->brandFacets(',
            $body,
            'brands() must go through the FacetCache-backed wrapper, not computeBrandFacets() directly',
        );
        $this->assertStringNotContainsString('->computeBrandFacets(', $body, 'the raw, explicitly-UNCACHED method must not be called from this public route');
    }

    // ------------------------------------------------------------------ M20

    public function testAdminProductListCapsUnboundedQueries(): void
    {
        $src = $this->read('Models/AdminProductRepository.php');
        $this->assertStringContainsString('public const MAX_ROWS = 5000;', $src);

        $body = $this->methodBody($src, 'list');
        $this->assertMatchesRegularExpression(
            '/\$limit > 0 \? min\(\$limit, self::MAX_ROWS\) : self::MAX_ROWS/',
            $body,
            'limit<=0 ("all"/export) must be capped at MAX_ROWS, not left unbounded',
        );
    }

    public function testProductExportShowsATruncationMarkerAtTheCap(): void
    {
        $body = $this->methodBody($this->read('Controllers/Admin/ProductController.php'), 'export');
        $this->assertStringContainsString('AdminProductRepository::MAX_ROWS', $body);
        $this->assertStringContainsString('truncated', $body, 'a capped export must say so, not look like a complete file');
        $this->assertStringContainsString('unset($rows)', $body);
    }

    // ------------------------------------------------------------------ M21 / I1 (index migrations)

    public function testReportIndexMigrationExists(): void
    {
        $sql = $this->readSql('74_report_indexes.sql');
        $this->assertStringContainsString('idx_suborders_del_created', $sql);
        $this->assertStringContainsString('idx_orders_del_created', $sql);
        $this->assertStringContainsString('IF NOT EXISTS', $sql, 'must be idempotent, matching 54_product_shops_index.sql');
    }

    public function testCatalogIndexMigrationPromotesThePhpScriptIndexes(): void
    {
        $sql = $this->readSql('75_catalog_indexes.sql');
        foreach ([
            'idx_products_cat_status_del', 'idx_products_status_created',
            'idx_variants_product_default', 'idx_inventory_shop_avail',
            'idx_products_del_created', 'idx_mshops_status_del',
        ] as $idx) {
            $this->assertStringContainsString($idx, $sql, $idx . ' must be in the numbered migration series, not only in perf2_indexes.php');
        }
    }

    public function testBothNewMigrationsAreSourcedByRunAll(): void
    {
        $sql = $this->readSql('run_all.sql');
        $this->assertStringContainsString('SOURCE 74_report_indexes.sql;', $sql);
        $this->assertStringContainsString('SOURCE 75_catalog_indexes.sql;', $sql);
    }

    // ------------------------------------------------------------------ M22

    public function testPurchaseRulesForVariantIsMemoisedPerRequest(): void
    {
        $src = $this->read('Models/StoreCatalogRepository.php');
        $this->assertStringContainsString('purchaseRulesMemo', $src);
        $body = $this->methodBody($src, 'purchaseRulesForVariant');
        $this->assertStringContainsString('array_key_exists($variantId, $this->purchaseRulesMemo)', $body);
    }

    public function testCartLineCountIsCappedOnBothEntryPoints(): void
    {
        $src = $this->read('Controllers/Api/V1/CustomerApiController.php');
        $this->assertStringContainsString('MAX_CART_LINES = 100', $src);

        foreach (['placeOrder', 'validateCart'] as $m) {
            $body = $this->methodBody($src, $m);
            $this->assertMatchesRegularExpression(
                '/count\(\(array\) \(\$in\[\'items\'\] \?\? \[\]\)\) > self::MAX_CART_LINES/',
                $body,
                $m . '() must reject an oversized item list explicitly, not silently truncate it',
            );
        }
    }

    // ------------------------------------------------------------------ M23

    public function testOrderTrackingBatchesItemsAndInvoices(): void
    {
        $body = $this->methodBody($this->read('Models/StoreOrderRepository.php'), 'track');
        $this->assertStringContainsString("whereIn('sub_order_id', \$subIds)", $body);
        // Item rows must still carry no sub_order_id key — the payload shape is
        // load-bearing for the shipped mobile app.
        $this->assertStringContainsString("unset(\$it['sub_order_id'])", $body);
    }

    // ------------------------------------------------------------------ M24

    public function testMonlineBrowseClampsPageBeforeItBecomesAnOffset(): void
    {
        $body = $this->methodBody($this->read('Controllers/Monline/CatalogController.php'), 'browse');
        $this->assertMatchesRegularExpression('/\$pages\s*=\s*max\(1,\s*\(int\)\s*ceil\(\$total\s*\/\s*\$limit\)\)/', $body);
        $this->assertMatchesRegularExpression('/\$page\s*=\s*min\(\$page,\s*\$pages\)/', $body);

        // The clamp must happen BEFORE the offset is computed, or ?page=1000000
        // still becomes the huge OFFSET this fix exists to prevent.
        $clamp  = strpos($body, 'min($page, $pages)');
        $offset = strpos($body, "\$opts['offset']");
        $this->assertNotFalse($clamp);
        $this->assertNotFalse($offset);
        $this->assertLessThan($offset, $clamp);
    }

    public function testMonlineManufacturersAndCategoriesAreCached(): void
    {
        $src = $this->read('Models/MonlineCatalogRepository.php');
        foreach (['manufacturers' => 'monline_manufacturers', 'categories' => 'monline_categories'] as $m => $key) {
            $body = $this->methodBody($src, $m);
            $this->assertStringContainsString("'{$key}'", $body);
            $this->assertStringContainsString('cache(', $body);
            $this->assertStringContainsString('cache()->save(', $body);
        }
    }

    // ------------------------------------------------------------------ M25

    public function testNearbyShopIdsIsMemoisedPerRequest(): void
    {
        $src = $this->read('Models/StoreShopRepository.php');
        $this->assertStringContainsString('nearbyIdMemo', $src);
        $body = $this->methodBody($src, 'nearbyShopIds');
        $this->assertStringContainsString('sprintf(', $body, 'the memo key must be rounded/bounded, not the raw float');
        $this->assertStringContainsString('??=', $body);
    }

    public function testNearbySqlHasASafetyValveLimit(): void
    {
        $body = $this->methodBody($this->read('Models/StoreShopRepository.php'), 'nearby');
        $this->assertStringContainsString('max($limit * 5, 500)', $body);
    }

    // ------------------------------------------------------------------ M26

    public function testInventorySnapshotBatchesInsteadOfPerCellQueries(): void
    {
        $body = $this->methodBody($this->read('Libraries/Inventory/InventoryService.php'), 'snapshotRows');
        $this->assertStringContainsString("whereIn('variant_id', \$variantIds)->whereIn('shop_id', \$shopIds)", $body);
        // Must NOT call the old per-cell methods inside the shop loop anymore.
        $this->assertStringNotContainsString('$this->levels($vid, $sid)', $body);
        $this->assertStringNotContainsString('$this->valuation($vid, $sid', $body);
        // Empty-shops shape must be preserved: still one row per variant, not [].
        $this->assertStringContainsString('if ($variants === [])', $body);
        $this->assertStringNotContainsString('if ($shops === [])', $body, 'a vendor with zero shops must still get one zeroed row per variant, not an empty result');
    }

    // ------------------------------------------------------------------ M30

    public function testSettingsGetIsMemoisedIncludingMisses(): void
    {
        $src = $this->read('Models/SettingsRepository.php');
        $this->assertStringContainsString('private array $memo = [];', $src);

        $get = $this->methodBody($src, 'get');
        $this->assertStringContainsString('array_key_exists($memoKey, $this->memo)', $get);

        // The catch branch must NOT memoise — a transient DB fault must not pin a
        // default for the rest of the request. Checking that "catch" appears before
        // the memo-write line is not enough: a mutant that turns the catch body into
        // `$row = null;` (falling through to the normal memo-write line, which then
        // evaluates `$row !== null ? … : null` and memoises null) still returns
        // $default for THIS call — same visible output — while silently pinning the
        // miss for the rest of the request. The catch block's OWN body must contain
        // an actual `return`, not just precede the write textually.
        $this->assertMatchesRegularExpression(
            '/catch \(\\\\Throwable\) \{[^}]*return \$default;[^}]*\}/',
            $get,
            'the catch block must return $default directly, not fall through to the memo-write line',
        );

        $set = $this->methodBody($src, 'set');
        $this->assertStringContainsString('unset($this->memo[', $set, 'set() must invalidate the memo for the key it just wrote');
    }
}
