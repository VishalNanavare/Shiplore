<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Adversarial audit finding (2026-08-20, sub-project C, medium severity): several
 * admin views built a confirm() dialog message by interpolating esc($x, 'attr')
 * output directly inside an onsubmit="return confirm('...')" JS-string-literal.
 * HTML-attribute escaping is the wrong context for text that will be re-parsed as
 * JavaScript — a browser HTML-decodes an attribute's value BEFORE executing it as
 * script, so a name/title containing a single quote breaks out of the confirm()
 * string and executes arbitrary JS in the viewing admin's session. Several of the
 * affected values (vendor display_name, shop name, rider name) are set by a
 * lower-privileged actor (the vendor themself), making this a real privilege
 * escalation path, not merely self-XSS.
 *
 * The audit's own two named instances (vendor-portal button, new per-row
 * shop-portal button) are also covered by HTTP-rendered tests in
 * AdminVendorShopNavigationTest.php; admin/shops/show.php and admin/riders/show.php
 * are covered here only, since their controllers have dependencies (raw SQL against
 * unprefixed table names, or no existing test scaffolding) that make a full HTTP
 * render disproportionately expensive for what is a template-structure check.
 *
 * This scans the whole file, not one instance, so a FUTURE re-introduction of this
 * exact anti-pattern anywhere in these views is caught too — not just today's fix.
 */
final class AdminOnsubmitConfirmXssTest extends CIUnitTestCase
{
    private const FILES = [
        'admin/vendors/show.php',
        'admin/shops/show.php',
        'admin/riders/show.php',
    ];

    public function testNoScannedViewInterpolatesEscAttrOutputInsideAnOnsubmitConfirmString(): void
    {
        foreach (self::FILES as $rel) {
            $path = APPPATH . 'Views/' . $rel;
            $this->assertFileExists($path);
            $src = (string) file_get_contents($path);

            $this->assertDoesNotMatchRegularExpression(
                "/onsubmit=\"return confirm\\('[^\"]*<\\?=\\s*esc\\([^)]*'attr'\\)/",
                $src,
                $rel . " must not build a confirm() message by interpolating esc(..., 'attr') inside an onsubmit attribute — use ajax-forms.js's data-confirm attribute instead, which never re-parses the text as code",
            );
        }
    }

    public function testAllThreeFormsUseTheSafeDataConfirmAttributeInstead(): void
    {
        foreach (self::FILES as $rel) {
            $src = (string) file_get_contents(APPPATH . 'Views/' . $rel);

            $this->assertMatchesRegularExpression(
                '/data-confirm="[^"]*<\?=\s*esc\(/',
                $src,
                $rel . ' should carry its dynamic confirm message via data-confirm, escaped for the attribute context',
            );
        }
    }
}
