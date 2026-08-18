<?php

declare(strict_types=1);

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Cookie;
use Config\Cors;

/**
 * The platform's domain is configuration, not a literal.
 *
 * `Config\App::$allowedHostnames` used to be eight hand-written hostnames and
 * `Config\Cookie::$domain` a ninth copy of the same string. Both are now derived from
 * a single `Config\App::$baseDomain`, overridable with `app.baseDomain` in .env, so
 * moving environments is one line instead of nine edits across two files.
 *
 * Why this list is load-bearing beyond CI4's host validation:
 * SiteURIFactory::getValidHost() substitutes the REQUEST's host into site_url() only
 * when that host appears in $allowedHostnames. A host missing from it does not 404 —
 * it silently falls back to $baseURL's domain, so every link on that panel points at
 * another origin. That is precisely the failure that broke "Return to Admin"
 * (CrossPanelLinkFixTest), reached from the other direction.
 */
final class AllowedHostnamesTest extends CIUnitTestCase
{
    /**
     * The configured domain drives the whole list, in order.
     *
     * Written out in full rather than generated, so a change to the derivation cannot
     * quietly agree with itself. The value comes from phpunit.dist.xml's
     * app.baseDomain — proof in itself that the list follows configuration rather than
     * anything written into app/Config/App.php.
     */
    public function testTheConfiguredDomainDrivesTheWholeList(): void
    {
        $this->assertSame(
            [
                'shiplore.test',
                'admin.shiplore.test',
                'vendor.shiplore.test',
                'shop.shiplore.test',
                'rider.shiplore.test',
                'manufacturer.shiplore.test',
                'mshop.shiplore.test',
                'monline.shiplore.test',
            ],
            config('App')->allowedHostnames,
        );
    }

    /**
     * No tracked config file may name a real deployment domain.
     *
     * The repository is public. The domain is not a secret — it is in DNS and in
     * certificate-transparency logs regardless — but the platform's address is
     * deployment configuration and belongs in .env, which is gitignored. This is the
     * assertion that keeps someone "helpfully" restoring a working default.
     *
     * `.test` is reserved by RFC 6761 and `localhost` by RFC 2606, so neither can ever
     * be a real deployment.
     */
    public function testTrackedConfigNamesNoRealDomain(): void
    {
        // Constructor bypassed, exactly as Tests\Support\Libraries\ConfigReader does, so
        // these are the literals as written in the file — not the .env-resolved values.
        $raw = new class extends App {
            public function __construct() {}
        };

        $this->assertSame('localhost', $raw->baseDomain, 'app/Config/App.php must not name a real domain');
        $this->assertSame('https://localhost/', $raw->baseURL, 'app/Config/App.php must not name a real base URL');
        $this->assertSame([], $raw->allowedHostnames, 'the hostname list must be derived, never written out');

        $rawCookie = new class extends Cookie {
            public function __construct() {}
        };
        $this->assertSame('', $rawCookie->domain, 'app/Config/Cookie.php must not name a real domain');

        // And a sweep, so a real domain cannot arrive in some OTHER literal in these
        // files. Asserts it actually examined something first — the earlier version of
        // this test matched nothing and passed with zero assertions.
        $scanned = 0;

        foreach (['Config/App.php', 'Config/Cookie.php', 'Config/Cors.php'] as $file) {
            $code = '';

            foreach (token_get_all((string) file_get_contents(APPPATH . $file)) as $t) {
                if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                    continue; // comments discuss the design; only code is the concern
                }
                $code .= is_array($t) ? $t[1] : $t;
            }
            $scanned += strlen($code);

            // Any dotted hostname appearing anywhere inside a string literal, including
            // inside a URL. Case-SENSITIVE on purpose: a real TLD is lowercase, whereas
            // this codebase's env keys are camelCase ('app.baseURL', 's3.accessKey'), so
            // requiring a lowercase final label separates hostnames from config keys.
            preg_match_all('/[\'"][^\'"]*?\b((?:[a-z0-9-]+\.)+[a-z]{2,})\b/', $code, $m);

            foreach ($m[1] as $host) {
                // .test and .localhost are reserved by RFC 6761, example.* by RFC 2606,
                // so none of them can ever be a real deployment.
                $this->assertMatchesRegularExpression(
                    '/(^|\.)(test|localhost|example\.(com|org|net))$/',
                    $host,
                    "{$file} hardcodes '{$host}' — the deployment domain belongs in .env (app.baseDomain)",
                );
            }
        }

