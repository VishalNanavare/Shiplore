<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Audit B, P1c — three stored-XSS sinks in the product form.
 *
 * `public/assets/js/product-form.js` built markup by string concatenation and
 * assigned it to innerHTML, interpolating values that are free text somebody typed
 * into a panel:
 *
 *   - the vendor-category dropdown interpolated `c.name`
 *   - the vendor-shop dropdown interpolated `s.name` (vendor-controlled)
 *   - the media-library tile interpolated `im.url` into a src="" attribute
 *
 * The last one is the sharpest: a stored value containing a double quote closes
 * the attribute, so `" onerror="…` executes. All three render inside the admin and
 * vendor panels, which is a vendor-to-admin path.
 *
 * These are source assertions — the repo has no JS test runner — but they are
 * evaluated against a comment-free view of the file so that the explanatory
 * comments the fix carries (which necessarily describe the vulnerable construct)
 * cannot satisfy or violate an assertion. Each was mutation-checked.
 */
final class ProductFormXssTest extends CIUnitTestCase
{
    /**
     * This file exists TWICE, served from two roots, and XssSinkTest already
     * requires the copies to stay byte-identical. Every check below therefore runs
     * against both: fixing only the served copy would leave the sink sitting in the
     * other one, one `cp` away from coming back.
     *
     * @return array<string, array{string}>
     */
    public static function copies(): array
    {
        return [
            'public/assets' => [FCPATH . 'assets/js/product-form.js'],
            'assets'        => [ROOTPATH . 'assets/js/product-form.js'],
        ];
    }

    /**
     * The file with whole-line comments removed.
     *
     * Deliberately conservative: it drops only lines that are ENTIRELY a comment.
     * testFixCommentsAreWholeLineOnly() below pins the assumption that no line
     * carrying one of the needles also carries a trailing comment, so this is
     * sufficient without needing to tokenize JS strings and regex literals.
     */
    private function code(string $path): string
    {
        $out = [];

        foreach (preg_split('/\R/', $this->raw($path)) as $line) {
            if (preg_match('~^\s*(//|/\*|\*)~', $line) === 1) {
                continue;
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    private function raw(string $path): string
    {
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** The <option> builder must produce a node, never a markup string. */
    #[\PHPUnit\Framework\Attributes\DataProvider('copies')]
    public function testOptionBuilderUsesTextContent(string $path): void
    {
        $code = $this->code($path);

        $this->assertMatchesRegularExpression(
            '/function optionEl\(value, label\)\s*\{.*?document\.createElement\(\'option\'\).*?o\.textContent\s*=/s',
            $code,
            'optionEl() must build the node with createElement and assign the label via textContent',
        );
        $this->assertStringNotContainsString(
            'o.innerHTML',
            $code,
            'optionEl() must never route a label through innerHTML',
        );
    }

    /** No <option> anywhere in the file may still be built by concatenation. */
    #[\PHPUnit\Framework\Attributes\DataProvider('copies')]
    public function testNoOptionIsBuiltByConcatenation(string $path): void
    {
        $code = $this->code($path);

        $this->assertStringNotContainsString(
            "'<option value=\"' +",
            $code,
            'an <option> is still being concatenated from a value — that is the XSS sink this fix removed',
        );
        $this->assertStringNotContainsString(
            "+ '</option>'",
            $code,
            'an <option> is still being closed by concatenation, so something is being interpolated into it',
        );
    }

    /** Both vendor-scoped dropdowns must go through the safe builder. */
    #[\PHPUnit\Framework\Attributes\DataProvider('copies')]
    public function testVendorCategoryAndShopDropdownsUseOptionEl(string $path): void
    {
        $code = $this->code($path);

        $this->assertMatchesRegularExpression(
            '/cat\.appendChild\(optionEl\(c\.id, c\.name\)\)/',
            $code,
            'the vendor-category dropdown must build options through optionEl()',
        );
        $this->assertMatchesRegularExpression(
            '/shop\.appendChild\(optionEl\(s\.id, s\.name \|\| \'\'\)\)/',
            $code,
            'the vendor-shop dropdown must build options through optionEl()',
        );

        // Rebuilding by appendChild only works if the old options were cleared first.
        $this->assertStringContainsString("cat.innerHTML = '';", $code, 'the category select must be emptied before repopulating');
        $this->assertStringContainsString("shop.innerHTML = '';", $code, 'the shop select must be emptied before repopulating');
    }

    /** The media-library thumbnail URL must be set as a property, not built into an attribute. */
    #[\PHPUnit\Framework\Attributes\DataProvider('copies')]
    public function testLibraryThumbnailUrlIsNotInterpolatedIntoMarkup(string $path): void
    {
        $code = $this->code($path);

        $this->assertStringNotContainsString(
            "'<img src=\"' + im.url",
            $code,
            'im.url is still concatenated into a src attribute — a stored filename containing a quote injects onerror=',
        );
        $this->assertMatchesRegularExpression(
            '/thumb\.src\s*=\s*im\.url;/',
            $code,
            'the thumbnail URL must be assigned as a property so it can never be parsed as markup',
        );
        $this->assertMatchesRegularExpression(
            '/tile\.insertBefore\(thumb, tile\.firstChild\)/',
            $code,
            'the thumbnail must still be inserted first inside the tile, preserving the original rendering order',
        );
    }

    /** No user-supplied value may be concatenated into any src attribute in this file. */
    #[\PHPUnit\Framework\Attributes\DataProvider('copies')]
    public function testNoSrcAttributeIsBuiltFromAVariable(string $path): void
    {
        $code = $this->code($path);

        preg_match_all('/\'<img src="\' \+ (\w+(?:\.\w+)*)/', $code, $m);

        // ev.target.result is a FileReader readAsDataURL result — base64 of the file's
        // BYTES, not its name, so it cannot contain a quote. It is the one permitted
        // remaining case; anything else is a regression.
        $this->assertSame(
            ['ev.target.result'],
            $m[1],
            'a new value is being concatenated into a src attribute: ' . implode(', ', $m[1]),
        );
    }

    /**
     * Pins the assumption code() relies on: none of the needles above appears on a
     * line that also carries a trailing comment, so dropping whole-line comments is
     * enough to guarantee the assertions measure code and not prose.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('copies')]
    public function testFixCommentsAreWholeLineOnly(string $path): void
    {
        $needles = ['optionEl(', 'thumb.src', 'insertBefore(thumb', "'<option value=\"'", "'<img src=\"'"];

        foreach (preg_split('/\R/', $this->raw($path)) as $n => $line) {
            if (preg_match('~^\s*(//|/\*|\*)~', $line) === 1) {
                continue;
            }
            foreach ($needles as $needle) {
                if (! str_contains($line, $needle)) {
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression(
                    '~//~',
                    $line,
                    'line ' . ($n + 1) . ' carries a needle AND a trailing comment; code() cannot separate them there',
                );
            }
        }
    }
}
