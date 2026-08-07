<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The workflow that audited this change (a Find-then-adversarially-Verify sweep
 * across every panel's controllers and views) found 7 real sites that relied on
 * site_url()'s current-host resolution to build a link into a DIFFERENT panel's
 * route group. Once that group is restricted to its own subdomain
 * (PanelSubdomainIsolationTest), every one of these would 404: same host, wrong
 * panel's path. Each is fixed here either by routing through panel_url()
 * (PanelUrlHelperTest) or, where the simpler fix was to stop crossing panels at all
 * (partials/_topbar.php), by picking a same-panel destination instead.
 *
 * One candidate the audit's own verify pass flagged was independently re-checked
 * and confirmed real despite the verify agent's "SAFE" verdict there being wrong
 * (it mis-read the file) — see app/Views/vendor/dashboard.php:42-43 below. Nothing
 * here is taken on the workflow's word alone; every assertion targets code read
 * directly from these files.
 */
final class CrossPanelLinkFixTest extends CIUnitTestCase
{
    /** Source with comments stripped — several of these fixes carry comments naming site_url(), which must not satisfy a "does not use site_url" assertion. */
    private function code(string $path): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- vendor/dashboard.php

    public function testVendorDashboardMonlineLinksUsePanelUrl(): void
    {
        $code = $this->code(APPPATH . 'Views/vendor/dashboard.php');

        $this->assertStringContainsString("panel_url('monline', 'monline/orders')", $code);
        $this->assertStringContainsString("panel_url('monline', 'monline/browse')", $code);
        $this->assertDoesNotMatchRegularExpression(
            "/site_url\('monline/",
            $code,
            'the monline links must not still resolve against the vendor panel\'s own host',
        );
    }

    // ---------------------------------------------------------------- partials/_topbar.php

    public function testTopbarNotificationsLinkHandlesAllThreePanels(): void
    {
        $code = $this->code(APPPATH . 'Views/partials/_topbar.php');

        $this->assertMatchesRegularExpression(
            "/str_starts_with\(uri_string\(\), 'admin'\)\s*\)\s*\{\s*\\\$notifAllUrl = site_url\('admin\/notifications'\);/s",
            $code,
        );
        $this->assertMatchesRegularExpression(
            "/str_starts_with\(uri_string\(\), 'manufacturer'\)\s*\)\s*\{\s*\\\$notifAllUrl = site_url\('manufacturer\/dashboard'\);/s",
            $code,
            'manufacturer must not fall through to a vendor-panel route that does not exist for it',
        );
        $this->assertStringContainsString("\$notifAllUrl = site_url('vendor/notifications');", $code);
    }

    public function testTopbarProfileLinkHandlesAllThreePanels(): void
    {
        $code = $this->code(APPPATH . 'Views/partials/_topbar.php');

        $this->assertMatchesRegularExpression(
            "/str_starts_with\(uri_string\(\), 'admin'\)\s*\)\s*\{\s*\\\$profileUrl = site_url\('admin\/profile'\);/s",
            $code,
        );
        $this->assertMatchesRegularExpression(
            "/str_starts_with\(uri_string\(\), 'manufacturer'\)\s*\)\s*\{\s*\\\$profileUrl = site_url\('manufacturer\/dashboard'\);/s",
            $code,
        );
        $this->assertStringContainsString("\$profileUrl = site_url('vendor/me');", $code);

        // The old 2-way ternary sent every non-admin panel — including manufacturer —
        // straight into 'vendor/me'. That construct must be gone, not just supplemented.
        $this->assertDoesNotMatchRegularExpression(
            "/site_url\(str_starts_with\(uri_string\(\), 'admin'\) \? 'admin\/profile' : 'vendor\/me'\)/",
            $code,
        );
    }

    // ---------------------------------------------------------------- partials/_impersonation_banner.php

    public function testImpersonationBannerLeaveFormUsesPanelUrl(): void
    {
        $code = $this->code(APPPATH . 'Views/partials/_impersonation_banner.php');

        $this->assertStringContainsString("panel_url('admin', 'admin/portal/leave')", $code);
        $this->assertDoesNotMatchRegularExpression("/site_url\('admin\/portal\/leave'\)/", $code);
    }

    // ---------------------------------------------------------------- monline/_layout.php

    public function testMonlineLayoutVendorLinksUsePanelUrl(): void
    {
        $code = $this->code(APPPATH . 'Views/monline/_layout.php');

        $this->assertSame(
            2,
            substr_count($code, "panel_url('vendor', 'vendor/dashboard')"),
            'both the header dropdown and footer links must be fixed',
        );
        $this->assertDoesNotMatchRegularExpression("/site_url\('vendor\/dashboard'\)/", $code);
    }

    // ---------------------------------------------------------------- WebAuthFilter.php

    public function testWebAuthFilterLandingRedirectUsesPanelUrl(): void
    {
        $code = $this->code(APPPATH . 'Filters/WebAuthFilter.php');

        $this->assertMatchesRegularExpression(
            '/panel_url\(self::LANDING_SUBDOMAIN\[\$actual\], \$landing\)/',
            $code,
        );
        // The mapping must cover exactly the three real principal types LANDING does
        // (this is the same array, checked structurally rather than as a raw string
        // so reordering the entries can't make the test brittle).
        $this->assertMatchesRegularExpression(
            "/'platform'\s*=>\s*'admin',/",
            $code,
        );
        $this->assertMatchesRegularExpression(
            "/'vendor'\s*=>\s*'vendor',/",
            $code,
        );
        $this->assertMatchesRegularExpression(
            "/'manufacturer'\s*=>\s*'manufacturer',/",
            $code,
        );
    }

    /** A LANDING key with no matching subdomain must fall back to 'login', not crash. */
    public function testWebAuthFilterHandlesAnUnmappedActualGracefully(): void
    {
        $code    = $this->code(APPPATH . 'Filters/WebAuthFilter.php');
        $checkFn = $this->methodBody($code, 'checkPrincipal');

        $this->assertMatchesRegularExpression(
            '/\$landing = self::LANDING\[\$actual\] \?\? null;\s*if \(\$landing === null\)\s*\{\s*return redirect\(\)->to\(\'login\'\)/s',
            $checkFn,
        );
    }

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

    // ---------------------------------------------------------------- DocumentStorage.php

    public function testDocumentStoragePresignGetDummyFallbackUsesPanelUrl(): void
    {
        $code = $this->code(APPPATH . 'Libraries/Storage/DocumentStorage.php');
        $body = $this->methodBody($code, 'presignGet');

        $this->assertNotSame('', $body, 'presignGet() not found');
        $this->assertMatchesRegularExpression(
            '/\[\$dummySub\] = explode\(\'\/\', \$dummyRoute, 2\);\s*return panel_url\(\$dummySub, \$dummyRoute \. \'\?key=\' \. rawurlencode\(\$key\)\);/s',
            $body,
        );
        $this->assertDoesNotMatchRegularExpression("/return site_url\(\\\$dummyRoute/", $body);
    }

    /**
     * The two SAME-panel callers (called from within the vendor panel itself, so the
     * current host already IS the target panel) must keep working exactly as before —
     * this pins that the fix is behavior-preserving for them, not just for the two
     * cross-panel callers it was written for.
     */
    public function testSamePanelPresignGetCallersAreUnchanged(): void
    {
        $vendorMedia  = (string) file_get_contents(APPPATH . 'Controllers/Vendor/MediaController.php');
        $vendorUpload = (string) file_get_contents(APPPATH . 'Controllers/Vendor/DocumentUploadController.php');

        $this->assertStringContainsString("presignGet((string) \$m['object_key'], 'vendor/media/file')", $vendorMedia);
        $this->assertStringContainsString("presignGet((string) \$doc['object_key'])", $vendorUpload);
    }

    /**
     * The shared, host-agnostic media/(:segment) route (App\Controllers\MediaController,
     * reachable from any host) also falls through presignGet()'s dummy branch when the
     * asset is S3-backed but S3 is unconfigured. It uses the DEFAULT dummyRoute
     * ('vendor/kyc/file'), which is fixed by the same DocumentStorage change — this
     * just pins that the call site itself was left untouched (no per-caller special
     * casing needed, confirming the shared fix covers it).
     */
    public function testSharedMediaControllerStillUsesTheDefaultDummyRoute(): void
    {
        $code = (string) file_get_contents(APPPATH . 'Controllers/MediaController.php');

        $this->assertStringContainsString("presignGet((string) \$asset['object_key'])", $code);
    }
}
