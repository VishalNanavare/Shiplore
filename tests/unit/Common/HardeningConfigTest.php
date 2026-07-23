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
        $this->assertFalse(
            (new App())->forceGlobalSecureRequests,
            'enabling this also enables HSTS — confirm certs on all five subdomains first',
        );
        $this->assertCount(5, (new App())->allowedHostnames);
    }
}