        $this->assertGreaterThan(0, $scanned, 'no config source was read — the sweep proved nothing');
    }

    /**
     * $baseURL is the third copy of the domain and derives from the same source.
     *
     * Asserted through a fresh instance rather than config('App'), because
     * phpunit.dist.xml pins app.baseURL to http://example.com/ for the whole suite —
     * which is itself the behaviour the second half of this test protects.
     */
    public function testTheBaseUrlDerivesFromTheBaseDomain(): void
    {
        // app.baseURL must be genuinely absent for the fallback to engage, and
        // phpunit.dist.xml sets it process-wide — so unset it for this assertion only.
        $saved = $_SERVER['app.baseURL'] ?? null;
        unset($_SERVER['app.baseURL'], $_ENV['app.baseURL']);

        try {
            $fresh = new class extends App {
                public function __construct()
                {
                    $this->baseDomain       = 'example.co.uk';
                    $this->allowedHostnames = [];
                    parent::__construct();
                }
            };

            $this->assertSame('https://example.co.uk/', $fresh->baseURL);
        } finally {
            if ($saved !== null) {
                $_SERVER['app.baseURL'] = $saved;
            }
        }
    }

    /** An explicit app.baseURL must still win — the suite itself depends on this. */
    public function testAnExplicitBaseUrlIsNotOverwritten(): void
    {
        $this->assertSame(
            'http://example.com/',
            config('App')->baseURL,
            "phpunit.dist.xml's app.baseURL must survive the constructor's fallback",
        );
    }

    /** The whole point: one value moves the platform. */
    public function testChangingTheBaseDomainMovesEveryPanel(): void
    {
        $hosts = App::hostnamesFor('example.co.uk');

        $this->assertSame('example.co.uk', $hosts[0], 'the bare root must be accepted too');
        $this->assertContains('admin.example.co.uk', $hosts);
        $this->assertContains('manufacturer.example.co.uk', $hosts);
        $this->assertContains('monline.example.co.uk', $hosts);
        $this->assertNotContains('admin.shiplore.test', $hosts, 'nothing may stay pinned to the old domain');
    }

    /**
     * The CONSTRUCTED config must derive too, not merely offer a helper that does.
     *
     * Without this, re-hardcoding the eight literals back into $allowedHostnames passes
     * every other test in this file: the default case produces identical output either
     * way, and hostnamesFor() would sit there still correct and no longer called. A
     * mutation run proved exactly that, so this asserts on the instance.
     */
    public function testTheConstructedConfigDerivesItsHostnames(): void
    {
        $fresh = new class extends App {
            public function __construct()
            {
                $this->baseDomain       = 'example.co.uk';
                $this->allowedHostnames = [];
                parent::__construct();
            }
        };

        $this->assertContains('admin.example.co.uk', $fresh->allowedHostnames);
        $this->assertContains('mshop.example.co.uk', $fresh->allowedHostnames);
        $this->assertNotContains(
            'admin.shiplore.test',
            $fresh->allowedHostnames,
            'allowedHostnames is hardcoded again — changing baseDomain no longer moves the platform',
        );
    }

    /** A leading dot or stray whitespace in .env must not produce `admin..example.com`. */
    public function testTheBaseDomainIsNormalised(): void
    {
        $this->assertSame(App::hostnamesFor('example.com'), App::hostnamesFor('  .example.com '));
    }

    /**
     * The cookie domain must derive from the SAME source.
     *
     * Drift here is not cosmetic: the leading dot is what lets one session span
     * admin./vendor./manufacturer., so a cookie domain naming a different root logs the
     * user out on every hop between panels.
     */
    public function testTheCookieDomainDerivesFromTheSameBaseDomain(): void
    {
        $this->assertSame('.' . config('App')->baseDomain, config('Cookie')->domain);
    }

    /**
     * And it must follow a CHANGED base domain, not just agree with the default.
     *
     * Same lesson as the hostnames above: '.shiplore.test' hardcoded back into Cookie
     * satisfies the assertion above, because the default App also says shiplore.test.
     * Injecting a different App is what separates "derived" from "coincidentally equal".
     */
    public function testTheCookieDomainFollowsAChangedBaseDomain(): void
    {
        $fake = new class extends App {
            public function __construct()
            {
                $this->baseDomain       = 'example.co.uk';
                $this->allowedHostnames = [];
                parent::__construct();
            }
        };
        Factories::injectMock('config', App::class, $fake);

        try {
            $this->assertSame(
                '.example.co.uk',
                (new Cookie())->domain,
                'the cookie domain is hardcoded again — a session would be scoped to the wrong domain',
            );
        } finally {
            Factories::reset('config');
        }
    }

    // ------------------------------------------------------------------- cors

    /** Unset stays locked down — expansion must never happen by default. */
    public function testCorsIsClosedWhenTheEnvVarIsUnset(): void
    {
        $this->assertSame([], $this->corsOriginsFor(''));
    }

    /** `panels` writes the domain once instead of eight times. */
    public function testPanelsExpandsToEveryAllowedHost(): void
    {
        $origins = $this->corsOriginsFor('panels');

        $this->assertCount(count(config('App')->allowedHostnames), $origins);
        $this->assertContains('http://admin.shiplore.test', $origins, 'the suite pins baseURL to http, so origins follow that scheme');
        $this->assertContains('http://monline.shiplore.test', $origins);
    }

    /** The scheme follows baseURL, so a local http install does not emit https origins. */
    public function testPanelOriginsFollowTheBaseUrlScheme(): void
    {
        foreach ($this->corsOriginsFor('panels') as $origin) {
            $this->assertStringStartsWith('http://', $origin);
        }
    }

    /** Literal origins still work, and mix with the token. */
    public function testExplicitOriginsStillWorkAlongsideTheToken(): void
    {
        $origins = $this->corsOriginsFor('panels,https://partner.example.com');

        $this->assertContains('https://partner.example.com', $origins);
        $this->assertContains('http://admin.shiplore.test', $origins);

        $onlyLiteral = $this->corsOriginsFor('https://partner.example.com');
        $this->assertSame(['https://partner.example.com'], $onlyLiteral, 'without the token nothing is expanded');
    }

    /** @return list<string> */
    private function corsOriginsFor(string $env): array
    {
        $saved                              = $_SERVER['app.corsAllowedOrigins'] ?? null;
        $_SERVER['app.corsAllowedOrigins'] = $env;

        try {
            return (new Cors())->default['allowedOrigins'];
        } finally {
            if ($saved === null) {
                unset($_SERVER['app.corsAllowedOrigins']);
            } else {
                $_SERVER['app.corsAllowedOrigins'] = $saved;
            }
        }
    }

    /**
     * Every subdomain the ROUTER pins a group to must be in PANEL_SUBDOMAINS.
     *
     * This is the guard that matters going forward. Adding a panel means adding a
     * 'subdomain' route option; forget the matching label here and the new panel's host
     * is absent from $allowedHostnames, so its routes register but every link it renders
     * silently points at the wrong origin. Parsed from Routes.php rather than restated,
     * so the two cannot drift.
     */
    public function testEverySubdomainTheRouterPinsIsCovered(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        // Both forms: 'subdomain' => 'admin'  and  'subdomain' => ['vendor', 'shop'].
        preg_match_all("/'subdomain'\s*=>\s*(\[[^\]]*\]|'[a-z]+')/", $src, $m);
        $this->assertNotEmpty($m[1], 'no subdomain route options found — has Routes.php moved?');

        $routed = [];

        foreach ($m[1] as $raw) {
            preg_match_all("/'([a-z]+)'/", $raw, $labels);
            foreach ($labels[1] as $label) {
                $routed[$label] = true;
            }
        }
        $routed = array_keys($routed);
        sort($routed);

        $known = App::PANEL_SUBDOMAINS;
        sort($known);

        $this->assertSame(
            $routed,
            $known,
            'PANEL_SUBDOMAINS and the router disagree — a panel here would render links on the wrong origin',
        );
    }
}
