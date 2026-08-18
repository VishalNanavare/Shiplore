<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * Manufacturers sell B2B only — their products must never reach the consumer
 * storefront (shiplore.test) or the customer mobile apps.
 *
 * StoreCatalogRepository is the single gate for both surfaces: the web storefront and
 * Api\V1\CustomerApiController are both thin wrappers over it. But several of its read
 * methods build their own query rather than routing through baseQuery(), so the
 * exclusion has to be repeated per method — and a new method that forgets it silently
 * leaks the whole manufacturer catalogue onto the consumer storefront.
 *
 * These tests assert the predicate is present at every entry point, following the same
 * approach as the existing NOT_HOTEL vertical exclusion.
 */
final class ManufacturerCatalogExclusionTest extends CIUnitTestCase
{
    private string $src;
    private string $cart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->src  = (string) file_get_contents(APPPATH . 'Models/StoreCatalogRepository.php');
        $this->cart = (string) file_get_contents(APPPATH . 'Libraries/Store/CartService.php');
    }

    /** The predicate must compare against the vendors join, defaulting to 'vendor'. */
    public function testExclusionPredicatesAreDefined(): void
    {
        $this->assertStringContainsString(
            "COALESCE(v.party_type,'vendor') <> 'manufacturer'",
            $this->src,
            'the join-based exclusion predicate is missing',
        );
        $this->assertStringContainsString(
            "NOT EXISTS (SELECT 1 FROM vendors mv WHERE mv.id = p.vendor_id AND mv.party_type = 'manufacturer')",
            $this->src,
            'the join-free (EXISTS) exclusion predicate is missing',
        );
    }

    /**
     * COALESCE matters: a product whose vendor row is missing on a LEFT JOIN must still
     * be treated as an ordinary vendor product, not silently dropped from the storefront.
     */
    public function testMissingVendorRowDoesNotHideAVendorProduct(): void
    {
        $this->assertStringContainsString("COALESCE(v.party_type,'vendor')", $this->src);
        $this->assertStringNotContainsString(
            "v.party_type <> 'manufacturer'",
            $this->src,
            'a bare comparison would drop products whose vendor row did not join (NULL <> x is NULL)',
        );
    }

    /**
     * Every storefront read path must carry the exclusion.
     *
     * @dataProvider catalogEntryPoints
     */
    public function testEveryCatalogEntryPointExcludesManufacturers(string $method): void
    {
        $body = $this->methodBody($this->src, $method);

        $this->assertNotSame('', $body, "could not locate {$method}() — did it move or get renamed?");
        $this->assertMatchesRegularExpression(
            '/NOT_MANUFACTURER(_EXISTS)?/',
            $body,
            "StoreCatalogRepository::{$method}() does not exclude manufacturer products — "
            . 'manufacturer stock would be visible on the consumer storefront',
        );
    }

    public static function catalogEntryPoints(): array
    {
        return [
            // Covers products(), countProducts(), computeCount() and every facet method.
            'baseQuery'       => ['baseQuery'],
            'facetBase'       => ['facetBase'],
            // These build their own queries and do NOT route through the two above.
            'computeTree'     => ['computeTree'],
            'findBySlug'      => ['findBySlug'],
            'publishedTitle'  => ['publishedTitle'],
            'relatedProducts' => ['relatedProducts'],
        ];
    }

    /**
     * Defence in depth. CartService resolves by raw variant_id straight from the session
     * cart, so browse-time filtering alone does not stop a guessed id being priced in.
     */
    public function testCartServiceAlsoExcludesManufacturers(): void
    {
        $this->assertStringContainsString(
            "COALESCE(v.party_type,'vendor') <> 'manufacturer'",
            $this->cart,
            'CartService::linesFor() must reject manufacturer variants',
        );
    }

    /** The customer API must not have its own catalog query that bypasses the repository. */
    public function testCustomerApiHasNoIndependentProductQuery(): void
    {
        $api = (string) file_get_contents(APPPATH . 'Controllers/Api/V1/CustomerApiController.php');

        $this->assertStringNotContainsString(
            "table('products",
            $api,
            'the customer API must go through StoreCatalogRepository — a direct products '
            . 'query would bypass the manufacturer exclusion entirely',
        );
    }

    /** The manufacturer hostnames must be allow-listed or site_url() misbehaves on them. */
    public function testManufacturerSubdomainsAreConfigured(): void
    {
        $hosts  = (new App())->allowedHostnames;
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        foreach (['manufacturer.shiplore.test', 'mshop.shiplore.test', 'monline.shiplore.test'] as $h) {
            $this->assertContains($h, $hosts);
        }

        // manufacturer. and mshop. resolve; monline. deliberately does not yet (phase B).
        $this->assertStringContainsString("'subdomain' => 'manufacturer'", $routes);
        $this->assertStringContainsString("'subdomain' => 'mshop'", $routes);
    }

    /** Crude brace-matching body extractor — enough to scope an assertion to one method. */
    private function methodBody(string $src, string $method): string
    {
        if (! preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $start = (int) $m[0][1];
        $brace = strpos($src, '{', $start);
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
}
