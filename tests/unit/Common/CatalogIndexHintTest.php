<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Audit C5 — the storefront product query took ~22s on production and a page
 * issues 7-8 of them.
 *
 * StoreCatalogRepository pins `idx_products_cat_status_del` on the products table
 * whenever a category filter is active. That is right for an UNSCOPED browse, but
 * pinning an index on `products` forces the optimizer to start there and walk the
 * catalogue testing the product_shops EXISTS per row. Once a location scope is
 * present the correct plan is the opposite: start from product_shops and eq_ref
 * into products, bounded by what those shops actually stock.
 *
 * Production scale that makes this decisive: 1,000,531 published products,
 * 5,393,036 product_shops rows, and a 200-shop scope covering 95,650 distinct
 * products (~9.5% of the catalogue). The operator's own EXPLAIN confirmed MySQL
 * picks materialize -> eq_ref when the hint is absent.
 *
 * computeTree() already made this carve-out, but only for a SINGLE shop; the
 * multi-shop case — the common one — kept the pin.
 */
final class CatalogIndexHintTest extends CIUnitTestCase
{
    private function repo(): object
    {
        return new \App\Models\StoreCatalogRepository();
    }

    /** Invoke the private hint chooser. */
    private function hintFor(array $opts): string
    {
        $m = new ReflectionMethod(\App\Models\StoreCatalogRepository::class, 'categoryIndexHint');
        $m->setAccessible(true);

        return (string) $m->invoke($this->repo(), $opts);
    }

    /** No category filter => no hint, scoped or not. */
    public function testNoHintWithoutACategoryFilter(): void
    {
        $this->assertSame('', $this->hintFor([]));
        $this->assertSame('', $this->hintFor(['shop_ids' => [1, 2, 3]]));
    }

    /** Unscoped category browse keeps the pin — this is what it was added for. */
    public function testCategoryBrowseWithoutShopScopeKeepsTheHint(): void
    {
        $this->assertSame(
            'FORCE INDEX (idx_products_cat_status_del)',
            $this->hintFor(['category' => 'electronics']),
        );
    }

    /** The regression: a shop-scoped category browse must NOT pin the index. */
    public function testShopScopedCategoryBrowseDropsTheHint(): void
    {
        $this->assertSame(
            '',
            $this->hintFor(['category' => 'electronics', 'shop_ids' => range(1, 200)]),
            'pinning an index on products forces a catalogue walk instead of starting from product_shops',
        );
    }

    /** Single shop and many shops must behave identically — the old code split them. */
    public function testSingleAndMultiShopScopesAreTreatedTheSame(): void
    {
        $single = $this->hintFor(['category' => 'electronics', 'shop_ids' => [7]]);
        $many   = $this->hintFor(['category' => 'electronics', 'shop_ids' => range(1, 200)]);

        $this->assertSame($single, $many);
        $this->assertSame('', $single);
    }

    /** An empty shop list still counts as scoped (it means "nothing delivers here"). */
    public function testEmptyShopListIsStillAScope(): void
    {
        $this->assertSame('', $this->hintFor(['category' => 'electronics', 'shop_ids' => []]));
    }

    /** No literal hint assignment may survive outside the single chooser. */
    public function testEveryHintSiteRoutesThroughTheChooser(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Models/StoreCatalogRepository.php');

        $this->assertSame(
            0,
            preg_match_all("/! empty\(\\\$opts\['category'\]\) \? 'FORCE INDEX/", $src),
            'a hint site bypasses categoryIndexHint() and will re-pin the index under a shop scope',
        );
    }

    /** computeTree()'s carve-out must cover any shop scope, not just a single shop. */
    public function testComputeTreeDropsTheHintForAnyShopScope(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Models/StoreCatalogRepository.php');

        $this->assertStringContainsString('$shopScoped = array_key_exists(\'shop_ids\', $opts);', $src);
        $this->assertStringNotContainsString(
            'count((array) $opts[\'shop_ids\']) <= 1',
            $src,
            'the single-shop-only carve-out left multi-shop scopes pinned, which is the slow case',
        );
    }
}
