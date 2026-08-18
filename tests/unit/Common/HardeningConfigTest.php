<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\ContentSecurityPolicy;
use Config\Filters;

/**
 * Pins the hardening configuration, and in particular the properties that make it
 * SAFE to ship to a live system. Several of these are one-character changes that
 * would take the site down if flipped carelessly, so they get a test rather than a
 * comment.
 */
final class HardeningConfigTest extends CIUnitTestCase
{
    /**
     * The one that matters most. CSP is enabled, but report-only.
     *
     * Enforcing it would break every page that uses an inline <script> or an inline
     * onsubmit/onclick handler — which is most of the admin portal. reportOnly emits
     * `Content-Security-Policy-Report-Only`, which browsers never enforce.
     */
    public function testCspIsEnabledButReportOnly(): void
    {
        $this->assertTrue((new App())->CSPEnabled, 'CSP should be on so violations are reported');
        $this->assertTrue(
            (new ContentSecurityPolicy())->reportOnly,
            'CSP must stay report-only until inline scripts carry nonces — enforcing it now '
            . 'would break the admin portal and checkout',
        );
    }

    /** Report-only is useless if it does not know about the hosts the app really uses. */
    public function testCspAllowsTheThirdPartiesTheAppActuallyLoads(): void
    {
        $csp = new ContentSecurityPolicy();

        $this->assertIsArray($csp->scriptSrc);
        foreach (['https://www.gstatic.com', 'https://www.google.com', 'https://maps.googleapis.com'] as $host) {
            $this->assertContains($host, $csp->scriptSrc, $host . ' is referenced by app/Views but not allowed');
        }
        $this->assertContains('self', $csp->scriptSrc);
    }

    /** Security headers must be emitted by the app, not only by the .htaccess. */
    public function testSecureHeadersFilterRunsGlobally(): void
    {
        $after = (new Filters())->globals['after'];

        $this->assertContains(
            'secureheaders',
            $after,
            'the app must set its own security headers so they survive a DocumentRoot change',
        );
    }

    /** CSRF is still opt-in per route; this records that fact so the gap stays visible. */
    public function testCsrfIsStillNotGlobalAndIsTrackedAsAKnownGap(): void
    {
        $before = (new Filters())->globals['before'];

        // Not yet global — enabling it needs the documented exception list
        // (api/*, the PayU redirect-back callbacks) or checkout breaks.
        // When that lands, invert this assertion.
        $this->assertNotContains(
            'csrf',
            $before,
            'csrf is now global — good; update this test and drop the per-route filters '
            . 'that are no longer needed',
        );
    }

    /**
     * HSTS and forced HTTPS are deliberately NOT enabled here.
     *
     * Strict-Transport-Security is cached by the browser for max-age. Enabling it
     * before every one of the five hostnames in App::$allowedHostnames has a valid
     * certificate would make the broken one permanently unreachable for anyone who
     * had already visited. That is an operator decision, gated on verifying certs.
     */
    public function testTlsEnforcementIsLeftToTheOperator(): void
    {
        $app = new App();

        $this->assertFalse(
            $app->forceGlobalSecureRequests,
            'enabling this also enables HSTS — confirm a valid certificate on EVERY host '
            . 'in allowedHostnames first. HSTS is cached by the browser for max-age, so a '
            . 'host without one becomes permanently unreachable to anyone who has visited.',
        );

        // Every allow-listed host needs its own certificate before HSTS can be enabled.
        // Asserted as a property of the list rather than a fixed count, so adding a
        // hostname does not fail this test — it just widens what must be checked.
        $this->assertNotEmpty($app->allowedHostnames);
        foreach ($app->allowedHostnames as $host) {
            $this->assertMatchesRegularExpression('/^[a-z0-9.-]+$/', $host, 'malformed hostname: ' . $host);
        }
    }

    /** The manufacturer surfaces must be reachable, or site_url() misbehaves on them. */
    public function testManufacturerHostnamesAreAllowed(): void
    {
        $hosts = (new App())->allowedHostnames;

        foreach (['manufacturer.shiplore.test', 'mshop.shiplore.test', 'monline.shiplore.test'] as $host) {
            $this->assertContains($host, $hosts, $host . ' must be allow-listed');
        }
    }

    // ------------------------------------------------------------- php runtime

    /** @return array<string,string> directive => value, comments stripped */
    private function userIni(): array
    {
        $path = ROOTPATH . '.user.ini';
        $this->assertFileExists($path, '.user.ini must be tracked — a deployment already deleted it once');

        $out = [];

        foreach (file($path) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ';') || str_starts_with($line, '[')) {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            $out[trim($k)] = trim($v);
        }

        return $out;
    }

    /**
     * The directives the application cannot run without.
     *
     * This file was untracked for privacy, a deployment checkout deleted it, and the site
     * went down: CodeIgniter throws at pre_system when zlib.output_compression is on
     * (app/Config/Events.php), so NO route resolved. display_errors reverted at the same
     * moment, which publishes stack traces carrying absolute server paths.
     *
     * Tracking the file is the fix; this is what stops someone ignoring it again for the
     * same well-meant reason.
     */
    public function testTheRuntimeIniKeepsTheDirectivesTheAppNeeds(): void
    {
        $ini = $this->userIni();

        $this->assertSame('Off', $ini['zlib.output_compression'] ?? '', 'CodeIgniter cannot boot with zlib compression on');
        $this->assertSame('Off', $ini['display_errors'] ?? '', 'stack traces must never reach a visitor');
        $this->assertSame('On', $ini['log_errors'] ?? '');

        // A product form with a large variant matrix silently DROPS surplus inputs past
        // this limit rather than failing, so variants go missing with a success message.
        $this->assertGreaterThanOrEqual(10000, (int) ($ini['max_input_vars'] ?? 0));
    }

    /**
     * …and it must still name no server path or hosting account.
     *
     * The original carried error_log = "/home/<account>/logs/php.error.log". Tracking the
     * file for reliability must not re-publish that: omitting error_log lets PHP fall back
     * to the server's own log, which is where cPanel looks anyway.
     */
    public function testTheRuntimeIniNamesNoServerPath(): void
    {
        $body = (string) file_get_contents(ROOTPATH . '.user.ini');

        $this->assertDoesNotMatchRegularExpression(
            '#^\s*[a-z_.]+\s*=\s*"?/home/#mi',
            $body,
            'a hosting-account path must not be published — omit the directive and use the server default',
        );
        $this->assertArrayNotHasKey('error_log', $this->userIni(), 'error_log names an account path; leave it to the server');
    }
}
