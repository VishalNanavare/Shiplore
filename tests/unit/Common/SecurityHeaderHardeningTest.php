<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\ContentSecurityPolicy;

/**
 * vendor/ web-deny, the CSP violation-report endpoint, and the SecureHeaders filter
 * that carries .htaccess-only headers into the app (audit M10, M28, L13).
 *
 * M9 (HSTS) is deliberately NOT touched here — HardeningConfigTest already pins
 * forceGlobalSecureRequests=false as a live operator decision (cert coverage across
 * every allowedHostnames entry must be confirmed first; a wrong config can make a
 * host permanently unreachable to anyone who has visited, cached for max-age). This
 * file's job is the three findings that don't carry that risk.
 */
final class SecurityHeaderHardeningTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
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

    /**
     * collect() always returns 204 regardless of what it logged (the audit's own
     * design: a browser ignores the response either way) — so the Feature tests
     * above proving 204 for an oversized/garbage body do NOT prove the size cap or
     * the garbage tolerance actually gate anything internally. This checks the
     * guard exists in the right place, structurally.
     */
    public function testCollectHasASizeGuardBeforeLogging(): void
    {
        $body = $this->methodBody($this->read('Controllers/CspReportController.php'), 'collect');
        $this->assertStringContainsString('MAX_BYTES', $body);
        $this->assertMatchesRegularExpression('/strlen\(\$body\)\s*<=\s*self::MAX_BYTES/', $body);
        // The guard must actually gate the decode/log call, not just exist nearby.
        $guardPos = strpos($body, 'MAX_BYTES');
        $logPos   = strpos($body, 'log_message(');
        $this->assertNotFalse($guardPos);
        $this->assertNotFalse($logPos);
        $this->assertLessThan($logPos, $guardPos);
    }

    // ------------------------------------------------------------------ M28

    public function testVendorDirectoryIsWebDenied(): void
    {
        $this->assertFileExists(ROOTPATH . 'vendor/.htaccess', 'GET /vendor/composer/installed.json disclosed every pinned dependency version with no deny file');
        $htaccess = (string) file_get_contents(ROOTPATH . 'vendor/.htaccess');
        $this->assertStringContainsString('Require all denied', $htaccess);
        $this->assertStringContainsString('Deny from all', $htaccess, 'must work on both mod_authz_core and legacy Apache');
    }

    /** assets/vendor/ (bootstrap, bootstrap-icons, jquery) is a different directory and must stay readable. */
    public function testAssetsVendorDirectoryIsNotDenied(): void
    {
        $this->assertFileDoesNotExist(ROOTPATH . 'assets/vendor/.htaccess');
    }

    // ------------------------------------------------------------------ M10

    public function testCspReportUriIsConfiguredAndReportOnlyStaysUnflipped(): void
    {
        $csp = new ContentSecurityPolicy();
        $this->assertSame('/csp-report', $csp->reportURI, 'without a reportURI, reportOnly has nowhere to send violations');
        $this->assertTrue($csp->reportOnly, 'flipping this must be a SEPARATE change — the admin portal relies on inline scripts with no nonces yet');
    }

    public function testCspReportRouteAcceptsNoCsrf(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $this->assertMatchesRegularExpression(
            "/post\\('csp-report', 'CspReportController::collect'\\);/",
            $routes,
            'a browser-sent CSP report carries no session and no CSRF token, same as the PayU redirect-backs',
        );
    }

    public function testCollectAcceptsAWellFormedReportAndReturns204(): void
    {
        $body = (string) json_encode(['csp-report' => [
            'violated-directive' => 'script-src',
            'blocked-uri'        => 'https://evil.example/x.js',
            'document-uri'       => 'https://shiplore.in/store',
        ]]);
        $r = $this->withBody($body)->withBodyFormat('json')->post('csp-report');
        $r->assertStatus(204);
    }

    /** A malformed body must not throw or 500 — request->getJSON() would throw here. */
    public function testCollectToleratesGarbageBodyWithNoException(): void
    {
        $r = $this->withBody('not json at all {{{')->withBodyFormat('json')->post('csp-report');
        $r->assertStatus(204);
    }

    public function testCollectRejectsOversizedBodyBeforeParsing(): void
    {
        $body = (string) json_encode(['csp-report' => ['blocked-uri' => str_repeat('a', 20000)]]);
        $this->assertGreaterThan(8192, strlen($body));
        $r = $this->withBody($body)->withBodyFormat('json')->post('csp-report');
        $r->assertStatus(204);
    }

    // ------------------------------------------------------------------ L13

    public function testSecureHeadersAliasPointsAtTheAppClass(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Config/Filters.php');
        $this->assertStringContainsString("'secureheaders' => \\App\\Filters\\SecureHeaders::class", $src);
    }

    public function testAppSecureHeadersExtendsTheFrameworkFilter(): void
    {
        $this->assertTrue(is_subclass_of(\App\Filters\SecureHeaders::class, \CodeIgniter\Filters\SecureHeaders::class));
    }

    /**
     * A real request through the framework's filter chain — not a source scan — so
     * this actually proves the headers reach a response, not just that the code
     * mentions them.
     */
    public function testHeadersUniqueToHtaccessNowAlsoTravelWithTheApp(): void
    {
        $r = $this->get('login');
        $r->assertStatus(200);
        $r->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
        $r->assertHeader('Permissions-Policy', 'geolocation=(self), camera=(), microphone=(), payment=(), usb=()');
        // Still present — the framework's own headers, unaffected by the subclass.
        $r->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function testCoopAndCorpAreNowSet(): void
    {
        $r = $this->get('login');
        $r->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $r->assertHeader('Cross-Origin-Resource-Policy', 'same-site');
    }

    /**
     * CI4's own report-only CSP header must not collide with the app's enforcing
     * one. Not tested end-to-end via a live request: CI4's Feature-test harness
     * swaps in MockAppConfig, which hardcodes CSPEnabled=false, so
     * Content-Security-Policy-Report-Only never gets generated in this environment
     * regardless of the real app/Config/App.php setting (CSPEnabled=true, per the
     * audit). What's checked here is the header NAME never collides — the app-set
     * one is emitted through $response->setHeader() directly, not through the CI4
     * CSP library that owns the report-only name.
     */
    public function testEnforcingAndReportOnlyCspAreDistinctHeaders(): void
    {
        $r = $this->get('login');
        $r->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");

        $filterSrc = (string) file_get_contents(APPPATH . 'Filters/SecureHeaders.php');
        $this->assertStringNotContainsString(
            'Content-Security-Policy-Report-Only',
            $filterSrc,
            'the app filter must never set the report-only header — that name belongs to the CI4 CSP library',
        );
    }
}
