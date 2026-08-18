<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * Every allow-listed subdomain must have its own root route.
 *
 * CI4 registers a `['subdomain' => x]` route only when the current host matches, and the
 * bare `$routes->get('/')` apex fallback is declared last. So a hostname WITHOUT its own
 * root route silently falls through to the apex — which is Store\StoreController::home,
 * the consumer storefront.
 *
 * That failure mode is invisible in code review and total in effect: monline.shiplore.test
 * would have served the consumer shop, with consumer pricing, on the B2B hostname.
 *
 * This test makes the omission impossible to ship: add a hostname to
 * Config\App::$allowedHostnames without a matching root route and it fails.
 */
final class SubdomainRootRouteTest extends CIUnitTestCase
{
    private string $routesSrc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->routesSrc = (string) file_get_contents(APPPATH . 'Config/Routes.php');
    }

    /** @return list<string> the subdomain labels of every allow-listed host */
    private function allowedSubdomains(): array
    {
        $labels = [];
        foreach ((new App())->allowedHostnames as $host) {
            $parts = explode('.', $host);
            // 'shiplore.test' is the apex (2 labels) — it uses the fallback by design.
            if (count($parts) > 2) {
                $labels[] = $parts[0];
            }
        }

        return $labels;
    }

    /** The whole point of this file. */
    public function testEveryAllowedSubdomainHasARootRoute(): void
    {
        $missing = [];
        foreach ($this->allowedSubdomains() as $label) {
            if (! str_contains($this->routesSrc, "'subdomain' => '{$label}'")) {
                $missing[] = $label;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These hosts are allow-listed but have no root route, so '/' on them falls "
            . "through to the apex and serves the CONSUMER storefront: "
            . implode(', ', $missing),
        );
    }

    /** Each of the seven surfaces must land on its own entry point, not another's. */
    public function testSubdomainsRouteToTheCorrectEntryPoint(): void
    {
        $expected = [
            'admin'        => 'Auth\LoginController::show',
            'vendor'       => 'Auth\LoginController::show',
            'shop'         => 'Auth\LoginController::show',
            'rider'        => 'Rider\AuthController::loginForm',
            'manufacturer' => 'Auth\LoginController::show',
            'mshop'        => 'Auth\LoginController::show',
            'monline'     => 'Monline\CatalogController::home',
        ];

        foreach ($expected as $label => $handler) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($handler, '/') . ".*?'subdomain' => '" . $label . "'/",
                $this->routesSrc,
                "{$label}. must route to {$handler}",
            );
        }
    }

    /**
     * Ordering is load-bearing: a subdomain route registers with overwrite, the apex '/'
     * does not, so the apex must come LAST or it wins and every panel root breaks.
     */
    public function testApexFallbackIsDeclaredAfterEverySubdomainRoot(): void
    {
        $apex = strpos($this->routesSrc, "\$routes->get('/', 'Store\\StoreController::home');");
        $this->assertNotFalse($apex, 'the apex storefront root route is missing');

        foreach ($this->allowedSubdomains() as $label) {
            $pos = strpos($this->routesSrc, "'subdomain' => '{$label}'");
            $this->assertNotFalse($pos);
            $this->assertLessThan(
                $apex,
                $pos,
                "the {$label}. root route must be declared BEFORE the apex fallback",
            );
        }
    }

    /** monline must never render a price to a visitor who is not a signed-in buyer. */
    public function testMonlineGatesPricingOnABuyerSession(): void
    {
        $base = (string) file_get_contents(APPPATH . 'Controllers/Monline/BaseMonlineController.php');
        $home = (string) file_get_contents(APPPATH . 'Views/monline/home.php');

        // The gate must be derived from a resolved vendor, not from a bare login check.
        $this->assertStringContainsString('function isBuyer', $base);
        $this->assertStringContainsString('buyerVendorId() !== null', $base);
        $this->assertStringContainsString("principal_type') === 'vendor'", $base);

        // Nothing price-shaped may appear on the logged-out branch of the landing page.
        $loggedOut = substr($home, (int) strpos($home, 'else:'));
        $this->assertStringNotContainsString('₹', $loggedOut, 'no price may render to a logged-out visitor');
        $this->assertStringNotContainsString('base_price', $loggedOut);
        $this->assertStringNotContainsString('making_price', $loggedOut);
    }

    /** Making price is the manufacturer's internal cost — it must not reach monline at all. */
    public function testMakingPriceIsNeverExposedOnMonline(): void
    {
        foreach (glob(APPPATH . 'Controllers/Monline/*.php') ?: [] as $file) {
            $this->assertStringNotContainsString(
                'making_price',
                (string) file_get_contents($file),
                basename($file) . ' references making_price — that is the manufacturer\'s '
                . 'internal production cost and must never be served to a buyer',
            );
        }
        foreach (glob(APPPATH . 'Views/monline/*.php') ?: [] as $file) {
            $this->assertStringNotContainsString('making_price', (string) file_get_contents($file), basename($file));
        }
    }
}
