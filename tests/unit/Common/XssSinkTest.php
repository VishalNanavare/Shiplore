<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Output-encoding sinks (audit H5, M31, M32, M33, L3, L4, L5).
 *
 * Two recurring mistakes, both of which defeat escaping that IS present elsewhere:
 * trusting strip_tags() to remove attributes (it removes tags only), and handing
 * already-decoded text to an HTML sink.
 */
final class XssSinkTest extends CIUnitTestCase
{
    private function view(string $rel): string
    {
        return (string) file_get_contents(APPPATH . 'Views/' . $rel);
    }

    private function js(string $name): string
    {
        return (string) file_get_contents(FCPATH . 'assets/js/' . $name);
    }

    // ------------------------------------------------------------------ H5

    /**
     * strip_tags() removes disallowed TAGS but keeps ATTRIBUTES on the ones it allows,
     * so `<p onmouseover=...>` survives it. This is the only sanitiser applied to five
     * vendor rich-text fields before they are echoed unescaped on the PUBLIC product
     * page, and a vendor can rewrite a live product's content with no approval gate.
     */
    public function testRichTextStripsAttributesNotJustTags(): void
    {
        $src = $this->view('store/product.php');

        $this->assertStringContainsString(
            'preg_replace',
            $src,
            'strip_tags() alone keeps event-handler attributes on allowed tags',
        );
        // Plain substring, not a regex: the pattern being matched contains regex
        // delimiters of its own and quoting it twice is how this assertion breaks.
        $this->assertStringContainsString(
            '(p|br|ul|ol|li|strong|em|b|i|h4|h5|h6)',
            $src,
            'the attribute-stripping pass must run over the strip_tags() output',
        );
    }

    /** The sanitiser must actually strip an event handler off an allowed tag. */
    public function testRichTextSanitiserRemovesEventHandlers(): void
    {
        // Mirrors the two-step sanitiser in the view.
        $rich = static function (string $html): string {
            $safe = strip_tags($html, '<p><br><ul><ol><li><strong><em><b><i><h4><h5><h6>');

            return (string) preg_replace('#<\s*(/?)\s*(p|br|ul|ol|li|strong|em|b|i|h4|h5|h6)\b[^>]*>#i', '<$1$2>', $safe);
        };

        $this->assertSame('<p>hover</p><b>x</b>', $rich('<p onmouseover="alert(1)">hover</p><b onfocus=alert(2) tabindex=0>x</b>'));
        // Legitimate formatting must survive byte-for-byte.
        $this->assertSame('<p>a<strong>b</strong><em>c</em></p><ul><li>d</li></ul>', $rich('<p>a<strong>b</strong><em>c</em></p><ul><li>d</li></ul>'));
    }

    /**
     * JSON_UNESCAPED_SLASHES disables the default \/ escaping that makes </script>
     * impossible from inside a JSON string, so JSON_HEX_TAG must replace it.
     */
    public function testJsonLdCannotCloseItsOwnScriptElement(): void
    {
        $src = $this->view('store/product.php');

        $this->assertMatchesRegularExpression(
            '/json_encode\(\$ld,[^)]*JSON_HEX_TAG/',
            $src,
            'a product title containing </script> would close the JSON-LD element',
        );

        // Demonstrate the encoding actually neutralises it.
        $encoded = json_encode(['n' => 'a</script>b'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $this->assertStringNotContainsString('</script>', (string) $encoded);
        $this->assertSame('a</script>b', json_decode((string) $encoded, true)['n'], 'the value must still round-trip');
    }

    // ------------------------------------------------------------------ M31

    /** Vendor/customer free text must not become a live formula on an operator's machine. */
    public function testCsvExportsNeutraliseFormulaInjection(): void
    {
        foreach (['Admin/ProductController.php', 'Admin/OrderController.php'] as $rel) {
            $src = (string) file_get_contents(APPPATH . 'Controllers/' . $rel);

            $this->assertStringContainsString('csvCell', $src, $rel . ' writes untrusted text into a CSV unguarded');
        }
    }

    /** The guard must prefix exactly the characters a spreadsheet treats as a formula. */
    public function testCsvCellGuardCoversEveryFormulaLead(): void
    {
        $cell = static fn (mixed $v): string => (string) $v !== '' && str_contains("=+-@\t\r", ((string) $v)[0])
            ? "'" . (string) $v
            : (string) $v;

        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx"] as $payload) {
            $this->assertSame("'" . $payload, $cell($payload), 'formula lead not neutralised: ' . bin2hex($payload[0]));
        }
        // Ordinary values must be untouched, or the export changes for everyone.
        foreach (['Widget', '1250.00', 'ORD-1001', ''] as $ok) {
            $this->assertSame($ok, $cell($ok));
        }
    }

    // ------------------------------------------------------------------ M32 / M33

    /** SweetAlert2's `title` is an HTML sink; flash text arrives already decoded. */
    public function testToastUsesTextOnlyTitle(): void
    {
        $src = $this->js('ajax-forms.js');

        $this->assertStringContainsString('titleText:', $src, 'toast text must not be re-parsed as HTML');
        $this->assertDoesNotMatchRegularExpression(
            '/toast\(\)\.fire\(\{\s*icon:\s*icon,\s*title:\s*message/',
            $src,
            '`title` renders through the HTML parser — use titleText',
        );
    }

    /** Upload error strings embed the client-supplied filename; never innerHTML them. */
    public function testUploadErrorsAreNotConcatenatedIntoInnerHtml(): void
    {
        $src = $this->js('product-form.js');

        $this->assertDoesNotMatchRegularExpression(
            '/innerHTML\s*=\s*[\'"]<span class="text-danger">[\'"]\s*\+/',
            $src,
            'res.errors[] contains the raw uploaded filename',
        );
        $this->assertStringContainsString('errSpan.textContent', $src);
    }

    /** Both JS copies are served from different roots and must not drift. */
    public function testJsAssetsAreMirrored(): void
    {
        foreach (['ajax-forms.js', 'product-form.js'] as $name) {
            $this->assertSame(
                (string) file_get_contents(ROOTPATH . 'assets/js/' . $name),
                (string) file_get_contents(FCPATH . 'assets/js/' . $name),
                $name . ' has drifted between assets/ and public/assets/',
            );
        }
    }

    // ------------------------------------------------------------------ L3 / L4 / L5

    /** Dates echoed into a Content-Disposition filename must be validated. */
    public function testPosExportValidatesDatesBeforeTheFilename(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Controllers/Vendor/PosController.php');

        $this->assertStringContainsString(
            '/^\d{4}-\d{2}-\d{2}$/',
            $src,
            'unvalidated GET dates are interpolated into a quoted header parameter',
        );
    }

    /** json_encode into a single-quoted attribute needs JSON_HEX_APOS. */
    public function testBannerJsonEncodingEscapesQuotesAndTags(): void
    {
        $src = $this->view('admin/banners/index.php');

        $this->assertStringContainsString('JSON_HEX_APOS', $src, "an apostrophe in a title terminates the onclick attribute");
        $this->assertStringContainsString('JSON_HEX_TAG', $src, 'a name containing </script> closes the inline script');
    }

    /** The confirm prompt must carry the record name, in one well-formed attribute. */
    public function testMasterListConfirmPromptIsWellFormed(): void
    {
        $src = $this->view('admin/master/index.php');

        $this->assertStringContainsString('$confirmMsg', $src, 'the prompt is built from nested quotes and truncates');
        $this->assertStringNotContainsString('data-confirm="<?= $isActive', $src);
    }
}
